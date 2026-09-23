"""Security regression tests for Hastama.

Verifies that critical security fixes from Stage 1-3 remain in place.
These tests do NOT require database connectivity — they verify code-level security controls.
"""
import os

import pytest


class TestPasswordSecurity:
    """Verify password hashing and storage security."""

    def test_plaintext_not_stored_in_update_user(self):
        """update_user must NOT store plaintext password in password column."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        assert "password = ''" in source or 'password = ""' in source, \
            "update_user should clear password column, not store plaintext"

    def test_verify_password_uses_bcrypt_checkpw(self):
        """bcrypt passwords must be verified with bcrypt.checkpw, not hmac.compare_digest."""
        import app.core.password_utils as pu
        source = open(pu.__file__, encoding="utf-8").read()
        assert "bcrypt.checkpw" in source, "verify_password must use bcrypt.checkpw for bcrypt hashes"

    def test_verify_password_uses_hmac_for_legacy(self):
        """Legacy SHA-512/plaintext comparisons must use hmac.compare_digest."""
        import app.core.password_utils as pu
        source = open(pu.__file__, encoding="utf-8").read()
        assert "hmac.compare_digest" in source, "verify_password must use hmac.compare_digest for legacy formats"

    def test_password_hash_in_dependencies(self):
        """bcrypt must be listed as a dependency."""
        with open("pyproject.toml", encoding="utf-8") as f:
            content = f.read()
        assert "bcrypt" in content, "bcrypt must be in pyproject.toml dependencies"


class TestAuthorization:
    """Verify critical endpoints require authentication."""

    def _read_main_source(self):
        import app.main as main_mod
        return open(main_mod.__file__, encoding="utf-8").read()

    def test_add_user_requires_admin(self):
        source = self._read_main_source()
        idx = source.find('def add_user(')
        assert idx > 0, "add_user function not found"
        segment = source[idx:idx+1500]
        assert "_require_admin" in segment, "add_user must call _require_admin"

    def test_update_user_requires_admin(self):
        source = self._read_main_source()
        idx = source.find('def update_user(')
        assert idx > 0, "update_user function not found"
        segment = source[idx:idx+500]
        assert "_require_admin" in segment, "update_user must call _require_admin"

    def test_sabt_hozoor_requires_admin(self):
        source = self._read_main_source()
        idx = source.find('def sabt_hozoor(')
        assert idx > 0, "sabt_hozoor function not found"
        segment = source[idx:idx+500]
        assert "_require_admin" in segment, "sabt_hozoor must call _require_admin"

    def test_fetch_user_data_requires_admin(self):
        source = self._read_main_source()
        idx = source.find('def fetch_user_data(')
        assert idx > 0, "fetch_user_data function not found"
        segment = source[idx:idx+500]
        assert "_require_admin" in segment, "fetch_user_data must call _require_admin"

    def test_get_hozoor_requires_admin(self):
        source = self._read_main_source()
        idx = source.find('def get_hozoor(')
        assert idx > 0, "get_hozoor function not found"
        segment = source[idx:idx+500]
        assert "_require_admin" in segment, "get_hozoor must call _require_admin"

    def test_overtime_report_requires_auth(self):
        source = self._read_main_source()
        idx = source.find('def overtime_report(')
        assert idx > 0, "overtime_report function not found"
        segment = source[idx:idx+500]
        assert "_require_auth" in segment or "_require_admin" in segment, \
            "overtime_report must require authentication"

    def test_download_pdf_requires_auth(self):
        source = self._read_main_source()
        idx = source.find('def download_pdf(')
        assert idx > 0, "download_pdf function not found"
        segment = source[idx:idx+500]
        assert "_require_auth" in segment or "_require_admin" in segment, \
            "download_pdf must require authentication"


class TestSessionSecurity:
    """Verify session handling security."""

    def test_session_rotation_on_login(self):
        """Login must clear session before setting new data."""
        import app.api.routes.auth as auth_mod
        source = open(auth_mod.__file__, encoding="utf-8").read()
        assert "session.clear()" in source or "session.pop" in source, \
            "Login must rotate session"

    def test_logout_clears_all_session_data(self):
        """Logout must clear entire session, not just username."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        idx = source.find('def logout(')
        assert idx > 0, "logout function not found"
        segment = source[idx:idx+300]
        assert "session.clear()" in segment, \
            "Logout must call session.clear(), not just pop('username')"

    def test_session_has_max_age(self):
        """SessionMiddleware must have max_age configured."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        assert "max_age" in source, "SessionMiddleware must have max_age parameter"


class TestCSRFProtection:
    """Verify CSRF middleware exists and is functional."""

    def test_csrf_middleware_exists(self):
        """_CSRFMiddleware must be defined in main.py."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        assert "class _CSRFMiddleware" in source, "_CSRFMiddleware class not found"

    def test_csrf_validates_header(self):
        """CSRF middleware must validate X-CSRF-Token header."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        assert "x-csrf-token" in source.lower() or "X-CSRF-Token" in source, \
            "CSRF middleware must check X-CSRF-Token header"

    def test_csrf_interceptor_in_ux(self):
        """hastama-ux.js must have global fetch interceptor for CSRF."""
        with open("app/static/js/hastama-ux.js", encoding="utf-8") as f:
            source = f.read()
        assert "X-CSRF-Token" in source, "hastama-ux.js must inject X-CSRF-Token"
        assert "getCsrfToken" in source, "hastama-ux.js must have getCsrfToken helper"


class TestErrorHandling:
    """Verify error messages don't leak sensitive information."""

    def test_no_str_e_in_main_responses(self):
        """main.py must not return str(e) to clients."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        lines = source.split("\n")
        violations = []
        for i, line in enumerate(lines, 1):
            stripped = line.strip()
            if ("return" in stripped or "JSONResponse" in stripped) and "str(e)" in stripped:
                violations.append(f"Line {i}: {stripped[:80]}")
        assert not violations, f"str(e) found in responses: {violations}"


class TestSecretManagement:
    """Verify secrets are not hardcoded."""

    def test_no_hardcoded_meyer_perko(self):
        """meyer#perko must not appear in any Python source file."""
        for root, dirs, files in os.walk("app"):
            for f in files:
                if f.endswith(".py"):
                    path = os.path.join(root, f)
                    with open(path, encoding="utf-8", errors="ignore") as fh:
                        if "meyer#perko" in fh.read():
                            pytest.fail(f"Hardcoded credential found in {path}")

    def test_master_admin_from_env(self):
        """MASTER_ADMIN_USERNAMES should read from environment."""
        import app.api.routes.auth as auth_mod
        source = open(auth_mod.__file__, encoding="utf-8").read()
        assert "os" in source and "environ" in source, \
            "MASTER_ADMIN_USERNAMES should read from environment variable"

    def test_hmac_secret_from_env(self):
        """_HMAC_SECRET should read from environment."""
        import app.services.audit as audit_mod
        source = open(audit_mod.__file__, encoding="utf-8").read()
        assert "HASTAMA_HMAC_SECRET" in source, \
            "_HMAC_SECRET should be configurable via HASTAMA_HMAC_SECRET env var"


