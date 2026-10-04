"""Computed-style parity harness (Python reference vs Laravel port).

`design_parity.py` compares *class vocabulary*; `css_coverage.py` compares
*sheet inventory*.  Neither can tell you that a page renders differently, which
is the thing that actually matters: the Laravel bundle imports every legacy
stylesheet, so a rule from a sheet the page never loaded can still win the
cascade (`.modal { display: none }` from admin.css flattened the user-panel
dialogs to 0x0).

This harness compares the *rendered result* instead:

  1. `emit` writes a self-contained JS expression that walks every id/class
     selector the reference template uses, plus the dynamic classes its scripts
     add, and records each match's bounding rect and computed style vector;
  2. you paste that expression into the browser on each app and save the JSON;
  3. `diff` lines the two snapshots up by selector and by match index and prints
     the properties that disagree.

Because the two DOMs are not identical, the comparison is a *report*, not an
assertion: a selector that exists on one side only, or a different match count,
is itself a finding.

Usage:
    python tools/style_parity.py emit user-panel.html          > .audit/fp.js
    python tools/style_parity.py diff .audit/py.json .audit/lv.json
    python tools/style_parity.py diff --summary py.json lv.json
"""

from __future__ import annotations

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from css_leak_audit import PAIRS, TPL, page_tokens, script_names  # noqa: E402

# The style vector.  Chosen so that a difference is meaningful for layout or
# appearance, cheap enough to read for ~500 selectors, and stable across runs
# (no animation values, no timing-dependent properties).
PROPERTIES = [
    "display", "position", "visibility", "opacity", "z-index", "float",
    "box-sizing", "overflow", "overflow-x", "overflow-y",
    "width", "height", "min-width", "min-height", "max-width", "max-height",
    "top", "right", "bottom", "left",
    "margin-top", "margin-right", "margin-bottom", "margin-left",
    "padding-top", "padding-right", "padding-bottom", "padding-left",
    "border-top-width", "border-right-width", "border-bottom-width",
    "border-left-width", "border-top-style", "border-radius",
    "border-top-color", "border-bottom-color",
    "background-color", "background-image", "color",
    "font-family", "font-size", "font-weight", "font-style",
    "line-height", "letter-spacing", "text-align", "text-decoration-line",
    "white-space", "direction", "writing-mode", "text-overflow",
    "flex-direction", "flex-wrap", "justify-content", "align-items",
    "align-self", "flex-grow", "flex-shrink", "order", "gap",
    "grid-template-columns", "grid-template-rows", "grid-auto-flow",
    "transform", "transition-duration", "cursor", "pointer-events",
    "box-shadow", "list-style-type", "text-shadow",
]

TEMPLATE = "user-panel.html"


def selectors_for(template: str) -> list[str]:
    """Every id and class selector the reference page uses, most specific first."""
    with open(os.path.join(TPL, template), encoding="utf-8", errors="replace") as handle:
        source = handle.read()
    scripts = script_names(source) or PAIRS.get(template, [])
    classes, ids, _tags = page_tokens(source, scripts)

    # A class that is also an element id name is covered by the id selector; keep
    # both only when they differ, so the report does not double-count.
    selectors = [f"#{name}" for name in sorted(ids)]
    selectors += [f".{name}" for name in sorted(classes)]
    return selectors


def emit(template: str) -> str:
    selectors = selectors_for(template)
    return (
        "(function(){var sels=__SELS__,props=__PROPS__;"
        "var out={url:location.href,viewport:[innerWidth,innerHeight],"
        "body:document.body.className,dpr:devicePixelRatio,items:{}};"
        "for(var i=0;i<sels.length;i++){var sel=sels[i],nodes;"
        "try{nodes=document.querySelectorAll(sel);}catch(e){continue;}"
        "if(!nodes.length){out.items[sel]=null;continue;}"
        "var list=[];for(var j=0;j<nodes.length;j++){var el=nodes[j],cs=getComputedStyle(el),"
        "r=el.getBoundingClientRect();var o={rect:[Math.round(r.x),Math.round(r.y),"
        "Math.round(r.width),Math.round(r.height)],tag:el.tagName.toLowerCase()};"
        "for(var k=0;k<props.length;k++){o[props[k]]=cs.getPropertyValue(props[k]);}list.push(o);}"
        "out.items[sel]=list;}"
        "return JSON.stringify(out);})()"
    ).replace("__SELS__", json.dumps(selectors, ensure_ascii=False)).replace(
        "__PROPS__", json.dumps(PROPERTIES)
    )


