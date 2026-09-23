"""Extract the queue-label markup out of master-admin.html (preview-only helper)."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = ROOT / "app" / "templates" / "master-admin.html"

LABEL_START = '<div class="lbl" id="maLabelPreview"'
STUDIO_START = '<section class="ma-label-studio"'
NEXT_SECTION = "{% elif active_section == 'system-settings' %}"


def _template() -> str:
    return TEMPLATE.read_text(encoding="utf-8")


def extract_label_markup() -> str:
    """Return the ``.lbl`` preview block with balanced tags."""
    html = _template()
    start = html.index(LABEL_START)
    depth = 0
    index = start
    while True:
        open_at = html.find("<div", index)
        close_at = html.find("</div>", index)
        if close_at == -1:
            raise ValueError("unbalanced <div> in the label markup")
        if open_at != -1 and open_at < close_at:
            depth += 1
            index = open_at + 4
        else:
            depth -= 1
            index = close_at + 6
            if depth == 0:
                return html[start:index]


def extract_studio_section() -> str:
    """Return the whole label-studio section of the template."""
    html = _template()
    start = html.index(STUDIO_START)
    stop = html.index(NEXT_SECTION, start)
    end = html.rindex("</section>", start, stop) + len("</section>")
    return html[start:end]
