"""Architecture and security regression tests for the notification feature."""
from pathlib import Path

from starlette.requests import Request

from app.api.routes import notifications

ROOT = Path(__file__).resolve().parents[1]


def _request(session):
    return Request({"type": "http", "method": "GET", "path": "/", "headers": [], "session": session})


def test_notification_schema_is_normalized_and_indexed():
    sql = (ROOT / "database" / "notifications.sql").read_text(encoding="utf-8")
    assert "CREATE TABLE dbo.notifications" in sql
    assert "CREATE TABLE dbo.user_notifications" in sql
    assert "CREATE TABLE dbo.notification_targets" in sql
    assert "UQ_user_notifications UNIQUE (notification_id, username)" in sql
    assert "IX_user_notifications_inbox" in sql
    assert "IX_user_notifications_unread" in sql
    assert "action_url IS NULL OR action_url LIKE '/%'" in sql


def test_admin_authorization_uses_signed_session_role():
    assert notifications._actor(_request({"username": "admin", "is_admin": True}), admin=True) == "admin"
    try:
        notifications._actor(_request({"username": "alice", "is_admin": False}), admin=True)
    except Exception as exc:
        assert exc.status_code == 403
    else:
        raise AssertionError("normal user gained admin notification access")


def test_notification_ui_is_integrated_in_both_panels():
    admin = (ROOT / "app/templates/admin.html").read_text(encoding="utf-8")
    user = (ROOT / "app/templates/user-panel.html").read_text(encoding="utf-8")
    assert "notificationAdminBox" in admin and "مدیریت اعلان‌ها" in admin
    assert "notificationBell" in user and "notificationCenter" in user
    assert "notification-system.css" in admin and "notification-system.css" in user
    assert "notification-system.js" in admin and "notification-system.js" in user


def test_frontend_never_renders_notification_content_as_html():
    javascript = (ROOT / "app/static/js/notification-system.js").read_text(encoding="utf-8")
    assert ".innerHTML" not in javascript
    assert "textContent" in javascript
    assert "setInterval(refreshCount,60000)" in javascript


def test_web_push_service_worker_and_api_are_integrated():
    worker = (ROOT / "app/static/js/hastama-sw.js").read_text(encoding="utf-8")
    javascript = (ROOT / "app/static/js/notification-system.js").read_text(encoding="utf-8")
    routes = (ROOT / "app/api/routes/notifications.py").read_text(encoding="utf-8")
    schema = (ROOT / "database/notifications.sql").read_text(encoding="utf-8")
    assert "addEventListener('push'" in worker
    assert "showNotification" in worker
    assert "clients.matchAll" in worker
    assert "registerWebPush" in javascript
    assert "/api/push/subscribe" in routes
    assert "/api/push/status" in routes
    assert "CREATE TABLE dbo.push_subscriptions" in schema
    assert "pywebpush" in (ROOT / "pyproject.toml").read_text(encoding="utf-8")


def test_browser_notifications_use_the_durable_inbox_only():
    javascript = (ROOT / "app/static/js/notification-system.js").read_text(encoding="utf-8")
    routes = (ROOT / "app/api/routes/notifications.py").read_text(encoding="utf-8")
    assert "new EventSource('/api/notifications/stream')" in javascript
    assert "startAdminRequestStream" not in javascript
    assert "requestLabels" not in javascript
    assert "last-event-id" in routes


def test_request_domains_publish_to_the_notification_boundary():
    main = (ROOT / "app/main.py").read_text(encoding="utf-8")
    ticketing = (ROOT / "app/services/ticketing.py").read_text(encoding="utf-8")
    assert "publish_system_notification_to_admins" in main
    assert "_notify_requester_status('mrkhc_table'" in main
    assert "_notify_requester_status('ezafe_table'" in main
    assert "_notify_requester_status('totalpass_table'" in main
    assert "publish_system_notification(" in ticketing
