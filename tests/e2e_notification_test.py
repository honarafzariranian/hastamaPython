"""Runtime-oriented notification tests using mocked SQL Server connections.

These tests deliberately do not claim Chrome or Windows delivery; those require a
real browser, push subscription, and LAN client.
"""
from unittest.mock import Mock, patch

from app.api.routes import notifications
from starlette.requests import Request


def request_with_session(session):
    return Request({"type": "http", "method": "GET", "path": "/", "headers": [], "session": session})


def test_push_config_requires_authenticated_session():
    try:
        notifications.push_config(request_with_session({}))
    except Exception as exc:
        assert exc.status_code == 401
    else:
        raise AssertionError("push config was exposed without authentication")


def test_subscription_payload_rejects_missing_encryption_keys():
    payload = notifications.PushSubscriptionInput(endpoint="https://push.example/sub", keys={})
    with patch.object(notifications, "get_connection"):
        try:
            notifications.push_subscribe(payload, request_with_session({"username": "alice"}))
        except Exception as exc:
            assert exc.status_code == 422
        else:
            raise AssertionError("invalid push keys were accepted")


def test_action_url_is_internal_only():
    try:
        notifications.NotificationInput(title="Hi", content="Body", action_url="https://evil.example")
    except Exception:
        return
    raise AssertionError("external notification action URL was accepted")


def test_admin_mark_all_read_updates_only_current_admin_inbox():
    cursor = Mock()
    cursor.rowcount = 3
    conn = Mock()
    conn.cursor.return_value = cursor

    with patch.object(notifications, "get_connection", return_value=conn), patch.object(notifications, "_ensure_schema"):
        result = notifications.admin_mark_all_read(
            request_with_session({"username": "admin", "is_admin": True})
        )

    assert result == {"success": True, "updated": 3}
    query = cursor.execute.call_args.args[0]
    assert "RTRIM(un.username)=?" in query
    assert cursor.execute.call_args.args[1] == ("admin",)
    conn.commit.assert_called_once()
    conn.close.assert_called_once()


def test_admin_mark_all_read_requires_admin_role():
    try:
        notifications.admin_mark_all_read(request_with_session({"username": "alice", "is_admin": False}))
    except Exception as exc:
        assert exc.status_code == 403
    else:
        raise AssertionError("non-admin user gained admin mark-all access")


def test_expired_push_response_is_translated_to_endpoint_cleanup():
    class ExpiredError(Exception):
        response = Mock(status_code=410)

    with patch("pywebpush.webpush", side_effect=ExpiredError()):
        expired = notifications.send_to_subscriptions(
            [{"endpoint": "https://push.example/old", "p256dh": "p", "auth": "a"}],
            {"title": "x"},
        )
    assert expired == ["https://push.example/old"]
