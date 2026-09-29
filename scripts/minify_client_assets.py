"""Local, deterministic minification tool for the client assets.

Two jobs:

* ``--check``  verify (offline, no network, no external tool) that every
  JavaScript / CSS file in ``app/static`` survives :mod:`app.services.client_assets`
  unchanged in meaning — the JavaScript must still parse under ``node --check``
  when Node is available — and print the before/after byte totals.
* ``--write``  emit the minified copies into a directory (default
  ``build/client-assets``) so a release can be inspected or archived without
  touching the development sources.

The same code path serves production responses at runtime (see
``_ClientAssetMinifierMiddleware`` in ``app/main.py``), so a green ``--check`` is
exactly the guarantee that production delivery relies on.

Usage::

    .venv\\Scripts\\python.exe scripts\\minify_client_assets.py --check
    .venv\\Scripts\\python.exe scripts\\minify_client_assets.py --write
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.services import client_assets  # noqa: E402  (path set above)

STATIC = ROOT / "app" / "static"


def _node_available() -> bool:
    try:
        subprocess.run(["node", "--version"], capture_output=True, check=True, timeout=30)
        return True
    except (OSError, subprocess.SubprocessError):
        return False


def _node_check(text: str) -> str | None:
    """Return Node's error output when the JavaScript does not parse."""
    with tempfile.TemporaryDirectory() as tmp:
        target = Path(tmp) / "snippet.js"
        target.write_text(text, encoding="utf-8")
        proc = subprocess.run(
            ["node", "--check", str(target)], capture_output=True, timeout=120
        )
    if proc.returncode == 0:
        return None
    return proc.stderr.decode("utf-8", "ignore").strip().splitlines()[:3]


def _files() -> list[Path]:
    result: list[Path] = []
    for pattern in ("*.js", "*.css"):
        result.extend(sorted(STATIC.rglob(pattern)))
    return result


def run(*, write: bool, out_dir: Path | None) -> int:
    node = _node_available()
    print(f"node available: {node}   (JS parse verification)")
    print(f"{'file':<52}{'before':>10}{'after':>10}{'saved':>9}{'%':>7}  status")
    total_before = total_after = 0
    failures: list[str] = []
    for path in _files():
        rel = path.relative_to(STATIC).as_posix()
        raw = path.read_text("utf-8", "ignore")
        before = len(raw.encode("utf-8"))
        try:
            if path.suffix == ".js":
                out = client_assets.minify_js(raw)
            else:
                out = client_assets.minify_css(raw)
        except client_assets.MinifyError as exc:
            failures.append(f"{rel}: {exc}")
            print(f"{rel:<52}{before:>10}{'—':>10}{'—':>9}{'—':>7}  REFUSED ({exc})")
            continue
        after = len(out.encode("utf-8"))
        total_before += before
        total_after += after
        status = "ok"
        if path.suffix == ".js" and node:
            error = _node_check(out)
            if error:
                failures.append(f"{rel}: node --check failed: {error}")
                status = "NODE-CHECK-FAILED"
        pct = (100 * (before - after) / before) if before else 0
        print(f"{rel:<52}{before:>10}{after:>10}{before - after:>9}{pct:>6.1f}%  {status}")
        if write and out_dir is not None:
            target = out_dir / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(out, encoding="utf-8")

    print("-" * 92)
    saved = total_before - total_after
    pct = (100 * saved / total_before) if total_before else 0
    print(f"{'TOTAL':<52}{total_before:>10}{total_after:>10}{saved:>9}{pct:>6.1f}%")
    if failures:
        print(f"\nFAILURES ({len(failures)}):")
        for item in failures:
            print("  " + item)
        return 1
    print("\nAll files minified with an unchanged token stream"
          + (" and passed node --check." if node else " (Node unavailable: parse check skipped)."))
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="verify, write nothing")
    group.add_argument("--write", action="store_true", help="write minified copies")
    parser.add_argument("--out", default="build/client-assets", help="output directory for --write")
    args = parser.parse_args()
    os.chdir(ROOT)
    return run(write=args.write, out_dir=(ROOT / args.out) if args.write else None)


if __name__ == "__main__":
    raise SystemExit(main())
