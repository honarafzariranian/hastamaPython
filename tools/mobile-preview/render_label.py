"""Render the label-studio (label printer) section to a standalone preview page.

Preview-only helper: extracts the ``label-printer`` section of
``app/templates/master-admin.html``, wraps it with the real stylesheets, and
stubs ``fetch`` so ``GET /master-admin/api/printers`` returns a live payload
produced by ``app/services/printer.py``.  This lets us look at the label studio
(desktop + mobile) without a master-admin login.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from app.services.printer import describe_printers  # noqa: E402

TEMPLATE = ROOT / "app" / "templates" / "master-admin.html"
OUT = Path(__file__).resolve().parent / "label-preview.html"

CSS = [
    "vazir.css",
    "admin.css",
    "master-admin.css",
    "toast.css",
    "label-print.css",
    "dark-theme.css",
]

START = '<section class="ma-label-studio"'
NEXT_SECTION = "{% elif active_section == 'system-settings' %}"


def label_section(html: str) -> str:
    start = html.index(START)
    stop = html.index(NEXT_SECTION, start)
    end = html.rindex("</section>", start, stop) + len("</section>")
    return html[start:end]


def build(payload: dict) -> str:
    section = label_section(TEMPLATE.read_text(encoding="utf-8"))
    links = "\n".join(f'    <link rel="stylesheet" href="/static/css/{name}">' for name in CSS)
    return f"""<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>پیش‌نمایش استودیو لیبل</title>
{links}
</head>
<body data-section="label-printer">
    <main class="ma-dashboard-main" id="top">
    <div class="ma-view">
{section}
    </div>
    </main>
    <script>
    // ── Mock API: /printers answers with a live spooler snapshot ──
    window.__PRINTER_PAYLOAD__ = {json.dumps(payload, ensure_ascii=False)};
    window.fetch = function (url) {{
      var u = String(url);
      if (u.indexOf('/printers') !== -1) {{
        return Promise.resolve({{ ok: true, json: function () {{
          return Promise.resolve({{ success: true, data: window.__PRINTER_PAYLOAD__ }});
        }} }});
      }}
      return Promise.resolve({{ ok: false, json: function () {{
        return Promise.resolve({{ detail: 'preview: ' + u }});
      }} }});
    }};
    </script>
    <script src="/static/js/csrf-bootstrap.js"></script>
    <script src="/static/js/toast.js"></script>
    <script src="/static/js/master-admin.js"></script>
</body>
</html>
"""


if __name__ == "__main__":
    OUT.write_text(build(describe_printers(force=True)), encoding="utf-8")
    print(f"wrote {OUT}")
