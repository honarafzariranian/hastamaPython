"""Tiny static server for the mobile preview page.

Serves the repo root so that /static/* hits app/static/*, and '/' renders the
preview HTML produced by render.py. Preview-only; never part of the app.
"""
from __future__ import annotations

import http.server
import os
import socketserver
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PREVIEW = Path(__file__).resolve().parent / "admin-preview.html"
PORT = int(os.environ.get("PREVIEW_PORT", "8123"))


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def translate_path(self, path: str) -> str:
        clean = path.split("?", 1)[0].split("#", 1)[0]
        if clean in ("/", "/index.html", "/admin"):
            return str(PREVIEW)
        if clean.startswith("/static/"):
            target = ROOT / "app" / clean.lstrip("/")
            if target.is_file():
                return str(target)
        if clean.startswith("/training/"):
            return str(PREVIEW)
        return super().translate_path(path)

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):  # quieter logs
        pass


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True


if __name__ == "__main__":
    with Server(("127.0.0.1", PORT), Handler) as httpd:
        print(f"preview server on http://127.0.0.1:{PORT}/")
        httpd.serve_forever()
