"""Login experience — the loader that greets a user and the CAPTCHA lifetime.

Two annoyances on ``/login`` are configured here instead of being baked into the
page:

cut-off welcome
    When the credentials are correct the user used to watch the button turn into
    *"در حال ورود..."* and then briefly green before the page changed.  That whole
    sequence is gone: the page now covers itself with a branded loader for a
    configurable number of seconds (three by default) and lands on the requested
    page when it is done.  The request itself still travels immediately — the
    loader is a *minimum*, not a delay in front of the server call.

expired CAPTCHA
    A code is only valid for a limited time.  A user who leaves the tab open and
    then submits got a message that the page immediately erased (the refresh
    helper cleared it), so the screen looked as if nothing had happened.  The
    lifetime is configurable here, the page warns while the user is still typing,
    and the server now names the reason (``captcha_expired``) so the page can
    explain it and keep the explanation on screen.

The values live in ``system_config`` and are edited in
``master-admin → system settings``; they are applied without a restart.  The
login page reads them from the public ``/api/system-config`` endpoint (nothing
here is a secret).  See ``docs/network/UNIFIED_URL_ARCHITECTURE.md`` §9e and
RR-32.
"""
from __future__ import annotations

import logging

from app.services import system_config

logger = logging.getLogger("hastama.login_experience")

# ── Settings (``system_config`` keys) ───────────────────────────────────────

ENABLED_KEY = "login_loader_enabled"
SECONDS_KEY = "login_loader_seconds"
TITLE_KEY = "login_loader_title"
MESSAGE_KEY = "login_loader_message"
NOTICE_KEY = "login_captcha_notice"
TTL_KEY = "login_captcha_ttl_seconds"

_DESCRIPTIONS = {
    ENABLED_KEY: "نمایش لودر تمام‌صفحه هنگام ورود به سامانه",
    SECONDS_KEY: "مدت نمایش لودر ورود (ثانیه)",
    TITLE_KEY: "عنوان لودر ورود",
    MESSAGE_KEY: "متن زیر عنوان لودر ورود",
    NOTICE_KEY: "هشدار زودهنگام انقضای کد امنیتی در صفحهٔ ورود",
    TTL_KEY: "مدت اعتبار کد امنیتی (ثانیه)",
}

DEFAULT_TITLE = "در حال آماده‌سازی میزکار شما…"
DEFAULT_MESSAGE = "لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما."

DEFAULT_SECONDS = 3
MIN_SECONDS, MAX_SECONDS = 1, 15

#: The CAPTCHA lifetime.  The previous hard-coded value (180 s) is the default;
#: the lower bound keeps a code usable long enough to type, the upper bound keeps
#: it from becoming a long lived credential.
DEFAULT_CAPTCHA_TTL_SECONDS = 180
MIN_CAPTCHA_TTL_SECONDS, MAX_CAPTCHA_TTL_SECONDS = 30, 1800

MAX_TITLE_CHARS = 80
MAX_MESSAGE_CHARS = 200

#: Warn the user this many seconds before the code dies, so the countdown is
#: visible while they are still typing instead of only after the fact.
CAPTCHA_WARNING_LEAD_SECONDS = 60


class LoginExperienceError(RuntimeError):
    """The login-experience settings are invalid or could not be applied."""


class _LoginState:
    def __init__(self) -> None:
        self.loader_enabled = True
        self.loader_seconds = DEFAULT_SECONDS
        self.loader_title = DEFAULT_TITLE
        self.loader_message = DEFAULT_MESSAGE
        self.captcha_notice = True
        self.captcha_ttl = DEFAULT_CAPTCHA_TTL_SECONDS


_state = _LoginState()


# ── Accessors used by the login page and the CAPTCHA service ────────────────

def loader_enabled() -> bool:
    return bool(_state.loader_enabled)


def loader_seconds() -> int:
    """Loader duration, always inside the configured bounds."""
    seconds = int(_state.loader_seconds)
    return max(MIN_SECONDS, min(MAX_SECONDS, seconds))


def loader_title() -> str:
    return _state.loader_title or DEFAULT_TITLE


def loader_message() -> str:
    return _state.loader_message or DEFAULT_MESSAGE


def captcha_notice_enabled() -> bool:
    """Whether the login page warns the user *before* the code expires."""
    return bool(_state.captcha_notice)


def captcha_ttl_seconds() -> int:
    """Lifetime of a newly generated code, in seconds."""
    ttl = int(_state.captcha_ttl)
    return max(MIN_CAPTCHA_TTL_SECONDS, min(MAX_CAPTCHA_TTL_SECONDS, ttl))


def public_config() -> dict:
    """The subset the unauthenticated login page is allowed to read.

    Values are strings, like every other row of ``/api/system-config``.
    """
    return {
        ENABLED_KEY: "1" if _state.loader_enabled else "0",
        SECONDS_KEY: str(loader_seconds()),
        TITLE_KEY: loader_title(),
        MESSAGE_KEY: loader_message(),
        NOTICE_KEY: "1" if _state.captcha_notice else "0",
        TTL_KEY: str(captcha_ttl_seconds()),
    }


