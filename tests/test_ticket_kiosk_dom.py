"""Regression tests for kiosk dismissal and RTL keyboard layout (requires jsdom)."""
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]


def test_ticket_kiosk_dom():
    node = shutil.which("node")
    if not node:
        pytest.skip("Node.js is not installed")
    if not any((base / "node_modules/jsdom").exists() for base in (ROOT, ROOT / "tests/js")):
        pytest.skip("Install jsdom with: npm install --prefix tests/js")
    result = subprocess.run(
        [node, str(ROOT / "tests/js/ticket-kiosk.dom.test.js")],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=30,
    )
    assert result.returncode == 0, result.stdout + result.stderr