class TestFileUpload:
    """Verify file upload security."""

    def test_magic_byte_validation(self):
        """File upload must validate magic bytes."""
        import app.main as main_mod
        source = open(main_mod.__file__, encoding="utf-8").read()
        assert "FF D8 FF" in source or "ff d8 ff" in source or "b'\\xff\\xd8\\xff'" in source or "b\"\\xff\\xd8\\xff\"" in source or "JPEG" in source, \
            "File upload must validate JPEG magic bytes"
        assert "89 50 4E 47" in source or "PNG" in source or "b'\\x89PNG'" in source, \
            "File upload must validate PNG magic bytes"


class TestWebSocketSecurity:
    """Verify WebSocket authentication."""

    def test_websocket_requires_session(self):
        """WebSocket endpoint must check session."""
        import app.api.routes.call_system as cs_mod
        source = open(cs_mod.__file__, encoding="utf-8").read()
        idx = source.find('call_display_ws')
        if idx > 0:
            segment = source[idx:idx+500]
            assert "session" in segment.lower() or "username" in segment.lower(), \
                "WebSocket endpoint must validate session"


class TestCallPageEntryGate:
    """/call-management and /call-display: master-admin only, linked from dashboard."""

    @staticmethod
    def _request(referer=None, session=None, host="127.0.0.1"):
        import app.main as main_mod

        class Req:
            def __init__(self):
                self.session = dict(session or {})
                self.headers = {}
                if referer is not None:
                    self.headers["referer"] = referer
                self.url = type("U", (), {"hostname": host, "path": "/call-management"})()

        return main_mod, Req()

    def test_anonymous_is_redirected_to_login(self):
        main_mod, req = self._request()
        resp = main_mod._require_call_page_access(req)
        assert resp.status_code == 303
        assert resp.headers["location"] == "/login"

    def test_regular_admin_is_rejected(self):
        main_mod, req = self._request(
            referer="http://127.0.0.1:5000/master-admin/dashboard",
            session={"username": "bob", "is_master_admin": False},
        )
        resp = main_mod._require_call_page_access(req)
        assert resp.status_code == 303
        assert resp.headers["location"] == "/admin"

    def test_master_admin_without_referer_is_rejected(self):
        main_mod, req = self._request(session={"username": "ali", "is_master_admin": True})
        resp = main_mod._require_call_page_access(req)
        assert resp.status_code == 303
        assert resp.headers["location"] == "/master-admin/dashboard"

    def test_master_admin_from_dashboard_is_allowed(self):
        main_mod, req = self._request(
            referer="http://127.0.0.1:5000/master-admin/dashboard",
            session={"username": "ali", "is_master_admin": True},
        )
        assert main_mod._require_call_page_access(req) is None

    def test_other_master_admin_section_is_rejected(self):
        main_mod, req = self._request(
            referer="http://127.0.0.1:5000/master-admin/users",
            session={"username": "ali", "is_master_admin": True},
        )
        resp = main_mod._require_call_page_access(req)
        assert resp is not None
        assert resp.headers["location"] == "/master-admin/dashboard"

    def test_self_referer_allows_iframe_and_refresh(self):
        for path in ("/call-display", "/call-management"):
            main_mod, req = self._request(
                referer=f"http://127.0.0.1:5000{path}",
                session={"username": "ali", "is_master_admin": True},
            )
            assert main_mod._require_call_page_access(req) is None, path

    def test_foreign_host_referer_is_rejected(self):
        main_mod, req = self._request(referer="https://evil.example/master-admin/dashboard")
        assert main_mod._call_page_referer_allowed(req) is False

    def test_dashboard_links_do_not_strip_referer(self):
        html = open("app/templates/master-admin.html", encoding="utf-8").read()
        assert 'href="/call-management" target="_blank" rel="noopener"' in html
        assert 'href="/call-display" target="_blank" rel="noopener"' in html
        assert 'href="/call-management" target="_blank" rel="noopener noreferrer"' not in html
        assert 'href="/call-display" target="_blank" rel="noopener noreferrer"' not in html

    def test_routes_call_the_gate(self):
        source = open("app/main.py", encoding="utf-8").read()
        assert "_require_call_page_access" in source
        for fn in ("async def call_display", "async def call_management"):
            idx = source.find(fn)
            assert idx > 0, fn
            segment = source[idx: idx + 500]
            assert "_require_call_page_access" in segment, fn
