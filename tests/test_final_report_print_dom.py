"""اجرای سوئیت DOM (jsdom) لایهٔ چاپ گزارش نهایی.

این تست فقط وقتی اجرا می‌شود که Node.js و jsdom در دسترس باشند
(محیط توسعه؛ در CI بدون Node به‌صورت خودکار skip می‌شود):

    cd tests/js && npm install jsdom

سوئیت رفتار واقعی ``final-report-print.js`` را می‌سنجد: ساخت فرم چاپ در
``#printReport``، سقف ۳۱ رکورد در هر برگ A4، تقسیم گزارش‌های بلندتر به
حداقل تعداد برگ، تکرار سربرگ، قرارگرفتن خلاصه/جداول جانبی/امضاها روی برگ
آخر، بودجهٔ ارتفاع هر برگ، و سیم‌کشی دکمه‌های چاپ و دریافت PDF.
"""
from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
HARNESS = ROOT / "tests" / "js" / "final-report-print.dom.test.js"


def _node() -> str | None:
    return shutil.which("node")


def _jsdom_paths() -> list[Path]:
    return [
        ROOT / "tests" / "js" / "node_modules",
        ROOT / "node_modules",
    ]


def test_final_report_print_dom_suite():
    node = _node()
    if not node:
        pytest.skip("Node.js در دسترس نیست (سوئیت DOM فقط محیط توسعه است)")
    if not HARNESS.exists():
        pytest.fail(f"فایل سوئیت پیدا نشد: {HARNESS}")
    node_path = next((p for p in _jsdom_paths() if (p / "jsdom").exists()), None)
    if not node_path:
        pytest.skip("jsdom نصب نشده است — برای اجرا: cd tests/js && npm install jsdom")

    env = dict(os.environ)
    env["NODE_PATH"] = str(node_path)
    result = subprocess.run(
        [node, str(HARNESS)],
        capture_output=True,
        text=True,
        timeout=180,
        env=env,
        cwd=str(ROOT),
    )
    tail = "\n".join((result.stdout or "").splitlines()[-8:])
    assert result.returncode == 0, f"DOM suite failed:\n{result.stdout}\n{result.stderr}\n--- tail ---\n{tail}"
