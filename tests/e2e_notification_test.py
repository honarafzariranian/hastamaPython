"""Runtime-oriented regression tests for the durable in-panel notification APIs."""
from app.api.routes import notifications
from starlette.requests import Request


def request_with_session(session):
    return Request({"type": "http", "method": "GET", "path": "/", "headers": [], "session": session})


def test_notification_stream_requires_authenticated_session():
    try:
        notifications.notification_stream(request_with_session({}))
    except Exception as exc:
        assert exc.status_code == 401
    else:
        raise AssertionError("notification stream was exposed without authentication")


def test_admin_stream_requires_admin_session():
    try:
        notifications.admin_request_stream(request_with_session({"username": "alice", "is_admin": False}))
    except Exception as exc:
        assert exc.status_code == 403
    else:
        raise AssertionError("admin notification stream was exposed to a normal user")


def test_internal_action_url_remains_same_origin():
    try:
        notifications.NotificationInput(title="Hi", content="Body", action_url="https://evil.example")
    except Exception as exc:
        assert "پیوند داخلی" in str(exc)
    else:
        raise AssertionError("external notification action URL was accepted")
