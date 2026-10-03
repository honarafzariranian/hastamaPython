#!/usr/bin/env python3
"""Cross-check every `api.<verb>(...)` call in the Vue front-end against the
URLs Laravel actually serves.

Why this exists
---------------
The shared axios client (`resources/js/services/api.js`) has `baseURL: '/api'`,
while the ported legacy handlers live at the application root (`POST
/login_user`, `/logout`, `/get_user_info`, …).  A call therefore has to pass
`{ baseURL: '' }` to reach them.  Forgetting the override does not fail loudly:
Laravel answers the SPA catch-all, or a 405 from a same-named route under
`/api`, and the operator sees a confusing message instead of a missing page.
That is exactly how `POST /api/login_user` broke login.

The route table comes from `php artisan route:list --json`, not from scraping
the route files: prefixes, middleware groups and route-file ordering all decide
the final URI, and only the framework knows the answer.

The tool separates two very different findings:

* **wrong base URL** — the route exists one prefix over (usually `/x` instead
  of `/api/x`).  The page is broken today and the fix is local to the call.
* **not ported** — nothing answers either path.  The endpoint is still on the
  migration backlog; the call is dead code from the side-by-side period.

Usage
-----
    python tools/api_paths.py [--verbose] [--routes path/to/routes.json]

`--routes` reuses a cached `route:list --json` dump, which is what CI would do.
Exit code 1 when a wrong-base call exists, 0 otherwise.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS_ROOT = ROOT / "laravel" / "resources" / "js"

CALL = re.compile(r"\bapi\.(get|post|put|patch|delete)\(\s*([`'\"])")


def matching_paren(text: str, start: int) -> str:
    """Return the argument slice of the call whose `(` is at `start`."""
    depth = 0
    for index in range(start, len(text)):
        char = text[index]
        if char in "([{":
            depth += 1
        elif char in ")]}":
            depth -= 1
            if depth == 0:
                return text[start : index + 1]
    return text[start:]


def normalize(path: str) -> str:
    """Collapse template interpolations, route params and queries to one wildcard."""
    path = re.sub(r"\$\{[^}]*\}", "*", path)
    path = path.split("?")[0]
    path = re.sub(r"\{[^}]*\}", "*", path)
    path = re.sub(r"/+", "/", path)
    return path.rstrip("/") or "/"


def js_calls() -> list[tuple[str, int, str, str, bool]]:
    """(file, line, verb, effective url, override present) for every api.<verb> call."""
    found = []
    for path in sorted(list(JS_ROOT.rglob("*.js")) + list(JS_ROOT.rglob("*.vue"))):
        text = path.read_text(encoding="utf-8")
        for match in CALL.finditer(text):
            verb, quote = match.group(1), match.group(2)
            args_at = match.end() - 1
            end = text.find(quote, args_at + 1)
            if end == -1:
                continue
            literal = text[args_at + 1 : end]
            if not literal.startswith("/"):
                continue  # dynamic target; nothing to check statically
            # `(` sits at `api.` + the verb, i.e. start + 4 + len(verb).  Off by one
            # here and every call whose URL interpolates a call —
            # `/x/${encodeURIComponent(y)}` — loses its argument slice, which
            # reports working code as broken.
            config = matching_paren(text, match.start(0) + 4 + len(verb))
            rooted = "baseURL" in config
            url = literal if rooted else "/api" + literal
            line = text[: match.start()].count("\n") + 1
            found.append(
                (
                    str(path.relative_to(ROOT)).replace("\\", "/"),
                    line,
                    verb.upper(),
                    normalize(url),
                    rooted,
                )
            )
    return found


def route_table(routes_json: str | None) -> dict[str, set[str]]:
    """effective path -> HTTP verbs Laravel serves for it."""
    if routes_json:
        payload = json.loads(Path(routes_json).read_text(encoding="utf-8"))
    else:
        payload = json.loads(
            subprocess.run(
                ["php", "artisan", "route:list", "--json"],
                cwd=ROOT / "laravel",
                capture_output=True,
                text=True,
                check=True,
            ).stdout
        )

    table: dict[str, set[str]] = {}
    for route in payload:
        uri = normalize("/" + route["uri"].strip("/"))
        for verb in str(route["method"]).split("|"):
            table.setdefault(uri, set()).add(verb.upper())
    return table


def answered(path: str, verb: str, table: dict[str, set[str]]) -> bool:
    if path in table:
        return verb in table[path]
    # Wildcard match: same segment count, literals equal, `*` matches anything.
    wanted = path.strip("/").split("/")
    for candidate, verbs in table.items():
        parts = candidate.strip("/").split("/")
        if len(parts) != len(wanted):
            continue
        if all(w == "*" or p == "*" or w == p for w, p in zip(wanted, parts)) and verb in verbs:
            return True
    return False


def main() -> int:
    argv = sys.argv[1:]
    verbose = "--verbose" in argv
    routes_json = argv[argv.index("--routes") + 1] if "--routes" in argv else None

    table = route_table(routes_json)
    calls = js_calls()
    wrong_base: list[tuple[str, int, str, str, str]] = []
    not_ported: list[tuple[str, int, str, str]] = []

    for file, line, verb, url, rooted in calls:
        if answered(url, verb, table):
            if verbose:
                print(f"ok   {verb:6} {url:55} {file}:{line}")
            continue

        # A route that exists one prefix over is a wrong-base-URL defect: the
        # page works, the client just asked the wrong URL.  Nothing at either
        # path means the endpoint has not been ported yet.
        sibling = url[4:] if url.startswith("/api/") else "/api" + url
        if answered(sibling, verb, table):
            wrong_base.append((file, line, verb, url, sibling))
        else:
            not_ported.append((file, line, verb, url))

    if wrong_base:
        print("WRONG BASE URL — the route exists, the client asked the wrong path:")
        for file, line, verb, url, sibling in wrong_base:
            print(f"  {verb:6} {url:46} -> {sibling:42} {file}:{line}")
        print()

    if not_ported:
        print(f"NOT PORTED — no route answers either path ({len(not_ported)}):")
        for file, line, verb, url in sorted(not_ported):
            print(f"  {verb:6} {url:46} {file}:{line}")
        print()

    print(
        f"{len(wrong_base)} wrong-base call(s); {len(not_ported)} unported; "
        f"{len(calls)} literal api.* calls checked against {len(table)} route paths."
    )
    return 1 if wrong_base else 0


if __name__ == "__main__":
    raise SystemExit(main())
