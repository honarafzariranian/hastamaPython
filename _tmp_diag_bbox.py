# Diagnose label PNG content bbox (where non-white pixels are).
import sys
from pathlib import Path

sys.path.insert(0, r"E:\Hastama")
from app.services import ticket_print as tp

ticket = {"service": "پذیرش", "number": 1, "persian_number": "۱"}
patient = {"name": "آزمون تستی", "admission_number_persian": "۹۹۹"}
html = tp.build_ticket_html(ticket, patient)
png = Path.home() / "AppData" / "Local" / "tmp" / "hastama-diag-label.png"
ok = tp._render_png_with_edge(html, str(png), 75, 81)
print("render_ok", ok, "path", png, "size", png.stat().st_size if png.exists() else 0)

try:
    from PIL import Image
except ImportError:
    print("no PIL")
    sys.exit(0)

im = Image.open(png).convert("L")
w, h = im.size
print("png_wh", w, h)
# bbox of pixels darker than 250 (content ink)
mask = im.point(lambda p: 255 if p < 250 else 0)
bbox = mask.getbbox()
print("ink_bbox", bbox)
if bbox:
    left, top, right, bottom = bbox
    print("margins_px LTRB", left, top, w - right, h - bottom)
    # physical mm at 203 dpi
    px_mm = 203 / 25.4
    print("margins_mm LTRB",
          round(left / px_mm, 2), round(top / px_mm, 2),
          round((w - right) / px_mm, 2), round((h - bottom) / px_mm, 2))
