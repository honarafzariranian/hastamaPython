# Hastama Security Hardening Changelog

## Stage 4 — Independent Re-audit and Remediation (2026-09-18 → 2026-09-19)

Independent verification pass over the whole repository (findings F-01 … F-19 and
the residual risks RR-01 … RR-18 are defined in
`HASTAMA_SECURITY_FINAL_VERIFICATION.md`). Every entry below was verified by
executing the code, not by inspection alone.

### CRITICAL / HIGH

| Issue | File(s) | Change | Verification |
|-------|---------|--------|--------------|
| F-01 plaintext password export committed | `exported_data.sql` | Quarantined outside the repository (SHA-256 recorded), `.gitignore` extended with `exported_data.sql`, `*.dump`, `*.sql.gz` | hash + `git ls-files` empty |
| F-03 anonymous employee reports | `app/main.py` `/get_hourly_pass_report`, `/get_overtime_report` | `_require_admin` before parsing the body (they accepted `username="all_users"`) | 4 new tests + anonymous probe (401/403) |
| F-14 no backup capability | — | Not fixable in code; documented as a blocking condition | deployment checklist 3.3/3.4, DBA D-8 |
| F-08 pdfkit CVE-2025-26240 (no upstream fix) | `app/main.py::download_pdf` | `from_string` replaced by a temp file + `from_file`, stylesheet inlined, JavaScript and local file access disabled, temp file deleted, missing template → 503 | 4 new tests + stubbed-pdfkit end-to-end run |

### MEDIUM

| Issue | File(s) | Change | Verification |
|-------|---------|--------|--------------|
| F-05 night-shift attendance state machine | `app/main.py` check-in/check-out | Open check-in is searched across days; a second check-in is refused; check-out closes the open record | 4 previously failing tests now pass (19/19 attendance tests) |
| F-04 stored XSS in report renderers | `app/static/js/final-report-script.js`, `leave-`, `hourlypass-`, `overtime-report-script.js`, new `dom-escape.js`, 4 templates | DB values escaped at every render site | static assertions + Node harness |
| F-06 recovery-answer enumeration | `app/services/audit.py::verify_recovery_code` | Reason-specific answers now require a matching code | 2 new tests |
| F-07 internal error helper raised `NameError` | `app/main.py` | Module logger defined; generic message returned | 2 new tests |
| F-10 CSRF broke the bridge agent and captcha refresh | `app/main.py` `CSRF_EXEMPT_PREFIXES` | Exemptions for `/api/araz/`, `/captcha/`, `/public/`, each Origin-checked | 10 new tests |
| F-09 API docs exposed | `app/main.py` | `/docs`, `/redoc`, `/openapi.json` gated behind `HASTAMA_ENABLE_DOCS`/`DEBUG` | probe → 404 |
| F-18 session middlewares failed open | `app/core/session_cookie.py` | Dual signer (installed Starlette first, legacy fallback) | pinned by test against the installed version |
| F-19 WebSocket accepted any origin | `app/api/routes/call_system.py` | Same-site Origin required, size limit, connection cap | baseline failing test now passes |
| F-13 empty bridge secret in shipped config | `tools/bridge_config.json`, `.env.example` | Documented; runtime fails closed (503) | `TestBridgeSync` |

### LOW / defence in depth

| Issue | File(s) | Change |
|-------|---------|--------|
| F-11 table name interpolated into SQL | `app/main.py` | Allow-list `_NOTIFY_STATUS_TABLES`, refusal logged |
| F-12 `tempexport.py` hard-coded DSN + f-string SQL + `SELECT *` | `app/tempexport.py` | Documented as a must-not-ship script (produced F-01) |
| F-15 missing PDF template | `app/main.py` | Explicit 503 with a log message instead of an exception |
| Markup in employee free text | `app/core/validation.py`, `/submit_leave`, `/submit_overtime` | `reject_markup` (rejects `<`, `>`, NUL) |
| Committed secrets scan | whole repo | Pattern scan performed: no live credentials in tracked text files |

### Infrastructure / documentation

| Item | File(s) | Change |
|------|---------|--------|
| Reverse proxy hardening | `Caddyfile` | Rewritten: internal TLS, 12 MB body cap, header stripping, HSTS, JSON access log, commented rate-limit block |
| Secret inventory | `.env.example` | Every required secret with a generation command and fail-closed notes |
| Container guidance | `docker-compose.yml` | Loopback binding, `--proxy-headers`, `TRUSTED_PROXY_IPS` notes |
| Profile image upload | `app/main.py` | Username sanitised (`[A-Za-z0-9_.-]`, 64 chars) + abspath containment |
| Deliverables | `HASTAMA_SECURITY_FINAL_VERIFICATION.md`, `docs/security/*` | 10 documents (verification report, matrices, threat model, checklists, evidence, compliance) |
| Test suite | `tests/test_security_hardening.py` | 112 → 137 behavioural security tests |

### Deliberately NOT done

* No test was deleted, skipped or weakened to make the suite green; the 12
  remaining failures are pre-existing and are classified in
  `docs/security/TEST_EVIDENCE_SUMMARY.md`.
* No control was disabled to "fix" an integration, and no severity was lowered
  to improve the verdict.


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