def snapshot(path: str) -> dict:
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def diff(reference: dict, ported: dict, summary_only: bool = False, limit: int = 400) -> int:
    ref_items = reference.get("items", {})
    new_items = ported.get("items", {})

    print(f"reference: {reference.get('url')}  {reference.get('viewport')}  "
          f"body={reference.get('body')!r}")
    print(f"ported   : {ported.get('url')}  {ported.get('viewport')}  "
          f"body={ported.get('body')!r}")

    only_ref = sorted(set(ref_items) - set(new_items))
    only_new = sorted(set(new_items) - set(ref_items))
    findings: list[tuple[str, str, list[str]]] = []

    for selector in sorted(set(ref_items) & set(new_items)):
        left, right = ref_items[selector], new_items[selector]
        if left is None and right is None:
            continue
        if left is None or right is None:
            findings.append((selector, "present on one side only", []))
            continue
        if len(left) != len(right):
            findings.append((selector, f"match count {len(left)} vs {len(right)}", []))
            continue
        for index, (a, b) in enumerate(zip(left, right)):
            problems = []
            if a["rect"] != b["rect"]:
                problems.append(f"rect {a['rect']} != {b['rect']}")
            for prop in PROPERTIES:
                if a.get(prop) != b.get(prop):
                    problems.append(f"{prop}: {a.get(prop)!r} != {b.get(prop)!r}")
            if problems:
                label = selector if len(left) == 1 else f"{selector}[{index}]"
                findings.append((label, "", problems))

    print(f"\nselectors: reference={len(ref_items)} ported={len(new_items)}")
    print(f"only in reference ({len(only_ref)}): {', '.join(only_ref[:20])}"
          + (" …" if len(only_ref) > 20 else ""))
    print(f"only in ported    ({len(only_new)}): {', '.join(only_new[:20])}"
          + (" …" if len(only_new) > 20 else ""))
    print(f"selectors with differences: {len({f[0].split('[')[0] for f in findings})}"
          f"  (declaration-level differences: {sum(len(f[2]) for f in findings) + len([f for f in findings if not f[2]])})")

    if summary_only:
        from collections import Counter

        counter = Counter()
        for label, note, problems in findings:
            for problem in problems:
                counter[problem.split(":")[0]] += 1
            if note:
                counter[note.split(" ")[0]] += 1
        print("\nproperties most often different:")
        for prop, count in counter.most_common(30):
            print(f"    {count:5d}  {prop}")
        return len(findings)

    for label, note, problems in findings[:limit]:
        print(f"\n  {label}")
        if note:
            print(f"      {note}")
        for problem in problems[:24]:
            print(f"      {problem}")
        if len(problems) > 24:
            print(f"      … (+{len(problems) - 24} more)")
    return len(findings)


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__)
        return 1
    command = argv[0]

    if command == "emit":
        template = argv[1] if len(argv) > 1 else TEMPLATE
        print(emit(template))
        return 0

    if command == "diff":
        summary_only = "--summary" in argv
        paths = [a for a in argv[1:] if not a.startswith("-")]
        if len(paths) != 2:
            print("diff needs exactly two snapshot files")
            return 1
        count = diff(snapshot(paths[0]), snapshot(paths[1]), summary_only)
        counts = ""
        print(f"\ndiffering selectors: {count}")
        return 0

    print(f"unknown command {command!r}")
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
