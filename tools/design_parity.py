"""Design-parity harness.

The migrated stylesheets are byte-identical to app/static/css, so a migrated
page renders like the legacy page when — and only when — it uses the same
class names on the same kind of elements.  This script extracts the class
vocabulary of every Python template and of the Vue page(s) that replaced it,
and reports what each side has that the other lacks.

Three classes of "missing" match are not gaps, and each is proved rather than
assumed:

  * `composed` — the legacy writes `x--shield`, Vue builds `` `x--${modifier}` ``;
  * `adjudicated` — the class is applied at runtime (a `<body>` class, for
    instance) or is a Jinja artifact, and ADJUDICATED says where to look;
  * everything else is a real gap in the migrated markup.

Usage:
  python tools/design_parity.py --summary          # one line per page pair
  python tools/design_parity.py rules.html         # full detail for a pair
  python tools/design_parity.py -v                 # include "extra in vue"
  python tools/design_parity.py --composed         # show composed matches too
"""

from __future__ import annotations

import os
import re
import sys
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TPL = os.path.join(ROOT, "app", "templates")
VUE = os.path.join(ROOT, "laravel", "resources", "js")

# Python template -> the Vue file(s) that carry its markup.
PAIRS: dict[str, list[str]] = {
    "login.html": ["pages/auth/LoginPage.vue"],
    "register.html": ["pages/public/RegisterPage.vue"],
    "rules.html": ["pages/public/RulesPage.vue"],
    "training.html": ["pages/public/TrainingPage.vue"],
    "training-lesson.html": ["pages/public/TrainingLessonPage.vue"],
    "offline.html": ["pages/public/OfflinePage.vue"],
    "vpn-warning.html": ["pages/public/IranOnlyPage.vue"],
    "user-panel.html": ["layouts/UserPanelLayout.vue"]
    + [
        f"pages/user/{n}"
        for n in (
            "DashboardPage.vue",
            "ProfilePage.vue",
            "LeavePage.vue",
            "OvertimePage.vue",
            "HourlyPassPage.vue",
            "TicketPage.vue",
            "NotificationsPage.vue",
            "FinalReportPage.vue",
        )
    ],
    "admin.html": ["layouts/AdminLayout.vue"]
    + [
        f"pages/admin/{n}"
        for n in (
            "DashboardPage.vue",
            "UsersPage.vue",
            "LeavePage.vue",
            "HourlyPassPage.vue",
            "OvertimePage.vue",
            "PayrollPage.vue",
            "ReportsPage.vue",
            "ShiftsPage.vue",
        )
    ],
    "master-admin.html": ["layouts/ControlCentreLayout.vue"]
    + [
        f"pages/control/{n}"
        for n in (
            "DashboardPage.vue",
            "UsersPage.vue",
            "SessionsPage.vue",
            "SecurityPage.vue",
            "ActionsPage.vue",
            "ErrorsPage.vue",
            "HealthPage.vue",
            "PasswordResetsPage.vue",
            "SettingsPage.vue",
            "SubscriptionsPage.vue",
        )
    ],
    "call-management.html": ["pages/call/CallManagementPage.vue"],
    "call-display.html": ["pages/call/CallDisplayPage.vue"],
    "ticket-kiosk.html": ["pages/call/TicketKioskPage.vue"],
    "label_print_document.html": ["pages/call/TicketPrintPage.vue"],
}

# Cases where the class really is reproduced, and how — each one verified by
# hand, so the reported gap count stays honest.
ADJUDICATED: dict[tuple[str, str], str] = {
    ("training.html", "tr-page"): "applied to <body> by the component",
    ("training.html", "cat_id"): "Jinja loop variable caught by the class scan",
    ("training-lesson.html", "tr-page"): "applied to <body> by the component",
    ("ticket-kiosk.html", "portrait"): "applied to <body> by the component",
    ("offline.html", "lan"): "the routed page is the served document",
    ("offline.html", "lan__title"): "the routed page is the served document",
    ("offline.html", "lan__url"): "the routed page is the served document",
}

CLASS_ATTR = re.compile(r'(?::?@?class)\s*=\s*"([^"]*)"')
CLASS_ATTR_SINGLE = re.compile(r"(?::?@?class)\s*=\s*'([^']*)'")
CLASS_BINDING = re.compile(r":class\s*=\s*\"([^\"]*)\"")
# class names inside a Vue binding appear as 'literal', "literal" or
# `template ${expr} literal` — backticks included.
LITERAL = re.compile(r"'([^']*)'|\"([^\"]*)\"|`([^`]*)`")
TOKEN = re.compile(r"[A-Za-z_][A-Za-z0-9_\-]*")
CLASS_TOKEN = re.compile(r"^[A-Za-z_][A-Za-z0-9_\-]*(-[A-Za-z0-9_\-]+)*$")
BLOCK_BOUNDARY = re.compile(r"<(?:script|style)\b", re.I)
SCRIPT_OR_STYLE = re.compile(r"<(script|style)\b[^>]*>.*?</\1>", re.I | re.S)
QUOTED = re.compile(r"['\"`]([^'\"`]*)['\"`]")


