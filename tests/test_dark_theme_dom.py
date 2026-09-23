"""اجرای سوئیت رفتاری (jsdom) لایهٔ مشترک تم.

مثل tests/test_responsive_tables_dom.py، این تست فقط وقتی اجرا می‌شود که
Node.js و jsdom در دسترس باشند؛ در CI بدون Node به‌صورت خودکار skip می‌شود:

    cd tests/js && npm install jsdom

سوئیت رفتار واقعی theme.js را می‌سنجد: ست‌شدن هم‌زمان کلاس‌های
dark-mode/dark-theme روی <html> و <body>، ماندگاری انتخاب کاربر در
localStorage، پیش‌فرض روشن بدون انتخاب کاربر، کار کردن همهٔ کلیدهای تغییر تم
(از جمله آیدی‌های قدیمی) و ساخت دکمهٔ شناور در صفحات گزارش.
"""

from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
HARNESS = ROOT / "tests" / "js" / "theme.dom.test.js"


def _jsdom_paths() -> list[Path]:
    return [
        ROOT / "tests" / "js" / "node_modules",
        ROOT / "node_modules",
    ]


def test_theme_dom_suite() -> None:
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
        f"سوئیت DOM تم ناموفق بود:\n{result.stdout}\n{result.stderr}"
    )
    assert "✓" in result.stdout
