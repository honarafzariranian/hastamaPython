"""Temporary probe — every Persian .bat reference in a tracked file must exist.

Prints the mismatches as \\uXXXX escapes so an invisible ZWNJ mismatch is visible.
Delete this file after use.
"""
from __future__ import annotations

import pathlib
import re
import subprocess

BS = chr(92)
ROOT = pathlib.Path(".").resolve()

TOKEN = re.compile(r"[^\s`\"'()\[\]<>|,;:]*" + re.escape(".bat"))


def tracked_files() -> list[str]:
    out = subprocess.run(
        ["git", "ls-files"], capture_output=True, text=True, encoding="utf-8", check=True
    ).stdout
    return [line for line in out.splitlines() if line.strip()]


def looks_persian(text: str) -> bool:
    return any("\u0600" <= ch <= "\u06ff" for ch in text)


def main() -> int:
    bad: list[tuple[str, int, str]] = []
    total = 0
    for rel in tracked_files():
        path = ROOT / rel
        if not path.is_file():
            continue
        try:
            text = path.read_text("utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        for lineno, line in enumerate(text.splitlines(), 1):
            for match in TOKEN.finditer(line):
                token = match.group(0)
                if not looks_persian(token):
                    continue
                total += 1
                name = token.replace(BS, "/").split("/")[-1].rstrip("`").strip()
                if not (ROOT / "scripts" / name).exists() and not (ROOT / name).exists():
                    bad.append((rel, lineno, token))

    print(f"persian .bat references checked: {total}")
    print(f"unresolved: {len(bad)}")
    for rel, lineno, token in bad:
        print(f"  {rel}:{lineno}  {token.encode('unicode_escape').decode()}")
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
