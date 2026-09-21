"""سرور پیش‌نمایش آفلاین پنل مدیریت (برای بازبینی طراحی داشبورد).

خروجی `preview/render.py` را سرو می‌کند تا داشبورد بازطراحی‌شده را بدون
SQL Server و بدون اجرای اپ اصلی، در مرورگر واقعی ببینید:

    python preview/render.py      # ساخت preview/out/index.html با دادهٔ نمونه
    python preview/serve.py       # http://localhost:8099

نکات:
    • مسیرهای `/admin/...` روی همان index.html نگاشت می‌شوند تا منطق SPA
      (navTo/toggleBox) داشبورد را در حالت فعال نشان دهد.
    • `/static/...` از سیم‌لینک `preview/out/static` → `app/static` خوانده می‌شود؛
      پس هر تغییری در CSS/JS بلافاصله در پیش‌نمایش دیده می‌شود (فقط رفرش کنید).
    • این فایل هیچ ربطی به اجرای پروداکشن ندارد؛ فقط ابزار توسعه است.
"""

from __future__ import annotations

import argparse
import functools
import http.server
import socketserver
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(__file__).resolve().parent / "out"


class PreviewHandler(http.server.SimpleHTTPRequestHandler):
    """مسیرهای SPA را روی index.html نگاشت می‌کند."""

    def do_GET(self):  # noqa: N802 (نام متد در http.server)
        path = self.path.split("?", 1)[0]
        if path in ("", "/") or path.startswith("/admin"):
            self.path = "/index.html"
        super().do_GET()

    def log_message(self, fmt, *args):  # کمتر شلوغ
        if "404" in (fmt % args):
            super().log_message(fmt, *args)


class ReusableServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main() -> None:
    parser = argparse.ArgumentParser(description="پیش‌نمایش آفلاین پنل مدیریت")
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=8099)
    args = parser.parse_args()

    if not (OUT / "index.html").exists():
        raise SystemExit("preview/out/index.html پیدا نشد — اول `python preview/render.py` را اجرا کنید.")

    handler = functools.partial(PreviewHandler, directory=str(OUT))
    with ReusableServer((args.host, args.port), handler) as httpd:
        print(f"پیش‌نمایش داشبورد: http://{args.host}:{args.port}/admin/dashboard")
        print("برای توقف: Ctrl+C")
        httpd.serve_forever()


if __name__ == "__main__":
    main()
