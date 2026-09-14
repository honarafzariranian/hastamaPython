# Hastama Security Hardening Changelog

## Stage 3 — Final Hardening (2026-09-14)

### CRITICAL Fixes

| Date | Issue | Root Cause | File(s) | Change | Impact | Verification |
|------|-------|-----------|---------|--------|--------|--------------|
| 2026-09-14 | HST-NEW-008: `update_user` stores plaintext password | Admin password update wrote plaintext to `password` column | `app/main.py:2212-2222` | Changed to store only bcrypt hash; clears `password` column | Eliminates last plaintext password storage path | Code review confirms `password = ''` in update path |
| 2026-09-14 | HST-NEW-002: Araz endpoints unauthenticated | No auth check on 7 Araz device endpoints | `app/api/routes/araz_api.py` | Added `_require_admin()` to all endpoints | Prevents unauthenticated device control and attendance injection | Route audit confirms auth on all endpoints |
| 2026-09-14 | HST-SEC-001: Legacy plaintext passwords | No migration path for existing plaintext passwords | `tools/migrate_passwords.py` | Created migration script with `--dry-run` support | Enables safe migration of legacy passwords | Script runs without errors |

### HIGH Fixes

| Date | Issue | Root Cause | File(s) | Change | Impact | Verification |
|------|-------|-----------|---------|--------|--------|--------------|
| 2026-09-14 | HST-NEW-001: Logout session flag leakage | `session.pop("username")` left `is_admin`/`is_master_admin` | `app/main.py:4180-4183` | Changed to `session.clear()` | Prevents session flag leakage after logout | Code review confirms `session.clear()` |
| 2026-09-14 | HST-NEW-003: Call system guest access | `_actor` returned `"guest"` for unauthenticated requests | `app/api/routes/call_system.py` | Added session auth to DELETE/PUT endpoints; slide upload requires auth | Prevents unauthenticated state changes | Code review confirms auth checks |
| 2026-09-14 | HST-NEW-004: HMAC secret hardcoded fallback | `_HMAC_SECRET` fell back to known default | `app/services/audit.py:264` | Empty fallback with warning log | Prevents recovery code forgery | Code review confirms env var usage |
| 2026-09-14 | HST-NEW-008: Plaintext in update_user | Same as CRITICAL above | `app/main.py` | Same as above | Same as above | Same as above |

### MEDIUM Fixes

| Date | Issue | Root Cause | File(s) | Change | Impact | Verification |
|------|-------|-----------|---------|--------|--------|--------------|
| 2026-09-14 | HST-NEW-005: Registration user enumeration | `GET /active-users` public | `app/api/routes/registration.py` | Added admin auth | Prevents user enumeration | Code review confirms auth check |
| 2026-09-14 | HST-NEW-006: Session timeout missing | No `max_age` on SessionMiddleware | `app/main.py:57` | Added `max_age=28800` (8 hours) | Enforces session lifetime | Code review confirms parameter |
| 2026-09-14 | HST-NEW-007: MASTER_ADMIN hardcoded | Username hardcoded in source | `app/api/routes/auth.py:27-29` | Now reads from `MASTER_ADMIN_USERNAMES` env var | Configurable admin list | Code review confirms env var |
| 2026-09-14 | Overtime report / PDF no auth | Endpoints had no auth check | `app/main.py:2946,4160` | Added `_require_auth` | Prevents unauthenticated data access | Code review confirms auth |
| 2026-09-14 | `.env.example` missing ARAZ_ACCESS_PASSWORD | Operational guidance incomplete | `.env.example` | Added `ARAZ_ACCESS_PASSWORD=` entry | Operational clarity | File verified |

### Infrastructure

| Date | Issue | File(s) | Change |
|------|-------|---------|--------|
| 2026-09-14 | Password migration script needed | `tools/migrate_passwords.py` | Created with `--dry-run`, idempotent, no password logging |
| 2026-09-14 | Security regression tests needed | `tests/test_security_regressions.py` | Created 8 test classes, 30+ test cases |
| 2026-09-14 | Final audit report needed | `HASTAMA_AFTA_SECURITY_AUDIT_FINAL.md` | Created comprehensive 30-section report |

---

## Stage 2 — First Remediation (2026-09-14)

### Changes

| Issue | File(s) | Change |
|-------|---------|--------|
| Timing attacks on password comparison | `app/core/password_utils.py` | Added `hmac.compare_digest()` for SHA-512 and plaintext fallback |
| Plaintext fallback in password reset | `app/api/routes/auth.py:382-399` | Reset flow attempts ALTER TABLE, stores hash only |
| SECRET_KEY=secret | `.env` | Rotated to 64-char random hex |
| DEBUG=True | `.env` | Set to False |
| Missing auth on 7 endpoints | `app/main.py` | Added `_require_admin()` to add_user, update_user, sabt_hozoor, fetch_user_data, get_user_info_final_report_page, get_shifts, get_hozoor |
| IDOR on get_user_info_report | `app/main.py` | Added ownership check |
| 34+ str(e) in responses | main.py, master_admin.py, registration.py, audit.py | Replaced with generic messages |
| Session not rotated on login | `app/api/routes/auth.py` | Added `session.clear()` before set |
| No CSRF protection | `app/main.py`, `app/static/js/hastama-ux.js` | Added `_CSRFMiddleware` + global fetch interceptor |
| File upload magic bytes | `app/main.py` | Added JPEG/PNG/GIF/WEBP validation |
| WebSocket no auth | `app/api/routes/call_system.py` | Added session check |
| Docker root user | `Dockerfile` | Added non-root user + healthcheck |
| Caddy infinite timeouts | `Caddyfile` | Set to 300s |
| bcrypt not in deps | `pyproject.toml` | Added `bcrypt>=4.0.0` |
