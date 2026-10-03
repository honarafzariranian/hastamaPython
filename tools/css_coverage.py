"""Stylesheet-coverage check.

The class-vocabulary harness can only see markup.  A migrated page can use
every legacy class name and still render unstyled, because the page's own
inline <style> block (or a stylesheet it linked) was never carried across.
This script answers the second half of the question:

  1. every `<link rel=stylesheet>` the template loaded — does the ported copy
     exist under laravel/resources/css/legacy/, and is it imported by app.css?
  2. the template's inline `<style>` block — are its class selectors present in
     the ported CSS or in the Vue component's own <style> block?

Usage:  python tools/css_coverage.py [template.html ...]
"""

from __future__ import annotations

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TPL = os.path.join(ROOT, "app", "templates")
CSS = os.path.join(ROOT, "laravel", "resources", "css")
VUE = os.path.join(ROOT, "laravel", "resources", "js")

PAIRS = {
    "login.html": ["pages/auth/LoginPage.vue"],
    "register.html": ["pages/public/RegisterPage.vue"],
    "rules.html": ["pages/public/RulesPage.vue"],
    "training.html": ["pages/public/TrainingPage.vue"],
    "training-lesson.html": ["pages/public/TrainingLessonPage.vue"],
    "offline.html": ["pages/public/OfflinePage.vue"],
    "vpn-warning.html": ["pages/public/IranOnlyPage.vue"],
    "user-panel.html": ["layouts/UserPanelLayout.vue"],
    "admin.html": ["layouts/AdminLayout.vue"],
    "master-admin.html": ["layouts/ControlCentreLayout.vue"],
    "call-management.html": ["pages/call/CallManagementPage.vue"],
    "call-display.html": ["pages/call/CallDisplayPage.vue"],
    "ticket-kiosk.html": ["pages/call/TicketKioskPage.vue"],
    "leave_report_page.html": ["pages/admin/LeavePage.vue"],
    "hourlypass_Report_page.html": ["pages/admin/HourlyPassPage.vue"],
    "overtime_report_page.html": ["pages/admin/OvertimePage.vue"],
    "payroll_report_page.html": ["pages/admin/PayrollPage.vue"],
    "final_report_page.html": ["pages/user/FinalReportPage.vue"],
    "label_print_document.html": ["pages/call/TicketPrintPage.vue"],
}

LINK = re.compile(r"<link[^>]+rel=[\"']stylesheet[\"'][^>]*>", re.I)
HREF = re.compile(r"href=[\"']([^\"']+)[\"']", re.I)
STATIC_FILE = re.compile(r"path=['\"]([^'\"]+)['\"]")
STYLE_BLOCK = re.compile(r"<style[^>]*>(.*?)</style>", re.I | re.S)
COMMENT = re.compile(r"/\*.*?\*/", re.S)
CSS_RULE = re.compile(r"([^{}]+)\{", re.S)
CLASS_IN_SELECTOR = re.compile(r"\.(-?[A-Za-z_][A-Za-z0-9_\-]*)")


def ported_css_text() -> str:
    text = ""
    for base, _dirs, files in os.walk(CSS):
        for name in files:
            if name.endswith(".css"):
                with open(os.path.join(base, name), encoding="utf-8", errors="replace") as handle:
                    text += handle.read()
    return text


def vue_style_text(paths: list[str]) -> str:
    text = ""
    for relative in paths:
        full = os.path.join(VUE, *relative.split("/"))
        if not os.path.exists(full):
            continue
        with open(full, encoding="utf-8", errors="replace") as handle:
            source = handle.read()
        for block in STYLE_BLOCK.findall(source):
            text += block
    return text


def stylesheet_name(tag: str) -> str | None:
    """The stylesheet a `<link>` loads, Jinja `url_for` included.

    Most templates write `href="{{ url_for('static', path='css/x.css') }}"`, so
    the static path is looked for in the whole tag first; a plain `href` is the
    fallback (the call pages use it).
    """
    static = STATIC_FILE.search(tag)
    if static:
        return static.group(1).rsplit("/", 1)[-1].split("?")[0]
    href = HREF.search(tag)
    if not href:
        return None
    return href.group(1).rsplit("/", 1)[-1].split("?")[0]


def main(names: list[str]) -> int:
    ported = ported_css_text()
    with open(os.path.join(CSS, "app.css"), encoding="utf-8") as handle:
        imported = handle.read()
    problems = 0

    for template, vues in PAIRS.items():
        if names and template not in names:
            continue
        with open(os.path.join(TPL, template), encoding="utf-8") as handle:
            source = handle.read()

        linked = [name for name in (stylesheet_name(tag) for tag in LINK.findall(source)) if name]

        inline = "\n".join(STYLE_BLOCK.findall(source))
        inline = COMMENT.sub("", inline)
        rules = [selector.strip() for selector in CSS_RULE.findall(inline)]
        inline_classes = set()
        for selector in rules:
            for name in CLASS_IN_SELECTOR.findall(selector):
                inline_classes.add(name)

        own_styles = vue_style_text(vues)
        available = ported + own_styles

        not_imported = [name for name in linked if name not in imported]
        missing_inline = sorted(name for name in inline_classes if f".{name}" not in available)

        print(f"\n{template}: linked={len(linked)} inline_rules={len(rules)} "
              f"inline_classes={len(inline_classes)}")
        if not_imported:
            print(f"    NOT IMPORTED: {', '.join(not_imported)}")
            problems += len(not_imported)
        if inline_classes:
            covered = len(inline_classes) - len(missing_inline)
            print(f"    inline selectors covered: {covered}/{len(inline_classes)}")
            if missing_inline:
                problems += len(missing_inline)
                preview = ", ".join(missing_inline[:12])
                more = "" if len(missing_inline) <= 12 else f" … (+{len(missing_inline) - 12})"
                print(f"    NOT COVERED: {preview}{more}")
    return problems


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("-")]
    count = main(args)
    print(f"\nstylesheet gaps: {count}")
