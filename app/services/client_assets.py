"""Deterministic, fully local minification of what the browser receives.

Production used to ship every JavaScript / CSS file and every rendered page with
all of its developer comments intact: 914 KB of comments across ``app/static``
(≈30 % of the payload) plus ~9 KB of HTML comments, several of which named
internal server modules and operational scripts.  Since the files are also
directly reachable at ``/static/...``, that detail travelled to any visitor who
pressed *View Source*.

This module removes exactly two things and nothing else:

* comments (``//``, ``/* … */`` for JS, ``/* … */`` for CSS, ``<!-- … -->`` for
  HTML outside raw-text elements), and
* redundant whitespace / indentation.

Newlines inside JavaScript are **kept** (automatic semicolon insertion is never
risked), string / template / regex literals are copied byte-for-byte, and every
JavaScript result is re-tokenised and compared token-for-token with its input —
if the two token streams differ, the transformation is refused and the original
bytes are served.  CSS results are checked for balanced braces/brackets/parens
and for unterminated strings.  HTML comments are only removed outside
``<script>``/``<style>``/``<textarea>``/``<title>`` and outside quoted
attributes; IE conditional comments are kept.

None of this is a security boundary.  Anything the browser receives can be read
and modified by the user — authorisation, authentication, validation and every
business rule stay on the server.  Minification here only reduces casual
readability and information disclosure.
"""

from __future__ import annotations

import os
import re
import threading

__all__ = [
    "ENV_FLAG",
    "MinifyError",
    "enabled",
    "minify_js",
    "minify_css",
    "strip_html_comments",
    "rewrite_body",
    "minified_static_bytes",
    "MAX_JS_BYTES",
]

#: Environment switch.  Unset/empty/0/false → behaviour is exactly as before.
ENV_FLAG = "HASTAMA_MINIFY_CLIENT_ASSETS"
_FALSY = {"", "0", "false", "no", "off"}

#: Vendor bundles that are already minified are skipped: re-tokenising a 900 KB
#: single-line file costs more than it returns and the only thing inside it is a
#: licence banner.  Ordinary application files are far below this.
MAX_JS_BYTES = 512 * 1024


class MinifyError(RuntimeError):
    """Raised when a file cannot be minified with certainty (caller keeps the original)."""


def enabled() -> bool:
    """True when production asked for comment-free delivery."""
    return os.getenv(ENV_FLAG, "").strip().lower() not in _FALSY


# ── JavaScript ───────────────────────────────────────────────────────────────

_JS_PUNCT = sorted(
    [
        ">>>=", "...", "===", "!==", "**=", "<<=", ">>=", ">>>", "&&=", "||=", "??=",
        "=>", "==", "!=", "<=", ">=", "&&", "||", "??", "?.", "++", "--", "+=", "-=",
        "*=", "/=", "%=", "&=", "|=", "^=", "<<", ">>", "**",
        "{", "}", "(", ")", "[", "]", ";", ",", "<", ">", "+", "-", "*", "/", "%",
        "&", "|", "^", "!", "~", "?", ":", "=", ".", "@", "#", "\\",
    ],
    key=len,
    reverse=True,
)

#: A ``/`` directly after one of these is a regular-expression literal.
_REGEX_PREV_WORDS = frozenset(
    {
        "return", "typeof", "instanceof", "in", "of", "new", "delete", "void", "throw",
        "case", "do", "else", "yield", "await",
    }
)
_REGEX_PREV_PUNCT = frozenset(
    {
        "(", "[", "{", ",", ";", ":", "=", "==", "===", "!=", "!==", "+", "-", "*", "/",
        "%", "!", "&&", "||", "??", "?", "=>", "...", "&", "|", "^", "~", "<", ">",
        "<=", ">=", "**",
    }
)

_ID_OK = re.compile(r"[A-Za-z0-9_$]")


def _id_start(ch: str) -> bool:
    return ch.isalpha() or ch in "_$" or ord(ch) > 0x7F


def _id_part(ch: str) -> bool:
    return ch.isalnum() or ch in "_$" or ord(ch) > 0x7F


