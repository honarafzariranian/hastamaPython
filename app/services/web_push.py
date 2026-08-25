"""Optional Web Push delivery for durable Hastama notifications."""
from __future__ import annotations

import json
import logging
from app.core.config import VAPID_PUBLIC_KEY, VAPID_SUBJECT
from typing import Iterable

logger = logging.getLogger("hastama.push")


def _settings():
    return (
        VAPID_PUBLIC_KEY.strip(),
        __import__("starlette.config", fromlist=["Config"]).Config(".env")("VAPID_PRIVATE_KEY", default="").strip(),
        VAPID_SUBJECT.strip(),
    )


def enabled() -> bool:
    public_key, private_key, subject = _settings()
    return bool(public_key and private_key and subject)


def public_key() -> str:
    return _settings()[0]


def send_to_subscriptions(subscriptions: Iterable[dict], payload: dict) -> list[str]:
    """Send best-effort Web Push messages.

    The dependency is imported lazily so existing installations keep working
    until Web Push is explicitly enabled and its package is installed.
    Returns endpoints reported as expired/invalid by the push service.
    """
    if not enabled():
        return []
    try:
        from pywebpush import webpush
    except ImportError:
        logger.warning("[Push] pywebpush is not installed; delivery skipped")
        return []

    _, private_key, subject = _settings()
    expired: list[str] = []
    body = json.dumps(payload, ensure_ascii=False)
    for subscription in subscriptions:
        endpoint = str(subscription.get("endpoint") or "")
        try:
            webpush(
                subscription_info={
                    "endpoint": endpoint,
                    "keys": {
                        "p256dh": subscription.get("p256dh"),
                        "auth": subscription.get("auth"),
                    },
                },
                data=body,
                vapid_private_key=private_key,
                vapid_claims={"sub": subject},
            )
            logger.info("[Push] Notification sent")
        except Exception as exc:  # delivery must never fail the source transaction
            status = getattr(getattr(exc, "response", None), "status_code", None)
            if status in (404, 410):
                expired.append(endpoint)
                logger.info("[Push] Subscription expired")
            else:
                logger.warning("[Push] Notification failed: %s", type(exc).__name__)
    return expired
