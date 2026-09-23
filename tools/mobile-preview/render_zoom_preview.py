"""Visual check: the queue label at the physical size, with the computed zoom.

Renders the same label markup twice — at zoom 1 (how it printed before) and at
the zoom the studio/print pipeline now applies — so the size difference is
visible at a glance.  Preview-only helper.
"""
from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from label_markup import extract_label_markup  # noqa: E402

OUT = HERE / "label-zoom-preview.html"
# 1.55 همان مقیاسی است که سرواژه/سند چاپ برای لیبل ۸۰×۸۰ حساب می‌کند
CASES = [(50, 55, None), (80, 80, 1), (80, 80, 1.55)]

HEAD = """<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="/static/css/vazir.css">
<link rel="stylesheet" href="/static/css/label-print.css">
<style>
  body { margin: 0; padding: 14px; background: #0f172a; color: #cbd5e1; font: 13px system-ui; }
  .row { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; }
  .case { border: 1px solid #334155; border-radius: 10px; padding: 10px; background: #111827; }
  .case h3 { margin: 0 0 8px; font-size: 12px; color: #93c5fd; direction: ltr; }
  .paper { background: #fff; }
</style>
</head>
<body>
<div class="row">
"""

TAIL = """</div>
</body>
</html>
"""


def case_html(markup: str, w: int, h: int, zoom) -> str:
    zoom_attr = '' if zoom is None else ';--lbl-zoom:%s' % zoom
    styled = markup.replace(
        '<div class="lbl"',
        '<div class="lbl lbl--print" style="--lbl-mm-w:%d;--lbl-mm-h:%d%s"' % (w, h, zoom_attr),
        1,
    )
    label = "%d x %d mm" % (w, h) if zoom is None else "%d x %d mm @ zoom %s" % (w, h, zoom)
    return '<div class="case"><h3>%s</h3><div class="paper">%s</div></div>\n' % (label, styled)


def build() -> str:
    markup = extract_label_markup()
    return HEAD + "\n".join(case_html(markup, *c) for c in CASES) + TAIL


if __name__ == "__main__":
    OUT.write_text(build(), encoding="utf-8")
    print("wrote %s" % OUT)
