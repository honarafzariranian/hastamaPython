"""اجرای سوئیت رفتاری (jsdom) لایهٔ حرکتی داشبورد بازطراحی‌شده.

مثل tests/test_dark_theme_dom.py، این تست فقط وقتی اجرا می‌شود که Node.js و
jsdom در دسترس باشند؛ در CI بدون Node به‌صورت خودکار skip می‌شود:

    cd tests/js && npm install jsdom

سوئیت رفتار واقعی dashboard-modern.js را می‌سنجد: شمارش اعداد با ارقام فارسی،
ارتفاع میله‌های نمودار، پر شدن حلقه‌های پیشرفت، نوارهای سهم و توزیع، فارسی‌سازی
نشانگرهای رتبه و افزودن کلاس انیمیشن ورود.
"""

from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
HARNESS = ROOT / "tests" / "js" / "dashboard-modern.dom.test.js"


def _jsdom_paths() -> list[Path]:
    return [
        ROOT / "tests" / "js" / "node_modules",
        ROOT / "node_modules",
    ]


def test_dashboard_dom_suite() -> None:
    node = shutil.which("node")
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
    assert result.returncode == 0, (
        f"سوئیت DOM داشبورد ناموفق بود:\n{result.stdout}\n{result.stderr}"
    )
    assert "✓" in result.stdout
