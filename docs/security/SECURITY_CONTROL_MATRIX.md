# Security Control Matrix — Hastama

Status legend: **IMPLEMENTED** (code present and tested in this assessment),
**IMPLEMENTED/UNTESTED** (code present, requires the live environment),
**PARTIAL**, **MANUAL** (operational/DBA task), **NOT PRESENT**.

| # | Control | Status | Implementation | Verification |
|---|---|---|---|---|
| C-01 | Password hashing — bcrypt (cost 12) | IMPLEMENTED | `app/core/password_utils.py:19` | `TestPasswordStorage::test_hash_generation_uses_bcrypt` |
| C-02 | Legacy SHA-512 / plaintext still verify, never written | IMPLEMENTED | `password_utils.verify_password` branch order | `test_legacy_plaintext_and_sha512_rows_still_authenticate` |
| C-03 | Password policy (≥8, upper, lower, digit, symbol, ≤128) | IMPLEMENTED | `password_utils.validate_password_policy` | read + `/reset_password` path |
| C-04 | Password migration tool for legacy rows | IMPLEMENTED/MANUAL | `tools/migrate_passwords.py` (idempotent, `--dry-run`) | needs DBA run — M-2 |
| C-05 | Generic authentication failure message | IMPLEMENTED | `auth.py` login + forgot-password | `test_unknown_account_gets_the_same_generic_message` |
| C-06 | Login throttling (15/600 s per IP, per-account counter) | IMPLEMENTED | `auth.py:109-110,346,428` | `test_repeated_failures_from_one_ip_are_throttled` |
| C-07 | IP source cannot be spoofed via X-Forwarded-For | IMPLEMENTED | `app/core/net.py client_ip` | `test_spoofed_forwarded_header_cannot_bypass_the_ip_limit` |
| C-08 | Captcha on login and password reset | IMPLEMENTED | `app/services/captcha.py` (`secrets.choice`, image noise) | endpoint probes |
| C-09 | Session id in a signed cookie + server-side registry | IMPLEMENTED | `app/core/sessions.py`, `_SessionRegistryMiddleware` | `TestSessionRevocation` |
| C-10 | Session fixation defence (session cleared at login) | IMPLEMENTED | `auth.py` login | source + regression test |
| C-11 | Session revocation (logout, password reset, admin action) | IMPLEMENTED | `sessions.revoke_session/revoke_user_sessions` | `test_revoke_user_sessions_targets_every_active_row` |
| C-12 | Cookie flags: HttpOnly, SameSite=Lax, Secure / HTTPS-only | IMPLEMENTED | `SessionMiddleware(https_only=True, same_site="lax")` + `_harden_cookies` on TLS | `test_session_middleware_uses_https_only`; live `Set-Cookie: … Secure` |
| C-13 | Idle/absolute session lifetime (default 8 h, configurable) | IMPLEMENTED | `SESSION_MAX_AGE_SECONDS` | read |
| C-14 | CSRF: session-bound double-submit token on mutating methods | IMPLEMENTED | `_CSRFMiddleware` | `TestCSRFMiddleware` (14 tests) |
| C-15 | CSRF exemptions narrow + Origin-checked + documented | IMPLEMENTED | `CSRF_EXEMPT_PREFIXES` | `TestCsrfExemptionIntegration` (10 tests) |
| C-16 | Server-side authorization only (no client-supplied role) | IMPLEMENTED | `get_is_admin_from_session`, `_master_admin` | `TestMasterAdminAuthorization` |
| C-17 | Master-admin control plane separated from admin | IMPLEMENTED | `master_admin._master_admin` (+ username allow-list) | `test_regular_admin_is_rejected` |
| C-18 | Ownership checks on tickets/notifications/attendance | IMPLEMENTED | SQL WHERE clauses (`_ticket_accessible_row`, `_owned_update`, `_attendance_actor`) | service tests + code review |
| C-19 | Employee report endpoints admin-only | IMPLEMENTED | `main.py` `/get_hourly_pass_report`, `/get_overtime_report`, **and** `GET /overtime_report` (HTML) | `TestEmployeeReportAuthorization`; live anon → 401 |
| C-20 | Output escaping for DB values in JS renderers | IMPLEMENTED | `esc()` in `admin.js`/`master-admin.js`, `dom-escape.js` + report scripts | `TestTemplateOutputEscaping`, `TestStoredXssRendering` |
| C-21 | Markup rejected on free-text write paths | IMPLEMENTED | `validation.reject_markup`, used by `/submit_leave`, `/submit_overtime` | `test_free_text_write_paths_reject_markup` |
| C-22 | CSP without `object-src`, `base-uri 'none'`, `frame-ancestors 'self'` | PARTIAL | `_SecurityHeadersMiddleware` | header read; `'unsafe-inline'` still required |
| C-23 | Security headers (HSTS, nosniff, referrer, permissions, COOP/CORP) | IMPLEMENTED | `_SecurityHeadersMiddleware` + `Caddyfile` | header read + Caddy config |
| C-24 | Parameterised SQL everywhere (identifier allow-lists) | IMPLEMENTED | see `docs/security/SQL_INJECTION_REVIEW.md` | AST scan of 52 sites |
| C-25 | Explicit column lists (no `SELECT *` on credential tables) | IMPLEMENTED | `fetch_user_for_login`, user list, registration read-back | `test_user_list_never_selects_credential_columns` |
| C-26 | Uploads: server-generated names, type/size caps, private dir | IMPLEMENTED | `main.py` profile image; `ticketing.store_private_attachment`; `call_system` slides | service tests |
| C-27 | Path traversal containment | IMPLEMENTED | sanitised username + `abspath` check; random storage names | `test_private_attachment_uses_safe_random_storage_name` |
| C-28 | Bridge secret mandatory, fail-closed, constant-time | IMPLEMENTED | `araz_api.py:37,430,444` | `TestBridgeSync` |
| C-29 | Device batch caps / validation | IMPLEMENTED | `araz_api.bridge_sync` (`MAX_BATCH`) | `test_batch_size_is_capped` |
| C-30 | PDF generation hardened (CVE-2025-26240) | IMPLEMENTED | `main.download_pdf` (from_file, no JS, no local files) | `TestPdfGenerationHardening` |
| C-31 | WebSocket origin validation (TV display) | IMPLEMENTED | `call_system._ws_origin_allowed` | `TestCallSystemAuthorization` |
| C-32 | Kiosk write endpoints origin-checked | IMPLEMENTED | `_guard_kiosk_write` + in-handler `origin_is_same_site` on every CSRF-exempt mutation | `test_kiosk_write_guard_rejects_cross_site_origin`; live cross-site POST → 403 |
| C-33 | Audit logging of security events | IMPLEMENTED | `app/services/audit.py` (login, logout, CSRF, session, reset, admin actions) | code + table schema |
| C-34 | Error messages do not leak internals | IMPLEMENTED | `_safe_error_message` (logger defined), JSON error shapes | `TestInternalErrorHandling` |
| C-35 | API docs disabled in production | IMPLEMENTED | `HASTAMA_ENABLE_DOCS` gate on `/docs`, `/redoc`, `/openapi.json` | live probe: `/docs` `/redoc` `/openapi.json` → 404 |
| C-36 | Secrets fail closed when unset | IMPLEMENTED | session key, HMAC key, bridge secret, Access password | `TestBridgeSync`, recovery tests |
| C-37 | Secrets documented for deployment | IMPLEMENTED | `.env.example` with generation commands | file read |
| C-38 | Reverse proxy hardening (TLS, body cap, timeouts, header stripping, access log) | IMPLEMENTED | `Caddyfile` | file read |
| C-39 | Trusted-proxy model for forwarded headers | IMPLEMENTED | `app/core/net.py`, `TRUSTED_PROXY_IPS` | `TestClientIP` (8 tests) |
| C-40 | Rate limiting at the reverse proxy | NOT PRESENT | `caddy-ratelimit` plugin not available offline (commented block) | application-level limits are primary |
| C-41 | Backup, restore and off-host log retention | NOT PRESENT | — | see RR-01 |
| C-42 | Antivirus / content scanning for uploads | NOT PRESENT | — | offline environment; compensating: type caps + private storage |
| C-43 | MFA for administrative accounts | NOT PRESENT | — | see RR-03 |
| C-44 | Dependency vulnerability management | PARTIAL | `pyproject.toml` + `uv.lock`; `pip-audit` run in this assessment; one unfixed High (pdfkit) | `TEST_EVIDENCE_SUMMARY.md` |
| C-45 | Secret scanning in CI | NOT PRESENT | — | manual scan performed; recommend pre-commit hook |
| C-46 | DB least privilege (app account, no DDL) | MANUAL | app runs schema migrations at startup (`ALTER TABLE` when a column is missing) | DBA task D-1 |
| C-47 | Data-at-rest protection for the SQL Server and Access files | MANUAL | — | DBA task D-2 |
| C-48 | Repo hygiene (no data exports, no vendor binaries) | PARTIAL | `latest.sql` untracked + ignore tightened; `tempexport.py` removed; MDB/EXE remain | RR-02 / RR-19 |
| C-49 | Login-only root (`/` → `/login`) | IMPLEMENTED | `app/main.py` `landing_page` → `RedirectResponse(301)`; landing assets deleted | `TestLoginOnlyRoot`; live `/` → 301 `/login` after redeploy (was 302 during Phase 8) |
| C-50 | Public docs/sitemap do not advertise the app root as content | IMPLEMENTED | `robots.txt` `Disallow: /`; sitemap lists only `/login` | `test_robots_disallow_root_and_sitemap_has_no_landing`; live match |
| C-51 | Call pages closed to direct entry | IMPLEMENTED | `_require_call_page_access` (master-admin + dashboard referer) | `TestCallPageEntryGate`; live `/call-display` → 303 login |
| C-52 | Upload size enforced by bounded read (no full-buffer DoS) | IMPLEMENTED | profile image + slide upload stream with a hard cap | `test_profile_upload_is_size_bounded_before_buffer` |
| C-53 | Slide upload magic-byte + Origin check | IMPLEMENTED | `upload_slide` (`origin_is_same_site` + extension↔magic) | source + live cross-site → 403 |