def _js_scan(src: str):
    """Tokenise *src* enough to find comments and literals safely.

    Yields ``(kind, start, end)`` with ``kind`` in
    ``{"ws", "comment", "str", "tmpl", "regex", "num", "ident", "punct"}``.
    Raises :class:`MinifyError` on anything that cannot be read with certainty.
    """
    n = len(src)
    i = 0
    prev: tuple[str, str] | None = None  # (kind, text) of the previous significant token
    while i < n:
        ch = src[i]

        # whitespace
        if ch.isspace():
            j = i + 1
            while j < n and src[j].isspace():
                j += 1
            yield ("ws", i, j)
            i = j
            continue

        # comments
        if ch == "/" and i + 1 < n:
            nxt = src[i + 1]
            if nxt == "/":
                j = src.find("\n", i)
                j = n if j < 0 else j
                yield ("comment", i, j)
                i = j
                continue
            if nxt == "*":
                j = src.find("*/", i + 2)
                if j < 0:
                    raise MinifyError("unterminated block comment")
                yield ("comment", i, j + 2)
                i = j + 2
                continue

        # string literals
        if ch in "\"'":
            j = _js_string_end(src, i, ch)
            yield ("str", i, j)
            prev = ("str", ch)
            i = j
            continue

        # template literals (kept verbatim, including any ${ } expression)
        if ch == "`":
            j = _js_template_end(src, i)
            yield ("tmpl", i, j)
            prev = ("tmpl", "`")
            i = j
            continue

        # regular-expression literal
        if ch == "/" and _regex_allowed(prev):
            j = _js_regex_end(src, i)
            yield ("regex", i, j)
            prev = ("regex", "/")
            i = j
            continue

        # numbers
        if ch.isdigit() or (ch == "." and i + 1 < n and src[i + 1].isdigit()):
            j = i
            while j < n:
                c = src[j]
                if c.isalnum() or c in "._" or (c in "+-" and src[j - 1] in "eE"):
                    j += 1
                    continue
                break
            yield ("num", i, j)
            prev = ("num", src[i:j])
            i = j
            continue

        # identifiers / keywords
        if _id_start(ch):
            j = i + 1
            while j < n and _id_part(src[j]):
                j += 1
            text = src[i:j]
            yield ("ident", i, j)
            prev = ("ident", text)
            i = j
            continue

        # punctuators
        for punct in _JS_PUNCT:
            if src.startswith(punct, i):
                yield ("punct", i, i + len(punct))
                prev = ("punct", punct)
                i += len(punct)
                break
        else:  # pragma: no cover - every non-space char is covered above
            raise MinifyError(f"unrecognised character {ch!r} at {i}")


def _js_string_end(src: str, i: int, quote: str) -> int:
    n = len(src)
    j = i + 1
    while j < n:
        c = src[j]
        if c == "\\":
            j += 2
            continue
        if c == quote:
            return j + 1
        if c == "\n":
            raise MinifyError("unterminated string literal")
        j += 1
    raise MinifyError("unterminated string literal")


def _js_template_end(src: str, i: int) -> int:
    """End of a template literal, coping with ``${ … }`` (strings/braces inside)."""
    n = len(src)
    j = i + 1
    depth = 0
    while j < n:
        c = src[j]
        if c == "\\":
            j += 2
            continue
        if depth == 0 and c == "`":
            return j + 1
        if c == "$" and j + 1 < n and src[j + 1] == "{":
            depth += 1
            j += 2
            continue
        if depth and c == "{":
            depth += 1
        elif depth and c == "}":
            depth -= 1
        elif c in "\"'":
            j = _js_string_end(src, j, c)
            continue
        j += 1
    raise MinifyError("unterminated template literal")


def _js_regex_end(src: str, i: int) -> int:
    n = len(src)
    j = i + 1
    in_class = False
    while j < n:
        c = src[j]
        if c == "\\":
            j += 2
            continue
        if c == "\n":
            raise MinifyError("newline inside a regular-expression literal")
        if c == "[":
            in_class = True
        elif c == "]":
            in_class = False
        elif c == "/" and not in_class:
            j += 1
            while j < n and src[j].isalpha():
                j += 1
            return j
        j += 1
    raise MinifyError("unterminated regular-expression literal")


def _regex_allowed(prev: tuple[str, str] | None) -> bool:
    if prev is None:
        return True
    kind, text = prev
    if kind == "ident":
        return text in _REGEX_PREV_WORDS
    if kind == "num" or kind == "str" or kind == "tmpl" or kind == "regex":
        return False
    return text in _REGEX_PREV_PUNCT


_WS_RUN = re.compile(r"[ \t\r\f\v\u00a0\u2028\u2029]+")


def _tidy_gap(text: str) -> str:
    """Collapse redundant whitespace in a run that contains no literal."""
    if not text:
        return text
    text = _WS_RUN.sub(" ", text)
    # drop indentation and blank lines; newlines themselves are preserved
    text = re.sub(r" *\n[ \n]*", "\n", text)
    return text


