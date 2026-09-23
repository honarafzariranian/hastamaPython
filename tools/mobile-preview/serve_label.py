"""Static server for the label-studio preview page (preview-only)."""
from __future__ import annotations

import os
import socketserver
from http.server import SimpleHTTPRequestHandler
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PREVIEW = Path(__file__).resolve().parent / "label-preview.html"
PORT = int(os.environ.get("PREVIEW_PORT", "8124"))


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def translate_path(self, path: str) -> str:
        clean = path.split("?", 1)[0].split("#", 1)[0]
        if clean in ("/", "/index.html"):
            return str(PREVIEW)
        if clean.startswith("/static/"):
            target = ROOT / "app" / clean.lstrip("/")
            if target.is_file():
                return str(target)
        return super().translate_path(path)

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        pass


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True


if __name__ == "__main__":
    with Server(("127.0.0.1", PORT), Handler) as httpd:
        print(f"label preview on http://127.0.0.1:{PORT}/", flush=True)
        httpd.serve_forever()
