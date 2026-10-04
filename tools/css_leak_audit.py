"""Cross-sheet CSS leak audit.

The Laravel bundle imports *every* legacy stylesheet into one global app.css,
while the Python app gave each page only the sheets it linked.  A page therefore
renders correctly under Python and wrongly under Laravel whenever a sheet it
never loaded declares a property on a class/id the page *does* use.

This is not hypothetical: `admin.css` declares `.modal { display: none }` for the
admin delete/checkout dialogs, and `/user_panel` also has `.modal` cards — so the
ported leave/over-tall/pass dialogs rendered 0x0 until the rule was scoped.

This script reports the remaining candidates:

  1. which sheets a template linked (Python truth);
  2. which ported sheets are therefore unloaded *for that page*;
  3. every rule in those unloaded sheets whose selector can still match an
     element in the template (all of the selector's class/id tokens appear in the
     template markup or in the JS that runs on it);
  4. whether the affected element is *also* styled by a loaded sheet, which is
     the signal that a leak is a real conflict rather than dead weight.

Matching is deliberately conservative (every token must be present), so the
report under-reports rather than inventing matches.  Rules only reachable inside
a media query are tagged with that query.

Usage:  python tools/css_leak_audit.py [template.html ...] [--file admin.css]
        python tools/css_leak_audit.py --summary
"""

from __future__ import annotations

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TPL = os.path.join(ROOT, "app", "templates")
CSS = os.path.join(ROOT, "laravel", "resources", "css", "legacy")
JS = os.path.join(ROOT, "laravel", "resources", "js")

COMMENT = re.compile(r"/\*.*?\*/", re.S)
LINK = re.compile(r"<link[^>]+rel=[\"']stylesheet[\"'][^>]*>", re.I)
SCRIPT = re.compile(r"<script[^>]*src=[\"']([^\"']+)[\"']", re.I)
STATIC_FILE = re.compile(r"path=['\"]([^'\"']+)['\"]")
HREF = re.compile(r"href=[\"']([^\"']+)[\"']", re.I)

CLASS_ATTR = re.compile(r"""class=["']([^"']*)["']""")
ID_ATTR = re.compile(r"""id=["']([^"']*)["']""")
TAG = re.compile(r"<([a-zA-Z][a-zA-Z0-9]*)")

TOKEN_CLASS = re.compile(r"\.(-?[A-Za-z_][A-Za-z0-9_\-]*)")
TOKEN_ID = re.compile(r"#(-?[A-Za-z_][A-Za-z0-9_\-]*)")

GENERIC_TAGS = {
    "html", "body", "div", "span", "p", "a", "img", "button", "input", "select",
    "option", "textarea", "label", "form", "table", "thead", "tbody", "tr", "td",
    "th", "ul", "ol", "li", "i", "b", "strong", "em", "small", "h1", "h2", "h3",
    "h4", "h5", "h6", "br", "hr", "nav", "aside", "main", "header", "footer",
    "section", "article", "svg", "path", "canvas", "video", "audio", "iframe",
}

# The template files this harness knows how to read.
PAIRS = {
    "user-panel.html": ["js/user-panel-script.js", "js/ticketing.js",
                        "js/internal-automation.js", "js/notification-system.js",
                        "js/theme.js", "js/hastama-ux.js", "js/responsive-tables.js",
                        "js/offline-guard.js"],
    "admin.html": ["js/admin.js", "js/admin-mobile.js", "js/ticketing.js",
                   "js/internal-automation.js", "js/notification-system.js",
                   "js/theme.js", "js/hastama-ux.js", "js/responsive-tables.js"],
    "master-admin.html": ["js/master-admin.js", "js/theme.js", "js/hastama-ux.js"],
    "call-management.html": ["js/call-system.js", "js/theme.js"],
    "call-display.html": ["js/call-display.js", "js/theme.js"],
    "login.html": ["js/theme.js"],
    "rules.html": ["js/theme.js"],
    "training.html": ["js/theme.js"],
}


# --------------------------------------------------------------------------- #
# CSS parsing
# --------------------------------------------------------------------------- #

def strip_comments(text: str) -> str:
    return COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def parse_rules(text: str, name: str) -> list[dict]:
    """Every style rule, with its media/at-rule context and source line."""
    text = strip_comments(text)
    rules: list[dict] = []
    stack: list[str] = []          # enclosing at-rules / selectors
    buffer = ""
    line = 1
    decl_line = line

    for char in text:
        if char == "\n":
            line += 1
        if char == "{":
            stack.append(buffer.strip())
            buffer = ""
            continue
        if char == "}":
            if stack:
                stack.pop()
            buffer = ""
            continue
        if char == ";" and stack:
            decl = buffer.strip()
            buffer = ""
            if decl:
                selector = stack[-1]
                context = " ".join(stack[:-1])
                if selector and not selector.startswith("@"):
                    rules.append({
                        "file": name,
                        "selector": selector,
                        "decl": decl,
                        "context": context,
                        "line": decl_line,
                    })
            continue
        if not buffer.strip():
            decl_line = line
        buffer += char
    return rules


