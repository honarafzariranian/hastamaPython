# Dump layout metrics of the server label HTML in headless Edge.
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

# Inject a reporter before </body>
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
      dpr:devicePixelRatio,
      bodyScroll:[b.scrollWidth,b.scrollHeight],
      htmlScroll:[h.scrollWidth,h.scrollHeight],
      page:p?{r:p.getBoundingClientRect().toJSON(), sw:p.scrollWidth}:null,
      lbl:r?{r:r.getBoundingClientRect().toJSON(), zoom:getComputedStyle(r).getPropertyValue('--lbl-zoom'), client:[r.clientWidth,r.clientHeight]}:null,
      content:c?{r:c.getBoundingClientRect().toJSON(), zoom:getComputedStyle(c).zoom, cw:c.clientWidth, sw:c.scrollWidth, ch:c.scrollHeight}:null
    };
    document.title='PROBE '+JSON.stringify(out);
  }, 500);
});
</script>
"""
html = html.replace("</body>", probe + "</body>")

html_path = Path(tempfile.gettempdir()) / "hastama-probe-label.html"
html_path.write_text(html, encoding="utf-8")

edge = tp.find_edge()
css_w = max(80, int(round(75 / 25.4 * 96)))
css_h = max(80, int(round(81 / 25.4 * 96)))
profile = Path(tempfile.gettempdir()) / "hastama-edge-label"
# dump-dom runs the page and prints final DOM (title will hold metrics)
cmd = [
    edge, "--headless", "--disable-gpu",
    "--no-first-run", "--no-default-browser-check",
    f"--user-data-dir={profile}",
    "--virtual-time-budget=4000",
    "--dump-dom",
    html_path.as_uri(),
]
env = os.environ.copy()
env["LANG"] = "fa-IR"
proc = subprocess.run(cmd, capture_output=True, text=True, timeout=40, encoding="utf-8", errors="replace", env=env)
dom = proc.stdout or ""
idx = dom.find("PROBE ")
if idx < 0:
    print("no probe in dom")
    print("stderr", (proc.stderr or "")[:500])
else:
    # title contains the JSON
    jstart = dom.find("{", idx)
    # title ends at </title>
    jend = dom.find("</title>", jstart)
    blob = dom[jstart:jend]
    print(blob)
    try:
        data = json.loads(blob)
        print("\n--- summary ---")
        print("inner", data["inner"], "dpr", data["dpr"])
        print("bodyScroll", data["bodyScroll"])
        print("page rect", data["page"]["r"] if data["page"] else None)
        print("lbl rect", data["lbl"]["r"] if data["lbl"] else None)
        print("lbl zoom", data["lbl"]["zoom"] if data["lbl"] else None)
        if data["content"]:
            print("content rect", data["content"]["r"])
            print("content zoom", data["content"]["zoom"], "cw", data["content"]["cw"], "sw", data["content"]["sw"])
    except Exception as e:
        print("parse fail", e)