def _js_tokens(src: str) -> list[tuple[str, str]]:
    return [
        (kind, src[s:e])
        for kind, s, e in _js_scan(src)
        if kind not in ("ws", "comment")
    ]


def minify_js(src: str) -> str:
    """Remove comments and indentation from JavaScript, or raise :class:`MinifyError`."""
    literal_kinds = ("str", "tmpl", "regex")
    out: list[str] = []
    gap: list[str] = []
    for kind, s, e in _js_scan(src):
        if kind == "comment":
            before = src[s - 1] if s > 0 else ""
            after = src[e] if e < len(src) else ""
            if _ID_OK.match(before or " ") and _ID_OK.match(after or " "):
                gap.append(" ")  # never let a comment glue two tokens together
            continue
        if kind in literal_kinds:
            out.append(_tidy_gap("".join(gap)))
            gap = []
            out.append(src[s:e])
            continue
        gap.append(src[s:e])
    out.append(_tidy_gap("".join(gap)))
    result = "".join(out)
    if _js_tokens(result) != _js_tokens(src):
        raise MinifyError("token stream changed")
    return result


# ── CSS ──────────────────────────────────────────────────────────────────────

#: A space is dropped next to these; a space between two ordinary characters is kept.
_CSS_TIGHT = frozenset("{};,")


def minify_css(src: str) -> str:
    """Remove comments and redundant whitespace from CSS."""
    n = len(src)
    out: list[str] = []
    pending_space = False
    depth_brace = depth_paren = depth_bracket = 0
    i = 0
    while i < n:
        ch = src[i]

        if ch in "\"'":
            j = _css_string_end(src, i, ch)
            if pending_space and out and out[-1] not in _CSS_TIGHT:
                out.append(" ")
            pending_space = False
            out.append(src[i:j])
            i = j
            continue

        if src.startswith("url(", i) or src.startswith("URL(", i):
            j = _css_url_end(src, i)
            if pending_space and out and out[-1] not in _CSS_TIGHT:
                out.append(" ")
            pending_space = False
            out.append(src[i:j])
            i = j
            continue

        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            if j < 0:
                raise MinifyError("unterminated comment")
            pending_space = True
            i = j + 2
            continue

        if ch.isspace():
            pending_space = True
            i += 1
            continue

        if ch == "{":
            depth_brace += 1
        elif ch == "}":
            depth_brace -= 1
        elif ch == "(":
            depth_paren += 1
        elif ch == ")":
            depth_paren -= 1
        elif ch == "[":
            depth_bracket += 1
        elif ch == "]":
            depth_bracket -= 1
        if depth_brace < 0 or depth_paren < 0 or depth_bracket < 0:
            raise MinifyError("unbalanced CSS block")

        if pending_space and out and out[-1] not in _CSS_TIGHT and ch not in _CSS_TIGHT:
            out.append(" ")
        pending_space = False
        out.append(ch)
        i += 1

    if depth_brace or depth_paren or depth_bracket:
        raise MinifyError("unbalanced CSS block")
    return "".join(out)


def _css_string_end(src: str, i: int, quote: str) -> int:
    n = len(src)
    j = i + 1
    while j < n:
        c = src[j]
        if c == "\\":
            j += 2
            continue
        if c == quote:
            return j + 1
        j += 1
    raise MinifyError("unterminated CSS string")


def _css_url_end(src: str, i: int) -> int:
    """End of a ``url(…)`` token — its body may legally contain ``//``."""
    n = len(src)
    j = i + 4
    if j < n and src[j] in "\"'":
        j = _css_string_end(src, j, src[j])
        k = src.find(")", j)
        return (k + 1) if k >= 0 else n
    k = src.find(")", j)
    return (k + 1) if k >= 0 else n


# ── HTML ─────────────────────────────────────────────────────────────────────

_RAW_TEXT_TAGS = ("script", "style", "textarea", "title")
_TAG_NAME = re.compile(r"<\s*([A-Za-z][A-Za-z0-9]*)")