def iter_sheets() -> dict[str, str]:
    out = {}
    for name in sorted(os.listdir(CSS)):
        if name.endswith(".css"):
            with open(os.path.join(CSS, name), encoding="utf-8", errors="replace") as handle:
                out[name] = handle.read()
    return out


# --------------------------------------------------------------------------- #
# Page vocabulary
# --------------------------------------------------------------------------- #

def stylesheet_names(source: str) -> set[str]:
    names = set()
    for tag in LINK.findall(source):
        static = STATIC_FILE.search(tag)
        href = static.group(1) if static else (m.group(1) if (m := HREF.search(tag)) else None)
        if href:
            names.add(href.rsplit("/", 1)[-1].split("?")[0])
    return names


def script_names(source: str) -> list[str]:
    return [s.rsplit("/", 1)[-1].split("?")[0] for s in SCRIPT.findall(source)]


def page_tokens(source: str, scripts: list[str]) -> tuple[set[str], set[str], set[str]]:
    """(classes, ids, tags) used by the page, markup plus the JS that runs on it."""
    classes: set[str] = set()
    ids: set[str] = set()
    tags: set[str] = set()

    for attr in CLASS_ATTR.findall(source):
        classes.update(attr.split())
    for attr in ID_ATTR.findall(source):
        ids.update(attr.split())
    tags.update(t.lower() for t in TAG.findall(source))

    # JavaScript adds/removes classes and looks elements up by class/id; scan the
    # scripts the page actually loads so dynamic states are not missed.
    for script in scripts:
        path = os.path.join(ROOT, "app", "static", *script.split("/"))
        if not os.path.exists(path):
            continue
        with open(path, encoding="utf-8", errors="replace") as handle:
            js = handle.read()
        classes.update(re.findall(r"classList\.(?:add|remove|toggle|contains)\(\s*['\"]([^'\"]+)", js))
        classes.update(re.findall(r"class\s*=\s*['\"]([^'\"]+)", js))
        ids.update(re.findall(r"getElementById\(\s*['\"]([^'\"]+)", js))
        ids.update(re.findall(r"[#]([A-Za-z_][\w\-]*)\s*(?:['\"`])", js))
    classes.discard("")
    ids.discard("")
    return classes, ids, tags


# --------------------------------------------------------------------------- #
# Matching
# --------------------------------------------------------------------------- #

def selector_parts(selector: str) -> list[str]:
    """Split a selector list on top-level commas."""
    parts, depth, buffer = [], 0, ""
    for char in selector:
        if char in "([":
            depth += 1
        elif char in ")]":
            depth -= 1
        if char == "," and depth == 0:
            parts.append(buffer)
            buffer = ""
            continue
        buffer += char
    parts.append(buffer)
    return [p.strip() for p in parts if p.strip()]


def selector_tokens(part: str) -> tuple[set[str], set[str], set[str]]:
    """Class/id/tag tokens of one compound selector chain."""
    cleaned = re.sub(r":(?:not|is|where)\([^()]*\)", "", part)
    cleaned = re.sub(r"\[[^\]]*\]", "", cleaned)
    cleaned = re.sub(r"::?[a-zA-Z-]+(\([^()]*\))?", "", cleaned)
    classes = set(TOKEN_CLASS.findall(cleaned))
    ids = set(TOKEN_ID.findall(cleaned))
    tags = {
        t.lower()
        for t in re.findall(r"(?:^|[\s>+~])([a-zA-Z][a-zA-Z0-9]*)", cleaned)
        if t.lower() in GENERIC_TAGS
    }
    return classes, ids, tags


def is_guarded(part: str) -> bool:
    """Whether a selector carries a page guard (`... body:has(...) ...`).

    `tools/scope_legacy_sheet.py` rewrites a leaking selector so it only applies
    on the pages that actually linked the sheet; such a selector can no longer
    match the protected page, so it must not be reported again.
    """
    return ":has(" in part


def matches_page(part: str, classes: set[str], ids: set[str], tags: set[str]) -> bool:
    if is_guarded(part):
        return False
    s_classes, s_ids, s_tags = selector_tokens(part)
    anchors = len(s_classes) + len(s_ids)
    if anchors == 0:
        return False                     # tag-only rules are shared vocabulary
    if not s_classes <= classes:
        return False
    if not s_ids <= ids:
        return False
    if not s_tags <= tags:
        return False
    return True