def sfc_template(source: str) -> str:
    """Return the SFC's <template> block.

    A plain `</style>` strip cannot be used: the components legitimately mention
    `<style>` inside their own documentation comments, which would swallow the
    rest of the file.  The template block runs from its opening tag to the next
    top-level `<script>`/`<style>` tag, which is how every SFC here is ordered.
    """
    start = source.find("<template>")
    if start < 0:
        return ""
    start += len("<template>")
    boundary = BLOCK_BOUNDARY.search(source, start)
    return source[start : boundary.start() if boundary else len(source)]


def legacy_markup(template_text: str) -> str:
    """Strip the inline <script>/<style> blocks from a Jinja template.

    The Python pages carry their own JavaScript and CSS inline, and the class
    names that appear there are behaviour hooks (`classList.add('open')`) or CSS
    rules rather than markup.  Both sides are reduced to markup before the
    vocabulary is read, so the comparison is markup to markup.
    """
    return SCRIPT_OR_STYLE.sub("", template_text)


def classes_of_source(text: str) -> tuple[Counter, Counter]:
    """Return (classes from class attributes, classes inside Vue bindings)."""
    static: Counter = Counter()
    bound: Counter = Counter()

    for pattern in (CLASS_ATTR, CLASS_ATTR_SINGLE):
        for match in pattern.finditer(text):
            for token in TOKEN.findall(match.group(1)):
                static[token] += 1

    for match in CLASS_BINDING.finditer(text):
        for literal in LITERAL.findall(match.group(1)):
            value = literal[0] or literal[1] or literal[2]
            for token in TOKEN.findall(value):
                if CLASS_TOKEN.match(token):
                    bound[token] += 1

    return static, bound


def vue_sources(paths: list[str]) -> tuple[Counter, Counter, str]:
    static: Counter = Counter()
    bound: Counter = Counter()
    raw = ""
    for relative in paths:
        full = os.path.join(VUE, *relative.split("/"))
        if not os.path.exists(full):
            print(f"  !! missing vue file: {relative}", file=sys.stderr)
            continue
        with open(full, encoding="utf-8") as handle:
            source = handle.read()
        raw += source
        s, b = classes_of_source(sfc_template(source))
        static.update(s)
        bound.update(b)
    return static, bound, raw


def composed_in_vue(name: str, vue_vocab: set[str], raw: str) -> str | None:
    """Return the prefix when `name` is composed at runtime by the Vue source.

    `rl-overview-card__icon--shield` is built as
    `` `rl-overview-card__icon--${card.modifier}` ``: the prefix is part of the
    Vue class vocabulary and the suffix appears as a plain literal next to it.
    """
    for index, char in enumerate(name):
        if char != "-":
            continue
        prefix, suffix = name[: index + 1], name[index + 1 :]
        if not suffix or prefix not in vue_vocab:
            continue
        if any(suffix in value for value in QUOTED.findall(raw)):
            return prefix
    return None


def report(pairs: list[str], verbose: bool, summary: bool, show_all: bool) -> int:
    problems = 0
    for template, vues in PAIRS.items():
        if pairs and template not in pairs:
            continue
        with open(os.path.join(TPL, template), encoding="utf-8") as handle:
            legacy = legacy_markup(handle.read())

        legacy_static, legacy_bound = classes_of_source(legacy)
        vue_static, vue_bound, raw = vue_sources(vues)

        legacy_all = legacy_static + legacy_bound
        vue_all = vue_static + vue_bound
        vue_vocab = set(vue_all)

        extra = sorted(set(vue_all) - set(legacy_all))

        missing: list[str] = []
        composed: list[str] = []
        settled: list[str] = []
        for name in sorted(set(legacy_all) - vue_vocab):
            note = ADJUDICATED.get((template, name))
            if note:
                settled.append(f"{name}  — {note}")
            elif composed_in_vue(name, vue_vocab, raw):
                composed.append(f"{name}  (built from {composed_in_vue(name, vue_vocab, raw)} + …)")
            else:
                missing.append(name)

        problems += len(missing)

        if summary:
            print(
                f"{template:30s} legacy={len(legacy_all):4d}  vue={len(vue_all):4d}  "
                f"missing={len(missing):4d}  composed={len(composed):3d}  "
                f"adjudicated={len(settled):2d}  extra={len(extra):4d}"
            )
            continue

        print(f"\n{'=' * 78}\n{template}  ->  {len(vues)} vue file(s)\n{'=' * 78}")
        print(f"legacy classes: {len(legacy_all)}   vue classes: {len(vue_all)}")
        if missing:
            print(f"\n-- MISSING in vue ({len(missing)}):")
            for name in missing:
                print(f"     {name}  (legacy x{legacy_all[name]})")
        if composed and show_all:
            print(f"\n-- composed at runtime ({len(composed)}):")
            for name in composed:
                print(f"     {name}")
        if settled and show_all:
            print(f"\n-- adjudicated as equivalent ({len(settled)}):")
            for name in settled:
                print(f"     {name}")
        if extra and verbose:
            print(f"\n-- extra in vue ({len(extra)}):")
            for name in extra:
                print(f"     {name}  (vue x{vue_all[name]})")
    return problems


if __name__ == "__main__":
    names = [a for a in sys.argv[1:] if not a.startswith("-")]
    count = report(
        names,
        verbose="-v" in sys.argv,
        summary="--summary" in sys.argv,
        show_all="--all" in sys.argv or "--composed" in sys.argv,
    )
    print(f"\ntotal missing classes: {count}")
