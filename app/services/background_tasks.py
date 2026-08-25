"""Application background jobs for notification maintenance."""
from __future__ import annotations

import logging
from datetime import datetime, timezone

from apscheduler.schedulers.background import BackgroundScheduler

from app.core.database import connection

logger = logging.getLogger("hastama.notifications.scheduler")
_scheduler: BackgroundScheduler | None = None


def publish_due_notifications() -> None:
    """Publish scheduled notifications without depending on an API request."""
    from app.api.routes import notifications

    try:
        with connection(commit=True) as conn:
            notifications._publish_due(conn.cursor())
        logger.info("scheduled notification sweep completed")
    except Exception:
        logger.exception("scheduled notification sweep failed")


def cleanup_expired_subscriptions() -> None:
    """Disable subscriptions that have been invalidated by a push provider."""
    # Provider expiry is handled immediately on 404/410. This maintenance job
    # removes no data; it only records that stale rows remain soft-disabled.
    try:
        with connection() as conn:
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM push_subscriptions WHERE disabled_at IS NOT NULL")
            count = int(cur.fetchone()[0])
        logger.info("push subscription maintenance completed disabled=%d", count)
    except Exception:
        logger.exception("push subscription maintenance failed")


def start_background_tasks() -> BackgroundScheduler:
    global _scheduler
    if _scheduler and _scheduler.running:
        return _scheduler
    scheduler = BackgroundScheduler(timezone=timezone.utc, daemon=True)
    scheduler.add_job(publish_due_notifications, "interval", seconds=30, id="publish-due", replace_existing=True)
    scheduler.add_job(cleanup_expired_subscriptions, "interval", hours=1, id="cleanup-push", replace_existing=True)
    scheduler.start()
    _scheduler = scheduler
    logger.info("notification background scheduler started")
    return scheduler


def stop_background_tasks() -> None:
    global _scheduler
    if _scheduler and _scheduler.running:
        _scheduler.shutdown(wait=False)
        logger.info("notification background scheduler stopped")
    _scheduler = None
