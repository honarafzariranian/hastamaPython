# Probe the print document geometry in headless Edge (same flags as the server print).
# Reports where each label block sits relative to the physical label box, so we can
# see what the label height clips (top and/or bottom).
import json
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from app.services import ticket_print as tp

SEL = [
    ".lbl",
    ".lbl__accent",
    ".lbl__content",
    ".lbl__header",
    ".lbl__queue",
    ".lbl__number-box",
    ".lbl__records",
    ".lbl__message",
    ".lbl__footer",
]

PROBE = """
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
    var out = { zoom: null, content: null, blocks: {} };
    var label = document.querySelector('.lbl');
    var content = document.querySelector('.lbl__content');
    if (label) out.zoom = getComputedStyle(label).getPropertyValue('--lbl-zoom').trim();
    if (content) {
      out.content = {
        clientH: content.clientHeight,
        scrollH: content.scrollHeight,
        rectH: content.getBoundingClientRect().height,
        zoomProp: getComputedStyle(content).zoom,
        usedZoom: content.getBoundingClientRect().height / content.clientHeight,
      };
    }
    var base = label ? label.getBoundingClientRect() : null;
    %s.forEach(function (sel) {
      var el = document.querySelector(sel);
      if (!el) { out.blocks[sel] = null; return; }
      var r = el.getBoundingClientRect();
      out.blocks[sel] = {
        top: Math.round((r.top - (base ? base.top : 0)) * 100) / 100,
        bottom: Math.round((r.bottom - (base ? base.bottom : 0)) * 100) / 100,
        h: Math.round(r.height * 100) / 100,
      };
    });
    // manual re-run of the fit math with the CURRENT (post-font) layout
    if (label && content) {
      var keepW = label.style.getPropertyValue('--lbl-zoom');
      var keepH = content.style.height, keepF = content.style.flex;
      label.style.setProperty('--lbl-zoom', '1');
      content.style.height = 'auto';
      content.style.flex = 'none';
      var natural = content.scrollHeight;
      out.manual = {
        natural: natural,
        lblW: label.clientWidth,
        lblH: label.clientHeight,
        heightRoom: Math.round(label.clientHeight / Math.max(1, natural) * 1000) / 1000,
        widthRoom: Math.round(label.clientWidth / 188.98 * 1000) / 1000,
        fontsReady: document.fonts ? document.fonts.status : 'n/a',
      };
      content.style.height = keepH;
      content.style.flex = keepF;
      label.style.setProperty('--lbl-zoom', keepW);
    }
    out.hasHL = typeof window.HastamaLabel;
    out.zoomBeforeManualFit = label ? getComputedStyle(label).getPropertyValue('--lbl-zoom').trim() : null;
    try {
      if (window.HastamaLabel) window.HastamaLabel.fitDocument();
    } catch (e) {
      out.fitError = String(e && e.message || e);
    }
    out.zoomAfterManualFit = label ? getComputedStyle(label).getPropertyValue('--lbl-zoom').trim() : null;
    document.title = 'GEODUMP ' + JSON.stringify(out);
  }, 900);
});
</script>
""" % json.dumps(SEL)


def probe(ticket, patient, settings=None, label_js=None):
    html = tp.build_ticket_html(ticket, patient, settings=settings)
    if label_js is not None:
        # replace the inlined engine with a stub that pins the zoom
        html = html.replace(
            "<script>if (window.HastamaLabel) { HastamaLabel.fitDocument(); }</script>",
            "",
        )
    html = html.replace("</body>", PROBE + "</body>")

    path = Path(tempfile.gettempdir()) / "hastama-geo-probe.html"
    path.write_text(html, encoding="utf-8")

    width_mm = (settings or {}).get("width_mm", 75)
    height_mm = (settings or {}).get("height_mm", 81)
    css_w = max(80, int(round(width_mm / 25.4 * 96)))
    css_h = max(80, int(round(height_mm / 25.4 * 96)))
    edge = tp.find_edge()
    cmd = [
        edge, "--headless", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
        "--user-data-dir=" + str(Path(tempfile.gettempdir()) / "hastama-edge-label"),
        "--force-device-scale-factor=%.6f" % (203 / 96),
        "--window-size=%d,%d" % (css_w, css_h),
        "--virtual-time-budget=4000",
        "--dump-dom",
        path.as_uri(),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=60,
                          encoding="utf-8", errors="replace")
    dom = proc.stdout or ""
    i = dom.find("GEODUMP ")
    if i < 0:
        print("no probe output; rc=", proc.returncode, (proc.stderr or "")[:300])
        return None
    blob = dom[i + len("GEODUMP "):]
    blob = blob[: blob.find("</title>")]
    data = json.loads(blob)
    print("zoom", data["zoom"], "content", data["content"])
    for sel, box in data["blocks"].items():
        print("   %-18s %s" % (sel, box))
    return data


if __name__ == "__main__":
    ticket = {"service": "پذیرش", "number": 12, "persian_number": "۱۲"}
    patient = {
        "name": "تقی",
        "age": "26",
        "national_id": "0890519684",
        "phone": "09332905840",
        "insurance_tracking": "12345",
        "insurance_base": "تأمین اجتماعی",
        "insurance_extra": "آسیا",
        "admission_number_persian": "۱۲۳۴۵",
    }
    for w, h in ((75, 81), (76, 80), (50, 55)):
        print("=== %sx%s mm (تعداد blocks) ===" % (w, h))
        probe(ticket, patient, settings={
            "width_mm": w, "height_mm": h, "template": "queue",
            "rotate": False, "show_name": True, "show_time": True, "show_hint": True,
        })