def strip_html_comments(html: str) -> str:
    """Drop ``<!-- … -->`` outside raw-text elements; keep conditional comments."""
    out: list[str] = []
    n = len(html)
    i = 0
    raw_close: str | None = None
    while i < n:
        if raw_close is not None:
            j = html.lower().find(raw_close, i)
            if j < 0:
                out.append(html[i:])
                break
            out.append(html[i:j])
            i = j
            raw_close = None
            continue

        if html.startswith("<!--", i):
            if html.startswith("<!--[if", i) or html.startswith("<!--<!", i):
                j = html.find("-->", i)
                j = n if j < 0 else j + 3
                out.append(html[i:j])
                i = j
                continue
            j = html.find("-->", i + 4)
            if j < 0:  # unterminated: keep the rest exactly as it was
                out.append(html[i:])
                break
            i = j + 3
            continue

        if html[i] == "<":
            j, tag = _html_tag_end(html, i)
            out.append(tag)
            if not tag.rstrip().endswith("/>"):
                match = _TAG_NAME.match(tag)
                if match and match.group(1).lower() in _RAW_TEXT_TAGS:
                    raw_close = "</" + match.group(1).lower()
            i = j
            continue

        out.append(html[i])
        i += 1
    return "".join(out)


def _html_tag_end(html: str, i: int) -> tuple[int, str]:
    """Return ``(next_index, tag_text)`` for the tag starting at *i*."""
    n = len(html)
    j = i + 1
    quote: str | None = None
    while j < n:
        ch = html[j]
        if quote:
            if ch == quote:
                quote = None
        elif ch in "\"'":
            quote = ch
        elif ch == ">":
            return j + 1, html[i:j + 1]
        j += 1
    return n, html[i:]


# ── dispatch + cache ─────────────────────────────────────────────────────────

_CACHE: dict[str, tuple[int, bytes]] = {}
_CACHE_LOCK = threading.Lock()

#: A trailing ``//# sourceMappingURL=…`` pointer.  Production ships no ``.map``
#: files, so the pointer would only send DevTools to a 404 — and it advertises a
#: build artefact that does not exist.  Only a *trailing* pointer is touched, so
#: a mention inside code is never altered.
_SOURCE_MAP_TAIL = re.compile(r"//# sourceMappingURL=\S+[ \t]*$")


def strip_source_map_pointer(text: str) -> str:
    """Drop a trailing ``sourceMappingURL`` comment, if the file ends with one."""
    stripped = text.rstrip()
    if _SOURCE_MAP_TAIL.search(stripped):
        return _SOURCE_MAP_TAIL.sub("", stripped)
    return text


def rewrite_body(path: str, body: bytes, *, content_type: bytes = b"") -> bytes:
    """Return the comment-free form of *body*, or *body* itself when unsure."""
    if not body:
        return body
    try:
        if path.endswith(".js") and body[:1] not in (b"{", b"["):
            return rewrite_javascript(body)
        if path.endswith(".css"):
            return minify_css(body.decode("utf-8")).encode("utf-8")
        if content_type.startswith(b"text/html"):
            return strip_html_comments(body.decode("utf-8")).encode("utf-8")
    except (MinifyError, UnicodeDecodeError, RecursionError):
        return body
    return body


def rewrite_javascript(raw: bytes) -> bytes:
    """Comment-free JavaScript, with any stale source-map pointer removed.

    Oversized (already minified) bundles are not re-tokenised — the pointer is
    still stripped, because production ships no ``.map`` files.  A file whose
    comments cannot be removed with certainty keeps them, but never keeps a
    dangling source-map pointer.
    """
    text = strip_source_map_pointer(raw.decode("utf-8"))
    if len(raw) > MAX_JS_BYTES:
        return text.encode("utf-8")
    try:
        return minify_js(text).encode("utf-8")
    except MinifyError:
        return text.encode("utf-8")


def minified_static_bytes(static_root: str, url_path: str) -> bytes | None:
    """Cached comment-free bytes for ``/static/...`` or ``None`` if unavailable."""
    rel = url_path.split("/static/", 1)[-1]
    if not rel or ".." in rel:
        return None
    full = os.path.join(static_root, *[part for part in rel.split("/") if part])
    try:
        stat = os.stat(full)
    except OSError:
        return None
    key = full
    with _CACHE_LOCK:
        hit = _CACHE.get(key)
        if hit and hit[0] == stat.st_mtime_ns:
            return hit[1]
    try:
        with open(full, "rb") as handle:
            raw = handle.read()
    except OSError:
        return None
    if not (url_path.endswith(".js") or url_path.endswith(".css")):
        return raw
    result = rewrite_body(url_path, raw)
    if result != raw:
        with _CACHE_LOCK:
            _CACHE[key] = (stat.st_mtime_ns, result)
    return result
