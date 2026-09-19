"""Shared input validation helpers.

Several write paths accepted unauthenticated or administrator supplied text
(names, departments, queue labels) without length or content checks.  That text
is later rendered by the admin UI, so unvalidated markup became a stored-XSS
primitive.  Escaping at the render layer (see ``app/static/js/master-admin.js``)
is the primary control; rejecting markup on input is the defence in depth.
"""

from __future__ import annotations

import re

# Iranian and Latin letters, digits, spaces and a conservative punctuation set.
_DISPLAY_ALLOWED = re.compile(r"^[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF"
                              r"A-Za-z0-9 .,_\-/()\u200c\u200f]+$")

CONTROL_CHARS = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")


def strip_control(value: str) -> str:
    """Remove ASCII control characters (log/report injection, NUL truncation)."""
    return CONTROL_CHARS.sub("", value or "")


def clean_display_text(value, *, max_length: int, field: str = "value", required: bool = False,
                       allow_punctuation: bool = True) -> str:
    """Validate a human readable field.

    Raises ``ValueError`` with a Persian operator-facing message when the value
    is too long, empty (when required) or contains markup / control characters.
    """
    text = strip_control(str(value if value is not None else "")).strip()
    if not text:
        if required:
            raise ValueError(f"{field} الزامی است.")
        return ""
    if len(text) > max_length:
        raise ValueError(f"{field} نباید بیش از {max_length} کاراکتر باشد.")
    if "<" in text or ">" in text or "&" in text and "&amp;" not in text:
        raise ValueError(f"{field} شامل کاراکترهای غیرمجاز است.")
    if allow_punctuation and not _DISPLAY_ALLOWED.match(text):
        raise ValueError(f"{field} شامل کاراکترهای غیرمجاز است.")
    return text


def clean_department(value, *, max_length: int = 100) -> str:
    return clean_display_text(value, max_length=max_length, field="بخش")


def normalize_username(value) -> str:
    return strip_control(str(value or "")).strip()


# Characters that turn stored text into markup.  Rejecting them on the way in is
# defence in depth: the render layer escapes (see app/static/js/dom-escape.js),
# but a field that can never contain markup cannot become a stored-XSS
# primitive if some future screen forgets to escape.
_MARKUP_CHARS = ("<", ">", "\x00")


def reject_markup(value, *, max_length: int, field: str = "value", required: bool = False) -> str:
    """Reject angle brackets / NUL in free text while keeping normal punctuation.

    Unlike :func:`clean_display_text` this deliberately allows the full range of
    punctuation (quotes, colons, Persian punctuation), so it can be applied to
    employee written descriptions without changing accepted input.
    """
    text = strip_control(str(value if value is not None else "")).strip()
    if not text:
        if required:
            raise ValueError(f"{field} الزامی است.")
        return ""
    if len(text) > max_length:
        raise ValueError(f"{field} نباید بیش از {max_length} کاراکتر باشد.")
    if any(char in text for char in _MARKUP_CHARS):
        raise ValueError(f"{field} شامل کاراکترهای غیرمجاز است.")
    return text
