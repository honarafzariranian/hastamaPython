"""Render a metrics page for the queue label.

The label design in ``label-print.css`` uses absolute pixel sizes tuned for a
small (≈50 x 55 mm) label, so ``--lbl-zoom`` scales the whole design proportionally
and a bigger label gets bigger boxes and text. This page measures, for a set of
physical label sizes, the *natural* content size and the largest zoom that still
fits — the data used to calibrate the formula in ``master-admin.js``.

Preview-only helper; never part of the app.
"""
from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from label_markup import extract_label_markup  # noqa: E402

OUT = HERE / "label-metrics.html"
SIZES = [(50, 55), (55, 50), (50, 50), (60, 60), (70, 70), (80, 80), (80, 50), (40, 40)]

HEAD = """<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="/static/css/vazir.css">
<link rel="stylesheet" href="/static/css/label-print.css">
<style>
  body { margin: 0; padding: 12px; background: #0f172a; color: #e2e8f0; font: 13px monospace; direction: ltr; }
  .box { display: inline-block; background: #fff; margin: 6px; vertical-align: top; }
  .tag { display: block; color: #93c5fd; font-weight: 700; margin: 4px 0; }
  #report { background: #111827; padding: 8px; white-space: pre-wrap; }
</style>
</head>
<body>
<div id="report">measuring…</div>
"""

SCRIPT = """
<script>
window.zoomSupported = !!(window.CSS && CSS.supports && CSS.supports('zoom', '1'));
window.measure = function (zoom) {
  var out = {};
  document.querySelectorAll('.lbl[data-case]').forEach(function (lbl) {
    var wmm = parseFloat(lbl.style.getPropertyValue('--lbl-mm-w'));
    var hmm = parseFloat(lbl.style.getPropertyValue('--lbl-mm-h'));
    var c = lbl.querySelector('.lbl__content');
    if (zoom != null) lbl.style.setProperty('--lbl-zoom', String(zoom));
    c.style.transform = 'none';
    var prevH = c.style.height, prevFlex = c.style.flex;
    c.style.height = 'auto'; c.style.flex = 'none';
    var natW = c.scrollWidth, natH = c.scrollHeight;
    c.style.height = prevH; c.style.flex = prevFlex;
    var W = wmm * 3.7795, H = hmm * 3.7795;
    out[wmm + 'x' + hmm] = {
      box: [Math.round(W), Math.round(H)],
      natural: [natW, natH],
      zoomFit: [+(W / natW).toFixed(2), +(H / natH).toFixed(2)],
      maxZoom: +Math.min(W / natW, H / natH).toFixed(2)
    };
  });
  return out;
};
window.measureFonts = function () {
  var lbl = document.querySelector('.lbl[data-case]');
  var pick = ['.lbl__lab-name', '.lbl__slogan', '.lbl__queue-number', '.lbl__record-label', '.lbl__record-value', '.lbl__message', '.lbl__brand-text strong'];
  var out = {};
  pick.forEach(function (sel) {
    var el = lbl.querySelector(sel);
    if (el) out[sel] = getComputedStyle(el).fontSize;
  });
  return out;
};
window.addEventListener('load', function () {
  setTimeout(function () {
    window.__metrics = { zoomSupported: window.zoomSupported, zoom1: window.measure(1) };
  }, 700);
});
</script>
</body>
</html>
"""


def case_html(markup: str, w: int, h: int) -> str:
    styled = markup.replace(
        '<div class="lbl"',
        '<div class="lbl lbl--print" data-case="%dx%d" style="--lbl-mm-w:%d;--lbl-mm-h:%d"' % (w, h, w, h),
        1,
    )
    return '<div class="case"><span class="tag">%d x %d mm</span><div class="box">%s</div></div>' % (w, h, styled)


def build() -> str:
    markup = extract_label_markup()
    cases = "\n".join(case_html(markup, w, h) for w, h in SIZES)
    return HEAD + cases + "\n" + SCRIPT


if __name__ == "__main__":
    OUT.write_text(build(), encoding="utf-8")
    print("wrote %s" % OUT)