def status() -> dict:
    """Everything the settings card and the API need to show."""
    return {
        "loader_enabled": bool(_state.loader_enabled),
        "loader_seconds": loader_seconds(),
        "loader_title": loader_title(),
        "loader_message": loader_message(),
        "captcha_notice": bool(_state.captcha_notice),
        "captcha_ttl_seconds": captcha_ttl_seconds(),
        "captcha_warning_lead_seconds": CAPTCHA_WARNING_LEAD_SECONDS,
        "min_seconds": MIN_SECONDS,
        "max_seconds": MAX_SECONDS,
        "min_captcha_ttl_seconds": MIN_CAPTCHA_TTL_SECONDS,
        "max_captcha_ttl_seconds": MAX_CAPTCHA_TTL_SECONDS,
        "public_config_keys": sorted(public_config().keys()),
    }


# ── Settings ────────────────────────────────────────────────────────────────

def _clean_text(value, limit: int, fallback: str) -> str:
    text = " ".join(str(value or "").split())
    return (text or fallback)[:limit]


def _load_settings() -> None:
    _state.loader_enabled = system_config.read_flag(ENABLED_KEY, True)
    _state.captcha_notice = system_config.read_flag(NOTICE_KEY, True)
    _state.loader_seconds = system_config.read_int(
        SECONDS_KEY, DEFAULT_SECONDS, minimum=MIN_SECONDS, maximum=MAX_SECONDS
    )
    _state.captcha_ttl = system_config.read_int(
        TTL_KEY,
        DEFAULT_CAPTCHA_TTL_SECONDS,
        minimum=MIN_CAPTCHA_TTL_SECONDS,
        maximum=MAX_CAPTCHA_TTL_SECONDS,
    )
    _state.loader_title = (
        system_config.read_value(TITLE_KEY) or DEFAULT_TITLE
    )[:MAX_TITLE_CHARS]
    _state.loader_message = (
        system_config.read_value(MESSAGE_KEY) or DEFAULT_MESSAGE
    )[:MAX_MESSAGE_CHARS]


def start() -> dict:
    """Startup hook: read the saved login-experience settings."""
    _load_settings()
    logger.warning(
        "login experience: loader %s (%ss), captcha lifetime %ss, expiry notice %s",
        "on" if _state.loader_enabled else "off",
        loader_seconds(),
        captcha_ttl_seconds(),
        "on" if _state.captcha_notice else "off",
    )
    return status()


def apply_settings(data: dict, actor: str = "") -> dict:
    """Validate, persist and apply the settings; raises on bad input."""
    if not isinstance(data, dict):
        raise LoginExperienceError("تنظیمات نامعتبر است.")

    title = _clean_text(data.get("loader_title"), MAX_TITLE_CHARS, DEFAULT_TITLE)
    message = _clean_text(data.get("loader_message"), MAX_MESSAGE_CHARS, DEFAULT_MESSAGE)

    try:
        seconds = int(data.get("loader_seconds", _state.loader_seconds))
    except (TypeError, ValueError):
        raise LoginExperienceError("مدت نمایش لودر باید عدد باشد.")
    if not (MIN_SECONDS <= seconds <= MAX_SECONDS):
        raise LoginExperienceError(
            f"مدت نمایش لودر باید بین {MIN_SECONDS} و {MAX_SECONDS} ثانیه باشد."
        )

    try:
        ttl = int(data.get("captcha_ttl_seconds", _state.captcha_ttl))
    except (TypeError, ValueError):
        raise LoginExperienceError("مدت اعتبار کد امنیتی باید عدد باشد.")
    if not (MIN_CAPTCHA_TTL_SECONDS <= ttl <= MAX_CAPTCHA_TTL_SECONDS):
        raise LoginExperienceError(
            "مدت اعتبار کد امنیتی باید بین "
            f"{MIN_CAPTCHA_TTL_SECONDS} و {MAX_CAPTCHA_TTL_SECONDS} ثانیه باشد."
        )

    loader_enabled = system_config.truthy(data.get("loader_enabled", _state.loader_enabled))
    notice = system_config.truthy(data.get("captcha_notice", _state.captcha_notice))

    writes = [
        (ENABLED_KEY, "1" if loader_enabled else "0"),
        (SECONDS_KEY, str(seconds)),
        (TITLE_KEY, title),
        (MESSAGE_KEY, message),
        (NOTICE_KEY, "1" if notice else "0"),
        (TTL_KEY, str(ttl)),
    ]
    failed = [
        key
        for key, value in writes
        if not system_config.write_value(key, value, actor=actor, description=_DESCRIPTIONS.get(key, ""))
    ]

    _load_settings()
    saved = status()
    saved["saved"] = not failed
    if failed:
        logger.error("login-experience settings not fully saved: %s", ", ".join(failed))
    return saved