def load_declarations(sheets: dict[str, str]) -> dict[str, set[str]]:
    """class/id token -> properties declared anywhere (for conflict reporting)."""
    out: dict[str, set[str]] = {}
    for name, text in sheets.items():
        for rule in parse_rules(text, name):
            prop = rule["decl"].split(":", 1)[0].strip().lower()
            if not prop or prop.startswith("-") and prop.endswith("-"):
                continue
            for part in selector_parts(rule["selector"]):
                s_classes, s_ids, _ = selector_tokens(part)
                for token in s_classes | s_ids:
                    out.setdefault(token, set()).add(prop)
    return out


# --------------------------------------------------------------------------- #

def report(template: str, only: str | None, sheets: dict[str, str]) -> int:
    path = os.path.join(TPL, template)
    if not os.path.exists(path):
        print(f"!! missing template {template}")
        return 0
    with open(path, encoding="utf-8", errors="replace") as handle:
        source = handle.read()

    loaded = stylesheet_names(source)
    scripts = script_names(source) or PAIRS.get(template, [])
    classes, ids, tags = page_tokens(source, scripts)

    # The page's *own* sheets define the intended value; a leaked declaration
    # only matters against those, so the property index is built from them.
    own = {name: text for name, text in sheets.items() if name in loaded}
    prop_index = load_declarations(own)

    unloaded = {name: text for name, text in sheets.items() if name not in loaded}
    if only:
        unloaded = {name: text for name, text in unloaded.items() if name == only}

    findings = []
    per_sheet: dict[str, int] = {}
    guarded = 0
    for name, text in unloaded.items():
        for rule in parse_rules(text, name):
            if all(is_guarded(p) for p in selector_parts(rule["selector"])):
                guarded += 1
            for part in selector_parts(rule["selector"]):
                if not matches_page(part, classes, ids, tags):
                    continue
                prop = rule["decl"].split(":", 1)[0].strip().lower()
                s_classes, s_ids, _ = selector_tokens(part)
                token_conflict = any(prop in prop_index.get(t, set()) for t in s_classes | s_ids)
                findings.append({
                    "part": part,
                    "conflict": token_conflict,
                    **rule,
                })
                per_sheet[name] = per_sheet.get(name, 0) + 1
                break

    print(f"\n=== {template}")
    print(f"    linked sheets : {', '.join(sorted(loaded))}")
    print(f"    vocabulary    : {len(classes)} classes, {len(ids)} ids")

    if "--sheets" in sys.argv:
        for name, count in sorted(per_sheet.items(), key=lambda kv: -kv[1]):
            print(f"    {count:5d}  {name}")
        return sum(per_sheet.values())

    findings.sort(key=lambda f: (f["file"], f["line"]))
    conflicts = [f for f in findings if f["conflict"]]
    print(f"    unloaded sheets with matches ({len(per_sheet)}): "
          + ", ".join(f"{n}={c}" for n, c in sorted(per_sheet.items(), key=lambda kv: -kv[1])))
    print(f"    leaked declarations: {len(findings)}  (property-overlapping: {len(conflicts)})")
    print(f"    already-guarded declarations (no longer reachable): {guarded}")
    if "--conflicts-only" in sys.argv:
        findings = conflicts
    for f in findings:
        flag = "CONFLICT" if f["conflict"] else "extra   "
        media = f" @{f['context']}" if f["context"] else ""
        print(f"      {flag} {f['file']}:{f['line']}{media}")
        print(f"               {f['part']}  {{ {f['decl']} }}")
    return len(conflicts)


def main(argv: list[str]) -> int:
    names = [a for a in argv if not a.startswith("-")]
    only = None
    if "--file" in argv:
        only = argv[argv.index("--file") + 1]

    sheets = iter_sheets()

    if "--summary" in argv:
        total = 0
        for template in PAIRS:
            path = os.path.join(TPL, template)
            if not os.path.exists(path):
                continue
            with open(path, encoding="utf-8", errors="replace") as handle:
                source = handle.read()
            loaded = stylesheet_names(source)
            scripts = script_names(source) or PAIRS.get(template, [])
            classes, ids, tags = page_tokens(source, scripts)
            count = 0
            for name, text in sheets.items():
                if name in loaded:
                    continue
                for rule in parse_rules(text, name):
                    if any(matches_page(p, classes, ids, tags) for p in selector_parts(rule["selector"])):
                        count += 1
            total += count
            print(f"{template:26s} linked={len(loaded):2d}  leaked declarations={count}")
        print(f"\nleaked declarations across tracked pages: {total}")
        return 0

    targets = names or list(PAIRS)
    total = 0
    for template in targets:
        total += report(template, only, sheets)
    print(f"\nproperty-overlapping leaks: {total}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
