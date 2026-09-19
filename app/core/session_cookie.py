"""Read-only access to the signed Starlette session cookie.

The CSRF and session-registry middlewares run *outside* ``SessionMiddleware`` in
the ASGI stack, so ``scope["session"]`` is not populated yet when they execute.
Rather than re-ordering the middleware stack (which would change response header
processing), these helpers decode the signed cookie exactly the way Starlette's
``SessionMiddleware`` does:

* ``itsdangerous.TimestampSigner(secret, salt="starlette.sessions")``
* base64 encoded JSON payload

The decoding is read-only: middlewares that need to *invalidate* a session emit
a deletion ``Set-Cookie`` instead of re-signing the payload.  Equivalence with
Starlette is asserted by ``tests/test_security_hardening.py``.
"""

from __future__ import annotations

import base64
import binascii
import json
import logging
from typing import Any, Dict, Optional

from itsdangerous import BadSignature, TimestampSigner

logger = logging.getLogger("hastama.session_cookie")

SESSION_COOKIE_NAME = "session"
# Salt used by older Starlette releases.  Starlette >= 1.0 signs without an
# explicit salt, so both forms are accepted (same secret key, different key
# derivation label).  ``_unsign`` always tries the installed version's format
# first; the fallback exists so an upgrade/downgrade of Starlette cannot
# silently invalidate every session or, worse, make the middlewares fail open.
SESSION_SALT = "starlette.sessions"


def parse_session_cookie(cookie_value: Optional[str], secret: str, max_age: int = 0) -> Dict[str, Any]:
    """Return the session dictionary encoded in *cookie_value*.

Any malformed, tampered or expired cookie yields an empty dictionary — the
caller must treat that as "no session" and continue as unauthenticated.
"""
    if not cookie_value or not secret:
        return {}
    try:
        data = _unsign(cookie_value, secret, max_age)
        payload = json.loads(base64.b64decode(data))
    except (BadSignature, ValueError, TypeError, binascii.Error):
        return {}
    except Exception as exc:  # pragma: no cover - defensive
        logger.warning("session cookie decode failed: %s", type(exc).__name__)
        return {}
    return payload if isinstance(payload, dict) else {}


def installed_signer(secret: str):
    """Build the signer the installed Starlette's SessionMiddleware uses.

    Starlette 1.6 constructs ``itsdangerous.TimestampSigner(str(secret_key))``
    with no salt argument.  Older releases passed ``salt="starlette.sessions"``.
    Rather than hard-coding a guess, inspect the class source once and mirror it.
    """
    from starlette.middleware.sessions import SessionMiddleware

    try:
        import inspect

        source = inspect.getsource(SessionMiddleware.__init__)
        if "salt" in source:
            return TimestampSigner(str(secret), salt=SESSION_SALT)
    except Exception:  # pragma: no cover - defensive
        pass
    return TimestampSigner(str(secret))


def _unsign(cookie_value: str, secret: str, max_age: int) -> bytes:
    """Verify the cookie with the expected signer, then with the legacy one."""
    signers = [installed_signer(secret)]
    legacy = TimestampSigner(str(secret), salt=SESSION_SALT)
    if not any(getattr(s, "salt", None) == getattr(legacy, "salt", None) for s in signers):
        signers.append(legacy)
    last_error: Optional[Exception] = None
    for signer in signers:
        try:
            return signer.unsign(cookie_value, max_age=max_age or None)
        except Exception as exc:  # BadSignature / SignatureExpired
            last_error = exc
    raise last_error if last_error else BadSignature("invalid session cookie")


def cookie_header_value(cookies: Dict[str, str]) -> Optional[str]:
    return cookies.get(SESSION_COOKIE_NAME)


def expire_cookie_header(*, secure: bool = False) -> bytes:
    """``Set-Cookie`` value that clears the session cookie."""
    parts = [
        f"{SESSION_COOKIE_NAME}=",
        "Path=/",
        "Max-Age=0",
        "HttpOnly",
        "SameSite=lax",
    ]
    if secure:
        parts.append("Secure")
    return "; ".join(parts).encode("latin-1")
