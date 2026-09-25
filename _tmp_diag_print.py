import os
import struct
import sys
import tempfile

sys.stdout.reconfigure(encoding="utf-8")
sys.path.insert(0, r"E:\Hastama")

from app.services.ticket_print import (  # noqa: E402
    _render_png_with_edge,
    build_ticket_html,
    find_edge,
    load_label_settings,
)

cfg = load_label_settings()
print("cfg", cfg)

h = build_ticket_html(
    {"number": 12, "service": "جوابدهی"},
    {"name": "SECRET", "admission_number": "12345", "phone": "0912"},
    settings=cfg,
)
print("template_result", 'data-template="result"' in h)
print("patient_group_class", "lbl__record-group--patient" in h)
print("has_id_admission", 'id="maPreviewAdmissionRow"' in h)
print("has_data_field", "data-field" in h)
print("SECRET", "SECRET" in h)
print("minimal_style", 'style="grid-template-columns:1fr"' in h)

i = h.find('class="lbl__records"')
print("records_snippet", h[i : i + 420] if i >= 0 else "none")

print("edge", find_edge())
png = os.path.join(tempfile.gettempdir(), "hastama-size-check.png")
if os.path.isfile(png):
    os.unlink(png)
ok = _render_png_with_edge(h, png, 75, 81)
print("render_ok", ok)
if ok and os.path.isfile(png):
    with open(png, "rb") as f:
        data = f.read(33)
    w, hgt = struct.unpack(">II", data[16:24])
    print("png_wh", w, hgt)
    print("expected_203", round(75 / 25.4 * 203), round(81 / 25.4 * 203))
    print("expected_96", round(75 / 25.4 * 96), round(81 / 25.4 * 96))
