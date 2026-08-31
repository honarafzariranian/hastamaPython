#!/usr/bin/env python3
"""
Audio Generation Script — edge-tts

Generates Persian female voice audio files for reception numbers 1-2000.
Uses edge-tts (Microsoft Edge TTS) — free, no API key required.
Voice: fa-IR-DilaraNeural (Persian female)

Requirements:
    pip install edge-tts

Usage:
    python generate_sample_call_audio.py
    python generate_sample_call_audio.py --numbers 1 12 125 512 1000 1500 2000
    python generate_sample_call_audio.py --overwrite
    python generate_sample_call_audio.py verify
"""
from __future__ import annotations

import argparse
import asyncio
import os
import sys
import time
from pathlib import Path

try:
    import edge_tts
except ImportError:
    print("ERROR: edge-tts is not installed.")
    print("Run: pip install edge-tts")
    sys.exit(1)

# Fix Windows console encoding for Persian text
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# ---------------------------------------------------------------------------
# Persian number words
# ---------------------------------------------------------------------------
ONES = [
    "", "\u06cc\u06a9", "\u062f\u0648", "\u0633\u0647", "\u0686\u0647\u0627\u0631",
    "\u067e\u0646\u062c", "\u0634\u0634", "\u0647\u0641\u062a", "\u0647\u0634\u062a",
    "\u0646\u0647",
    "\u062f\u0647", "\u06cc\u0627\u0632\u062f\u0647", "\u062f\u0648\u0627\u0632\u062f\u0647",
    "\u0633\u06cc\u0632\u062f\u0647", "\u0686\u0647\u0627\u0631\u062f\u0647",
    "\u067e\u0627\u0646\u0632\u062f\u0647", "\u0634\u0627\u0646\u0632\u062f\u0647",
    "\u0647\u0641\u062f\u0647", "\u0647\u062c\u062f\u0647", "\u0646\u0648\u0632\u062f\u0647",
]
TENS = [
    "", "", "\u0628\u06cc\u0633\u062a", "\u0633\u06cc", "\u0686\u0647\u0644",
    "\u067e\u0646\u062c\u0627\u0647", "\u0634\u0635\u062a", "\u0647\u0641\u062a\u0627\u062f",
    "\u0647\u0634\u062a\u0627\u062f", "\u0646\u0648\u062f",
]
HUNDREDS = [
    "", "\u0635\u062f", "\u062f\u0648\u06cc\u0633\u062a", "\u0633\u06cc\u0635\u062f",
    "\u0686\u0647\u0627\u0631\u0635\u062f", "\u067e\u0627\u0646\u0635\u062f",
    "\u0634\u0634\u0635\u062f", "\u0647\u0641\u062a\u0635\u062f", "\u0647\u0634\u062a\u0635\u062f",
    "\u0646\u0647\u0635\u062f",
]


def number_to_persian(n: int) -> str:
    """Convert an integer 1-2000 to natural Persian words."""
    if n < 1 or n > 2000:
        raise ValueError(f"Number {n} out of range 1-2000")

    _dohazar = "\u062f\u0648 \u0647\u0632\u0627\u0631"
    _hezar = "\u0647\u0632\u0627\u0631"
    _va = "\u0648"

    if n == 2000:
        return _dohazar

    parts: list[str] = []
    has_prev = False

    if n >= 1000:
        parts.append(_hezar)
        n %= 1000
        if n == 0:
            return _hezar
        has_prev = True

    if n >= 100:
        if has_prev:
            parts.append(_va)
        h = n // 100
        parts.append(HUNDREDS[h])
        n %= 100
        has_prev = n > 0

    if n >= 20:
        if has_prev:
            parts.append(_va)
        t = n // 10
        parts.append(TENS[t])
        n %= 10
        has_prev = n > 0

    if 1 <= n <= 19:
        if has_prev:
            parts.append(_va)
        parts.append(ONES[n])

    return " ".join(parts)


# ---------------------------------------------------------------------------
# Sentence builder
# ---------------------------------------------------------------------------
_SUFFIX = ", \u0644\u0637\u0641\u0627\u064b \u0628\u0647 \u0628\u062e\u0634 \u0646\u0645\u0648\u0646\u0647\u065c\u06af\u06cc\u0631\u06cc \u0645\u0631\u0627\u062c\u0639\u0647 \u06a9\u0646\u06cc\u062f."


def build_sentence(n: int) -> str:
    """Build the complete Persian announcement sentence."""
    persian_num = number_to_persian(n)
    return f"\u0634\u0645\u0627\u0631\u0647 {persian_num}{_SUFFIX}"


