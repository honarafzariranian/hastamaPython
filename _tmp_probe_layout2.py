# Probe layout with the EXACT flags used by _render_png_with_edge.
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, r"E:\Hastama")
from app.services import ticket_print as tp

ticket = {"service": "پذیرش", "number": 1, "persian_number": "۱"}
patient = {"name": "آزمون", "admission_number_persian": "۹۹۹"}
html = tp.build_ticket_html(ticket, patient)

probe = r"""
<script>
window.addEventListener('load', function(){
  setTimeout(function(){
    var r=document.querySelector('.lbl');
    var p=document.querySelector('.lbl-page');
    var c=document.querySelector('.lbl__content');
    var b=document.body, h=document.documentElement;
    var out={
      inner:[innerWidth,innerHeight],
      outer:[outerWidth,outerHeight],
      dpr:devicePixelRatio,
      bodyScroll:[b.scrollWidth,b.scrollHeight],
      htmlScroll:[h.scrollWidth,h.scrollHeight],
      page:p?{x:p.getBoundingClientRect().x,w:p.getBoundingClientRect().width}:null,
      lbl:r?{x:r.getBoundingClientRect().x,w:r.getBoundingClientRect().width,zoom:getComputedStyle(r).getPropertyValue('--lbl-zoom')}:null,
      content:c?{x:c.getBoundingClientRect().x,w:c.getBoundingClientRect().width,zoom:getComputedStyle(c).zoom,cw:c.clientWidth,sw:c.scrollWidth}:null
    };
    document.title='PROBE '+JSON.stringify(out);
  }, 800);
});
</script>
"""
html = html.replace("</body>", probe + "</body>")
html_path = Path(tempfile.gettempdir()) / "hastama-probe-label2.html"
html_path.write_text(html, encoding="utf-8")

edge = tp.find_edge()
css_w = max(80, int(round(75 / 25.4 * 96)))
css_h = max(80, int(round(81 / 25.4 * 96)))
scale = max(1.0, 203.0 / 96.0)
profile = Path(tempfile.gettempdir()) / "hastama-edge-label"

for extra in (
    [f"--window-size={css_w},{css_h}", f"--force-device-scale-factor={scale:.6f}"],
    [f"--window-size={css_w},{css_h}"],
    [f"--force-device-scale-factor={scale:.6f}"],
):
    cmd = [
        edge, "--headless", "--disable-gpu",
        "--no-first-run", "--no-default-browser-check",
        f"--user-data-dir={profile}",
        "--virtual-time-budget=4000",
        *extra,
        "--dump-dom",
        html_path.as_uri(),
    ]
    print("=== flags", extra)
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=40, encoding="utf-8", errors="replace")
    dom = proc.stdout or ""
    idx = dom.find("PROBE ")
    if idx < 0:
        print(" no probe", (proc.stderr or "")[:200])
        continue
    jstart = dom.find("{", idx)
    jend = dom.find("</title>", jstart)
    blob = dom[jstart:jend]
    try:
        d = json.loads(blob)
        print(" inner", d["inner"], "outer", d["outer"], "dpr", d["dpr"])
        print(" bodyScroll", d["bodyScroll"])
        print(" page", d["page"])
        print(" lbl", d["lbl"])
        print(" content", d["content"])
    except Exception as e:
        print(" parse fail", e, blob[:200])
