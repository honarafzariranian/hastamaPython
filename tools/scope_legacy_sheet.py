"""Scope a legacy stylesheet to the pages that actually loaded it.

The Laravel bundle imports every legacy sheet into one global `app.css`, but the
Python app gave each page only the sheets it linked.  Any rule in a sheet a page
never loaded can therefore still win the cascade there — that is how
`.modal { display: none }` from `admin.css` flattened the user-panel dialogs to
0x0, and how `.btn-cancel { border-radius: 20px }` from the same sheet overrode
the user panel's own 12px button radius.

The fix follows the convention this migration already uses for
`user-panel-style.css` (`body:has(.app-shell)`): guard the offending selectors
with `:where(body:has(:where(roots)))`, which is

* **specificity-transparent** — the outer `:where()` contributes zero, so a
  guarded `.modal` is still 0-1-0 and the cascade inside the panel roots is
  unchanged;
* **page-level rather than ancestor-level** — an element the admin scripts append
  to `<body>` (the leave date picker, for one) is still covered, whereas a
  `:where(roots) .leave-date-picker` prefix would have orphaned it.

Only selectors that can actually match the protected page are touched, so the
diff stays reviewable and every other page is bit-for-bit unaffected.  A selector
that already carries a guard is skipped, making the pass idempotent.

Usage:
    python tools/scope_legacy_sheet.py admin.css \
        --roots .page-shell,.ma-page-shell,.admin-shell --against user-panel.html
    python tools/scope_legacy_sheet.py admin.css --roots ... --against ... --check
"""

from __future__ import annotations

import argparse
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from css_leak_audit import CSS, TPL, page_tokens, script_names, selector_parts, selector_tokens  # noqa: E402

GUARD_NOTE = "/* page-scoped by tools/scope_legacy_sheet.py"


def protected_vocabulary(template: str) -> tuple[set[str], set[str]]:
    with open(os.path.join(TPL, template), encoding="utf-8", errors="replace") as handle:
        source = handle.read()
    classes, ids, _tags = page_tokens(source, script_names(source))
    return classes, ids


def needs_guard(part: str, classes: set[str], ids: set[str], roots: list[str]) -> bool:
    """Whether *part* can match the protected page and still needs a guard.

    Keyframe steps (`from`, `50%`) and document-level selectors carry no class or
    id token, so they never qualify and are left untouched.
    """
    s_classes, s_ids, _ = selector_tokens(part)
    if not (s_classes & classes or s_ids & ids):
        return False
    if any(root.lstrip(".") in (s_classes | s_ids) for root in roots):
        return False            # already carries the page root
    if ":has(" in part:
        return False            # already guarded by an earlier pass
    head = part.split()[0] if part.split() else part
    if head.startswith("*") or re.match(r"^(html|body)$", head):
        return False            # document-level: applies to every page by nature
    return True


# --------------------------------------------------------------------------- #
# CSS block parsing (enough for legacy sheets: blocks that nest, and text)
# --------------------------------------------------------------------------- #

SPLIT = re.compile(r"(\s*,\s*)")


def parse(text: str, index: int = 0) -> tuple[list, int]:
    """Parse *text* into `('text', raw)` and `('block', selector, gap, children)`.

    Everything that is not a block is kept verbatim — comments, blank lines and
    the indentation before a selector included — so a rewritten sheet differs
    from the original only where a guard was actually inserted.
    """
    items: list = []
    buffer = ""
    while index < len(text):
        char = text[index]
        if char == "{":
            trimmed = buffer.rstrip()
            leading = trimmed[: len(trimmed) - len(trimmed.lstrip())]
            if leading:
                items.append(("text", leading))
            selector = trimmed.strip()
            gap = buffer[len(trimmed):]
            children, index = parse(text, index + 1)
            items.append(("block", selector, gap, children))
            buffer = ""
            continue
        if char == "}":
            if buffer:
                items.append(("text", buffer))
            return items, index + 1
        buffer += char
        index += 1
    if buffer:
        items.append(("text", buffer))
    return items, index


def serialize(items: list, guard: str, classes: set[str], ids: set[str], roots: list[str],
              counter: list[int]) -> str:
    out: list[str] = []
    for item in items:
        if item[0] == "text":
            out.append(item[1])
            continue
        _, selector, gap, children = item
        inner = serialize(children, guard, classes, ids, roots, counter)
        if selector.startswith("@"):
            out.append(f"{selector}{gap}{{{inner}}}")
            continue
        # Keep the author's commas and line breaks; only guard the parts that leak.
        pieces = SPLIT.split(selector)
        for position in range(0, len(pieces), 2):
            part = pieces[position]
            if needs_guard(part, classes, ids, roots):
                pieces[position] = f"{guard} {part}"
                counter[0] += 1
        out.append(f"{''.join(pieces)}{gap}{{{inner}}}")
    return "".join(out)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("sheet")
    parser.add_argument("--roots", required=True, help="comma separated page-root selectors")
    parser.add_argument("--against", required=True, help="reference template that must NOT be affected")
    parser.add_argument("--check", action="store_true", help="report only, do not write")
    args = parser.parse_args(argv)

    roots = [r.strip() for r in args.roots.split(",") if r.strip()]
    classes, ids = protected_vocabulary(args.against)
    path = os.path.join(CSS, args.sheet)
    with open(path, encoding="utf-8", errors="replace") as handle:
        original = handle.read()

    # The banner is metadata, not CSS; drop a previous one so re-runs stay clean.
    if original.startswith(GUARD_NOTE):
        end = original.index("*/") + 2
        body_source = original[end:]
    else:
        body_source = original

    items, _ = parse(body_source)
    counter = [0]
    guard = ":where(body:has(:where(" + ", ".join(roots) + ")))"
    updated = serialize(items, guard, classes, ids, roots, counter)

    print(f"{args.sheet}: selector parts that can still match {args.against}: {counter[0]}")
    if args.check or counter[0] == 0:
        return 0

    note = (
        f"{GUARD_NOTE}\n"
        f"   Guards keep these rules on the pages whose body contains\n"
        f"   {', '.join(roots)} — the templates that linked this sheet in the Python\n"
        f"   app.  {args.against} never linked it, so an unguarded rule could win the\n"
        f"   cascade there; {counter[0]} selector part(s) were guarded.  The guard wraps\n"
        f"   the whole selector in `:where(..)`, so it adds no specificity and the\n"
        f"   cascade inside those pages is unchanged.\n"
        f"   See docs/migration/DESIGN_PARITY.md §3.9 */\n"
    )
    with open(path, "w", encoding="utf-8", newline="") as handle:
        handle.write(note + updated)
    print(f"{args.sheet}: guarded {counter[0]} selector part(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