# ---------------------------------------------------------------------------
# edge-tts generation
# ---------------------------------------------------------------------------
VOICE = "fa-IR-DilaraNeural"


async def generate_one(text: str, output_path: Path) -> bool:
    """Generate a single MP3 file using edge-tts."""
    try:
        communicate = edge_tts.Communicate(text, VOICE)
        await communicate.save(str(output_path))
        return output_path.exists() and output_path.stat().st_size > 100
    except Exception as e:
        print(f"  Error: {e}")
        return False


async def generate_batch(numbers: list[int], out_dir: Path, overwrite: bool = False, delay: float = 0.1):
    """Generate audio files for a list of numbers."""
    success = 0
    skip = 0
    error = 0
    total = len(numbers)

    for i, n in enumerate(numbers):
        filename = f"{n:04d}.mp3"
        filepath = out_dir / filename

        if filepath.exists() and not overwrite:
            skip += 1
            continue

        sentence = build_sentence(n)
        print(f"[{i + 1}/{total}] {filename}: {sentence}")

        ok = await generate_one(sentence, filepath)
        if ok:
            size_kb = filepath.stat().st_size / 1024
            print(f"  OK ({size_kb:.1f} KB)")
            success += 1
        else:
            error += 1

        # Small delay to be respectful to the service
        if i < total - 1:
            await asyncio.sleep(delay)

    return success, skip, error


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------
def main():
    parser = argparse.ArgumentParser(
        description="Generate Persian TTS audio files using edge-tts"
    )
    parser.add_argument("--start", type=int, default=1)
    parser.add_argument("--end", type=int, default=2000)
    parser.add_argument("--numbers", type=int, nargs="+")
    parser.add_argument("--overwrite", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--output-dir", type=str, default=None)
    parser.add_argument("--delay", type=float, default=0.1)
    args = parser.parse_args()

    # Output directory
    if args.output_dir:
        out_dir = Path(args.output_dir)
    else:
        script_dir = Path(__file__).resolve().parent
        project_root = script_dir.parent.parent
        out_dir = project_root / "app" / "static" / "audio" / "sample_call" / "fa-IR-DilaraNeural"

    out_dir.mkdir(parents=True, exist_ok=True)
    print(f"Output: {out_dir}")
    print(f"Voice:  {VOICE}")

    # Numbers
    if args.numbers:
        numbers = sorted(set(args.numbers))
    else:
        numbers = list(range(args.start, args.end + 1))
    numbers = [n for n in numbers if 1 <= n <= 2000]

    existing = sum(1 for n in numbers if (out_dir / f"{n:04d}.mp3").exists())
    to_gen = len(numbers) - existing if not args.overwrite else len(numbers)
    print(f"Total: {len(numbers)}, Existing: {existing}, To generate: {to_gen}")

    if args.dry_run:
        print("\n--- DRY RUN ---")
        for n in numbers[:20]:
            print(f"  {n:04d}.mp3 -> {build_sentence(n)}")
        if len(numbers) > 20:
            print(f"  ... and {len(numbers) - 20} more")
        return

    start_time = time.time()
    success, skip, error = asyncio.run(generate_batch(numbers, out_dir, args.overwrite, args.delay))
    elapsed = time.time() - start_time

    print(f"\n{'=' * 50}")
    print(f"Done in {elapsed:.1f}s")
    print(f"  Success: {success}")
    print(f"  Skipped: {skip}")
    print(f"  Errors:  {error}")
    print(f"  Output:  {out_dir}")


def verify(output_dir: Path | None = None) -> None:
    """Verify audio files."""
    if output_dir is None:
        script_dir = Path(__file__).resolve().parent
        project_root = script_dir.parent.parent
        output_dir = project_root / "app" / "static" / "audio" / "sample_call" / "fa-IR-DilaraNeural"

    print(f"Verifying: {output_dir}")
    missing = []
    invalid = []
    valid = 0

    for n in range(1, 2001):
        filepath = output_dir / f"{n:04d}.mp3"
        if not filepath.exists():
            missing.append(n)
            continue
        if filepath.stat().st_size < 100:
            invalid.append((n, filepath.stat().st_size))
            continue
        valid += 1

    print(f"  Valid:   {valid}")
    print(f"  Missing: {len(missing)}")
    print(f"  Invalid: {len(invalid)}")
    if missing:
        print(f"  Missing numbers (first 20): {missing[:20]}")
        if len(missing) > 20:
            print(f"    ... and {len(missing) - 20} more")


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "verify":
        verify()
    else:
        main()
