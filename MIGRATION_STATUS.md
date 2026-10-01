# MIGRATION_STATUS

FastAPI/Jinja2/vanilla-JS → **Laravel (PHP 8.x) + Vue 3 (Vite) + SQL Server**, Windows,
Cloudflare Tunnel, `hastama.ir`.

**Legend:** `Pending` · `In Progress` · `Migrated` · `Tested` · `Verified`
`Verified` requires the behaviour to have been run against the Laravel application and recorded in
the regression table. Row counts below are honest: nothing is migrated yet.

Last updated: **2026-09-30** (Phase 5 in progress — the user-panel and control-centre **read
surface** is ported and verified against the live database; the remaining route groups of
`docs/migration/ROUTE_INVENTORY.md` are still pending).

---

## Phase status

| Phase | Scope | Status | Evidence |
|---|---|---|---|
| 1 | Audit and document the existing application | **Complete** | `MIGRATION_AUDIT.md` + 7 inventories in `docs/migration/` |
| 2 | Laravel + Vue project skeleton, SQL Server connection, env handling | **Complete** | `ARCHITECTURE.md`, `MIGRATION_AUDIT.md` §35 |
| 3 | Models, relationships, safe migrations, schema verification | **Complete** | 43 models verified against the live schema (`hastama:schema-check` exit 0); `DATABASE.md`, `TESTING.md` |
| 4 | Authentication: login, logout, sessions, roles, password handling, password reset | **Complete** | Live HTTP smoke test of all 8 endpoints; `AuthSurfaceTest` (40) + `AuthDatabaseTest` (9, opt-in); `php artisan route:list` shows the web group using the CSRF bridge |
| 5 | REST API: every route in `docs/migration/ROUTE_INVENTORY.md` | **In Progress** | 17 read routes live (`php artisan route:list`); `LegacyReadDatabaseTest` (20, opt-in, against the real `userDB`); 554 tests green with `HASTAMA_DB_TESTS=1`; six bodies byte-identical to the running server — see “Phase 5” below |
| 6 | Vue foundation: shell, router, Pinia, API layer, error/loading states, RTL, theme | Pending | — |
| 7 | Public pages | Pending | — |
| 8 | User panel | Pending | — |
| 9 | Admin panel | Pending | — |
| 10 | Attendance | Pending | — |
| 11 | Araz integration | Pending | — |
| 12 | Notifications, SSE, WebSocket, background jobs, scheduler | Pending | — |
| 13 | Call management and call display | Pending | — |
| 14 | Printing: labels, receipts, raw print pipeline | Pending | — |
| 15 | Security review | Pending | — |
| 16 | Testing: unit, feature, API, frontend, integration, regression | Pending | — |
| 17 | Production: build, Windows tasks, Cloudflare, LAN | Pending | — |

---

## Feature status

### Identity and access

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Login (username + password + optional CAPTCHA) | **Verified** | Pending (Phase 6) | **Tested** | **Verified** |
| Legacy plaintext verification + hash upgrade | **Verified** | N/A | **Tested** | **Verified** |
| Session management (rotation, idle/absolute timeout, revocation) | **Verified** | Pending (Phase 6) | **Tested** | **Verified** |
| Authorization (admin / master admin / ownership) | **Verified** | Pending (Phase 6) | **Tested** | **Verified** — `owner` scoping arrives with the endpoints that need it |
| Password reset (request → approval → 8-char code) | **Verified** (request + verify + reset); admin approval is Phase 9 | Pending (Phase 6) | **Tested** | **Verified** for the public half |
| CAPTCHA (6 characters, GD-rendered, 3 attempts, DB-configured TTL) | **Verified** | Pending (Phase 6) | **Tested** | **Verified** |
| CSRF, both schemes (Laravel `XSRF-TOKEN` and legacy `csrf_token` cookie + header) | **Verified** | N/A | **Tested** | **Verified** end to end against the running server |
| Self-registration with admin approval | Pending | Pending | Pending | Pending |

### Attendance

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Attendance read (Access `TPrsInOut` + `hozoor` merge) | Pending | Pending | Pending | Pending |
| Check-in / check-out / manual attendance | Pending | Pending | Pending | Pending |
| Live presence summary (ring, zero-threshold overtime) | Pending | Pending | Pending | Pending |
| Shifts / work schedule (`shiftha`) | Pending | Pending | Pending | Pending |

### Requests

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Leave (`mrkhc_table` + triggering `leave_report`) | Pending | Pending | Pending | Pending |
| Hourly pass — `aval` / `beyn` / `akhr` | Pending | Pending | Pending | Pending |
| Overtime requests (`ezafe_table` + totals) | Pending | Pending | Pending | Pending |
| Payroll / Karaneh calculation storage | Pending | Pending | Pending | Pending |

### Ticketing

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Current ticketing (`tickets` / `ticket_messages` / `ticket_events`) | Pending | Pending | Pending | Pending |
| Ticket attachments (authorised download) | Pending | Pending | Pending | Pending |
| Legacy ticketing (`ticket_table` + `Parent_id` threads) | Pending | Pending | Pending | Pending |

### Announcements and notifications

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Announcements / notifications CRUD + scheduling | Pending | Pending | Pending | Pending |
| Per-user read / unread / dismiss / archive | Pending | Pending | Pending | Pending |
| SSE stream + admin stream + polling fallback | Pending | Pending | Pending | Pending |
| Web Push subscriptions | Pending | Pending | Pending | Pending |
| Offline service-worker fallback | Pending | Pending | Pending | Pending |
| Scheduler / due-notification sweep | Pending | N/A | Pending | Pending |

### Call system

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Reception calls + display queue | Pending | Pending | Pending | Pending |
| Numbered tickets (continuous global numbering, «نمونه‌گیری») | Pending | Pending | Pending | Pending |
| Call display (TV, full-screen, real-time) | Pending | Pending | Pending | Pending |
| Display slideshow | Pending | Pending | Pending | Pending |
| WebSocket push to displays | Pending | Pending | Pending | Pending |

### Printing

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Label / numbered-ticket printing (76×105 mm, EPSON TM-T88III Receipt) | Pending | Pending | Pending | Pending |
| Final report printing / PDF | Pending | Pending | Pending | Pending |

### Araz

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Araz Access MDB read (`TPrsInOut`) | Pending | N/A | Pending | Pending |
| Araz device protocol / time sync | Pending | Pending | Pending | Pending |
| `bridge-sync` (HMAC secret, fail-closed) | Pending | N/A | Pending | Pending |
| Card-number mapping (`hozoor_num` ↔ `CardNo`) | Pending | N/A | Pending | Pending |

### Admin panel and control centre

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Admin dashboard | Pending | Pending | Pending | Pending |
| User management | Pending | Pending | Pending | Pending |
| Reports (leave / hourly pass / overtime / payroll / final) | Pending | Pending | Pending | Pending |
| Profile images | Pending | Pending | Pending | Pending |
| Control centre dashboard | **Read** | Pending | Live DB | In Progress |
| Users / roles / status | **Read** | Pending | Live DB | In Progress |
| Sessions incl. bulk terminate | **Read** | Pending | Live DB | In Progress |
| Password reset requests | Pending | Pending | Pending | Pending |
| Audit logs / security events / errors / admin actions | **Read** | Pending | Live DB | In Progress |
| System health / global search | **Read** | Pending | Live DB | In Progress |
| Subscriptions | Pending | Pending | Pending | Pending |
| System settings (`system_config`) | Pending | Pending | Pending | Pending |
| Master-admin tickets | Pending | Pending | Pending | Pending |
| LAN access toggle + relay | Pending | Pending | Pending | Pending |
| Outage mode | Pending | Pending | Pending | Pending |
| Iran-only filter | Pending | Pending | Pending | Pending |
| Login experience settings | Pending | Pending | Pending | Pending |
| Printer administration | Pending | Pending | Pending | Pending |
| Notification administration | Pending | Pending | Pending | Pending |
| Internal automation + administration | Pending | Pending | Pending | Pending |

### Public / content and platform

| Feature | Backend | Frontend | Tests | Status |
|---|---|---|---|---|
| Landing / rules / training pages | Pending | Pending | Pending | Pending |
| Iran-only policy page, outage page, offline page | Pending | Pending | Pending | Pending |
| Health endpoints, `robots.txt`, `sitemap.xml` | Pending | N/A | Pending | Pending |
| Jalali date handling | **Read** | Pending | Unit + Live DB | In Progress |
| Persian RTL, dark theme, local Vazir font | N/A | Pending | Pending | Pending |
| Branding (logo, «آزمایشگاه دکتر امینی», tagline) | N/A | Pending | Pending | Pending |
| Trusted proxies, host allow-list, security headers | Pending | N/A | Pending | Pending |
| CSRF / rate limiting / uploads hardening | Pending | Pending | Pending | Pending |
| Cloudflare Tunnel + HTTPS + real client IP | Pending | N/A | Pending | Pending |
| LAN access without client changes | Pending | N/A | Pending | Pending |
| Production supervision (tasks, watchdog, health contract) | Pending | N/A | Pending | Pending |

---

## Documentation status

| Document | State |
|---|---|
| `README.md` | not yet updated (still describes the Python application) |
| `MIGRATION_AUDIT.md` | **written** (Phase 1 deliverable) |
| `MIGRATION_STATUS.md` | this file — Phases 1–5 recorded with evidence |
| `docs/migration/ROUTE_INVENTORY.md` | **written** — all 249 routes |
| `docs/migration/TEMPLATE_INVENTORY.md` | **written** — all 20 templates |
| `docs/migration/JS_INVENTORY.md` | **written** — all 28 modules |
| `docs/migration/FEATURE_INVENTORY.md` | **written** — 72 features |
| `docs/migration/DATABASE_SCHEMA.md` | **written** — all 44 tables |
| `docs/migration/DATABASE_TRIGGERS.md` | **written** — all 9 triggers verbatim |
| `docs/migration/OPEN_QUESTIONS.md` | **written** — 8 decisions + 6 gaps + `Q9`–`Q12` added by Phases 3–4, `Q13`–`Q14` by Phase 5; Q1 (Araz from PHP) superseded by §35 |
| `ARCHITECTURE.md` | **written** — describes the Laravel 13 + Vue 3 + SQL Server structure that now exists, including the Phase 3 model layer (§7.1) and the Phase 4 authentication layer (§7.2) |
| `API.md` | not created — the authentication endpoints are documented in `docs/migration/ROUTE_INVENTORY.md`; the document lands with Phase 5 |
| `DATABASE.md` | **written** — connection, model layer, the three compatibility rules, the trigger contract, the no-migrations policy, verification |
| `DEPLOYMENT.md` | not created (the Python deployment is `docs/HASTAMA_PRODUCTION_DEPLOYMENT.md`) |
| `SECURITY.md` | not created (the current state is `docs/security/*`) |
| `TESTING.md` | **written** — both suites, the offline policy, the opt-in live tests, the two testing traps (CSRF is bypassed in tests; configuration is not covered by request tests), and what is not covered |

---

## Phase 2 — what now exists and runs

Side-by-side deployment, exactly as agreed: the FastAPI application keeps serving
`127.0.0.1:5000` (verified still answering `200` throughout), and the new application runs at
`127.0.0.1:8000` in `E:\Hastama\laravel\`.

| Piece | State | Evidence |
|---|---|---|
| Laravel 13.34.0 on PHP 8.5.1 | running | `php artisan about`, `GET /up` → 200 |
| Vue 3.5 + Vite 8 + Vue Router 4 + Pinia 3 | built | `npm run build` → 98 modules, route-level code splitting active (`NotFound` is its own chunk) |
| SPA shell served by Laravel, history mode, deep links | working | `GET /` and `GET /some/deep/link` both 200 with the shell |
| RTL Persian + local Vazir + dark theme | verified in a browser | `dir="rtl"`, `lang="fa"`, `data-theme` toggling, `body` background `rgb(15,23,37)` (= the old app's `--dk-bg`) |
| Theme preference contract preserved | verified | `hastama-theme` in localStorage, `dark-mode` + `dark-theme` classes on `<html>`/`<body>`, default light, `colorScheme` set |
| API envelope `{success,data,message}` | working | `GET /api/health` → 200 with the exact envelope |
| Supervision contract preserved | verified against the old app | `GET /health` → 200 `{"status":"ok"}`, byte-identical to FastAPI |
| Persian errors, no internals leaked | verified | `GET /api/health/database` → 503 with a Persian message, no stack trace, detail sent to the log |
| Unknown API path answers JSON | working | `GET /api/does-not-exist` → 404 JSON envelope |
| Production database protected from `migrate` | done | Laravel's three default migrations were moved to `database/migrations-disabled/` so no command can create `users`/`cache`/`jobs` inside the live `userDB` |
| **SQL Server connection from Laravel** | **working** | driver `sqlsrv`, instance `SQLEXPRESS`, database `userDB`, **44 tables**, 16 `user_table` rows, ~3 ms — `GET /api/health/database` → 200 |
| **Persian text through the driver** | **verified** | «مدیریت» round-trips byte-identically to the server's own UTF-16 hex; a write/read probe of «مدیریت آزمایشگاه» also passed (rolled back) |

### Database driver — resolved

`pdo_odbc` was tried first (with approval) and **rejected on evidence**: it connects and reports all
44 tables, but Windows' ANSI ODBC layer converts `nvarchar` to the client code page, so the server's
correct «مدیریت» (`45062F06CC063106CC06EC…`) arrived as `"??????"`. `Client_CSet=UTF-8` and
`ClientCharset=UTF-8` changed nothing. Laravel's ODBC path was otherwise complete (it even has
`processInsertGetIdForOdbc`), so the rejection is the Unicode defect alone, not a Laravel limitation.

Microsoft Drivers **5.13.3** for PHP (PHP 8.5, NTS, x64) are now installed as
`C:\php\ext\php_sqlsrv.dll` and `C:\php\ext\php_pdo_sqlsrv.dll`, with `php.ini` backed up and
patched byte-exactly. Checksums, backups and the reason the DLLs must use the *unversioned* names
(PHP does not discover a `_85_nts_x64` suffix by itself) are recorded in `ARCHITECTURE.md` §7.

A side benefit of the probe: PHP sees the same legacy space-padding Python does (`nchar` returns
`'user      '`), and trimmed lookups work for Latin and Persian usernames alike — the exact
compatibility behaviour `MIGRATION_AUDIT.md` §31 requires.

## Phase 3 — the model layer

The schema was already there; Phase 3 built the Eloquent layer that describes it and then **proved** the
description is accurate. No feature was migrated in this phase, so no feature row moved — the model
layer is what Phases 4–17 will stand on.

| Piece | State | Evidence |
|---|---|---|
| **43 Eloquent models** for all 43 application tables | done | `hastama:schema-check` reports 43 models / 43 tables, no problems |
| Models verified against the **live** `userDB` | verified | `php artisan hastama:schema-check` → exit 0, "Every model matches the live schema" |
| Legacy padding handled on read | verified | 11 usernames, 13 `hozoor_num`, 16 `role` values trim correctly (`LegacyModelTest`) |
| Arabic/Persian yeh and kaf folded for lookups | verified | `PersianTextTest` asserts the exact code points and the exact SQL expression |
| Password verification for all four legacy formats | verified | `LegacyPasswordTest`, including the padded plaintext that 15 of 16 accounts use |
| Trigger-owned tables made read-only | verified | `leave_report` / `ezafe_total_table` throw on write; `totalpass_table` deliberately writable |
| Identity vs application-allocated keys | verified | `user_table` (no identity), the identity-keyed `hozoor` and queue tables, three composite keys reported as notes |
| Continuous queue numbering reachable safely | verified | `QueueTicket::nextNumber()` takes the raw lock and refuses to run outside a transaction; returns `MAX+1` on the live table in a rolled-back transaction |
| No migration can alter the live schema | verified | `MigrationSafetyTest` — empty `database/migrations`, scaffold migrations parked, empty seeder, no factories |
| Scaffold seeder that would have written to `user_table` | removed | `DatabaseSeeder` is explicitly empty; `UserFactory` deleted; both asserted by tests |
| Laravel suite | passing | **44 passed / 6 skipped** offline; **50 passed** with `HASTAMA_DB_TESTS=1` |
| Style | clean | `./vendor/bin/pint --test` → 85 files, no issues |
| Production untouched | verified | FastAPI `/health` still 200 throughout; `user_table` still 16 rows; the one write probe was rolled back |

### Corrections this phase forced

Recording mistakes found and fixed is part of the audit's value:

1. **`totalpass_table` is not trigger-owned.** Contracting it to read-only would have broken the hourly-pass
   approval queue: the application inserts the queue row itself and updates its `status`. Corrected in
   `docs/migration/DATABASE_TRIGGERS.md`, and the overlap it creates is now `Q9` in `OPEN_QUESTIONS.md`.
2. **The two guarded tables have no triggers of their own.** `trg_UpdateLeaveReport` lives on
   `mrkhc_table`; the overtime triggers live on `ezafe_table`. The first version of the schema check
   asserted the wrong thing and reported two false failures.
3. **The generated schema document printed one global foreign-key list under all 44 tables**, and was
   incomplete: 15 constraints exist, not 11. The repeated blocks were removed and an authoritative,
   source-read list was added.
4. **`totalpass_table` may receive two rows for one request** (trigger plus application insert). Not
   changed — the migration reproduces existing behaviour rather than guessing at a fix.

## Phase 4 — authentication

Authentication is the migration's highest-risk area, because the live table does not authenticate the
way any framework expects it to: **15 of the 16 accounts store a plaintext password** in
`password nchar(10)`, and exactly one has a bcrypt hash. Phase 4 replaced the credential path without
locking anyone out, and ported the session model the rest of the application is built on.

| Piece | State | Evidence |
|---|---|---|
| `legacy` user provider (`App\Auth\LegacyUserProvider`) | done | Registered through `Auth::provider()`; `config/auth.php` selects it by name. `AuthSurfaceTest` asserts the registered provider is this class — Laravel's stock `EloquentUserProvider` would reject 14 of 15 accounts |
| The four legacy credential formats | verified | bcrypt in `password_hash`, bcrypt in `password`, SHA-512 (raw or hex) in either, then constant-time plaintext. `LegacyPasswordTest` + `AuthDatabaseTest` against real rows |
| **First-login upgrade to bcrypt** | verified | A live account with a plaintext credential logs in, the row is rewritten (`password` emptied, bcrypt into `password_hash`, `password_changed_at` stamped), and a second login does **not** rewrite it again. Asserted inside a transaction, rolled back |
| Account-active rule folded into credential validation | verified | `disabled` / `inactive` / `locked` / `0` / `false` block the login and produce the *same* generic message as a wrong password |
| Padded, letter-folded username lookup | verified | `LOWER(REPLACE(REPLACE(LTRIM(RTRIM(username)), ي→ی), ك→ک))`; the live Arabic-yeh accounts resolve through the provider |
| Non-enumerating failure answers | verified | An unknown username and a wrong password return byte-identical status and body (`AuthDatabaseTest`) |
| Login CAPTCHA | verified | 6 characters from `A–Z` minus `O/I/0/1`, GD-rendered 180×56 PNG with rotation/noise/sine interference, code never in the response, 3 attempts, case-insensitive, single-use, TTL from `system_config` clamped 30–1800 s |
| CAPTCHA ordering | verified | Checked **before** any credential lookup, so the endpoint cannot be used as a password oracle — asserted by showing the row was not upgraded even though the credentials were correct |
| Brute-force throttle | done | 15 failures/IP and 30/account per 600 s, over Laravel's atomic cache limiter (the Python one was per-process and reset on restart) |
| Revocable session registry (`user_sessions`) | verified | Every login inserts a row with a fresh 43-character token; `/logout` marks it revoked with `terminated_by='self'` |
| Session registry validation middleware (`legacy.session`) | done | Confirms the registry row is active, belongs to the same username, and has not been idle beyond `HASTAMA_IDLE_TIMEOUT_SECONDS` (1800) — deliberately distinct from the client-side `idle_timeout_seconds` (300) |
| Admin / master-admin guards | verified | `401 {"success":false,"error":"لاگین نکردهاید."}` / `403 {…"دسترسی مدیریتی ندارید."}` and `401/403 {"detail": …}` for the control plane — including the 403 security event |
| CSRF bridge | verified | The legacy `csrf_token` cookie + `X-CSRF-Token` header scheme and Laravel's `XSRF-TOKEN` both accepted; a cross-site write to an exempt path is refused with `403 {"success":false,"error":"CSRF token mismatch."}` |
| Public endpoints ported from Python | done | `POST /login_user`, `GET /logout`, `GET /captcha`, `POST /captcha/refresh`, `GET /captcha/status`, `GET /api/csrf-token`, `GET /api/system-config`, `POST /api/session/destroy`, `POST /forgot_password`, `POST /reset_password` — paths, bodies and status codes unchanged |
| One deliberate addition | recorded | `GET /api/me`, behind `legacy.session`, so the SPA can ask "is this session good, and what is my role". Marked as an addition, not presented as a port |
| Recovery flow | done | Fails closed without `HASTAMA_HMAC_SECRET`, 8-character `A–Z0–9` code stored as HMAC-SHA256, 60-minute TTL, attempt cap, one generic rejection message, reason-specific messages only *after* the code is proven. There is **no email and no SMS** in this flow — an administrator approves and relays the code |
| Laravel suite | passing | **120 passed / 15 skipped** offline; **135 passed** with `HASTAMA_DB_TESTS=1` |
| Style | clean | `./vendor/bin/pint --test` → 110 files, no issues |
| Production untouched | verified | `user_table` still 16 rows with **1** hashed and 0 cleared; `user_sessions` still 305 rows; `password_reset_requests` still 7. Every write in the database test suite is rolled back |

### The live smoke test earned its keep

The Phase 4 endpoints were exercised over real HTTP against the running server (cookies, redirects, a
real session file), not only through Laravel's test client. That found **six defects the test suite
could not see**, each of which would have surfaced only in production:

1. **`LegacyUserProvider` did not satisfy the `UserProvider` contract.** Laravel 13 added
   `rehashPasswordIfRequired()`, and the un-implemented abstract method is a *fatal error* the moment
   any guarded route resolves the guard. Now implemented as the migration's upgrade hook.
2. **The CSRF middleware replacement silently did nothing.** `$middleware->web(replace: …)` matches by
   exact class name, and Laravel 13's group contains `PreventRequestForgery`, not the deprecated
   `ValidateCsrfToken` alias it was keyed on. The stock middleware kept running, so no legacy exemption
   applied and every submission was answered `419 Page Expired`. `AuthSurfaceTest` now asserts the
   configured group itself, because no request-level test can see this (the framework switches CSRF off
   in tests).
3. **The readable `csrf_token` cookie was encrypted.** Laravel encrypts outgoing cookies by default;
   `app/static/js/csrf-bootstrap.js` reads that cookie with `document.cookie`, so the header it echoed
   could never equal the session token. The cookie is now on `EncryptCookies`' except list — which is
   also what the legacy middleware published.
4. **The CSRF cookie lived 20 days instead of 8 hours.** Laravel's `cookie()` helper takes *minutes*;
   the setting is seconds (it mirrors `SESSION_MAX_AGE_SECONDS`).
5. **A rejected CSRF request answered a debug page.** `Handler::prepareException()` rewrites a
   `TokenMismatchException` into a generic `HttpException(419)` *before* any `render` callback runs, so
   the callback never fired and the client got HTML `419 Page Expired` — or a full stack trace — from an
   endpoint documented to answer JSON. The middleware now answers the rejection itself.
6. **Bcrypt could not be written to `password_hash`.** SQL Server refuses an implicit `nvarchar` →
   `varbinary` conversion, so *every* password change was a 500. Verified fix: `CONVERT(varbinary(64),
   CONVERT(varchar(128), ?, 2))` with the hash bound as hex. Centralised in
   `App\Services\Auth\LegacyCredentialWriter` so the login upgrade and the recovery reset cannot drift
   apart.

Two further defects were caught while reading the source rather than running it: `config/hastama.php`
referenced a `SystemConfig::PUBLIC_KEYS` constant that does not exist (the list lives on
`SystemSettings`), and `LegacyIds::make()` produced alphanumeric ids that its own `isValid()` — and the
Python `validate_request_id()` — reject, so a generated recovery request id could never be looked up
again. Both are fixed and covered by tests.

### Operational note — the legacy server was found stopped, and restored

While verifying Phase 4, `127.0.0.1:5000` was found **not listening**. Nothing in this migration
stopped it, and no Python file was touched (`git status` shows no changes under `app/`, and the Python
suite still reports the same `866 passed / 13 failed / 4 skipped`). The machine's own development-session
supervisor had logged a deliberate stop at `09:26:09` — `logs/hastama-dev-session.log` ends with
`STOPPED … (production launcher wrapper)` and no matching start — so the reference application was down
before the Phase 4 work resumed.

It was restored with the project's **own** production launcher, exactly as documented:
`scripts\run_server.bat` (which locates and runs `scripts\راه‌اندازی_سرور_تولید.bat`, the only supported
start path). Verified afterwards: `127.0.0.1:5000` listening, `/health`, `/api/system-config` and
`/login` all `200`, and both applications are serving side by side again (`5000` FastAPI, `8000` Laravel).

**A gap worth closing before cutover:** the `HastamaServer` and `HastamaWatchdog` scheduled tasks are
**Disabled**, so nothing restarts the legacy application if it stops — this incident had no supervisor
to recover from it. The restored instance is therefore detached rather than task-supervised. Re-enabling
the tasks (`scripts\فعال‌سازی_اجرای_خودکار.bat`) is the durable fix, and it belongs to Phase 17.

### Corrections and decisions this phase forced

1. **The CSRF exemption list was transcribed in full, not grown endpoint by endpoint.** The kiosk and
   LAN endpoints do not exist yet, so a list built lazily would have failed on the day the first kiosk
   request arrived. An exemption is also only half the control: the same-site `Origin` check that the
   Python middleware applied to exempt writes now lives in `VerifyLegacyCsrf`.
2. **`verify_recovery_code` appears in the exemption list but has no route.** The Python exemption tuple
   includes it and no endpoint answers it; the entry is kept verbatim rather than tidied, and recorded
   as `Q10` in `docs/migration/OPEN_QUESTIONS.md`.
3. **A successful login against the live database was not performed by hand.** It rewrites a real
   account's credential — the intended behaviour, but a change to production data that belongs to the
   operator, not to verification. The path is proven instead by `AuthDatabaseTest`, which drives the
   real HTTP kernel against the real database and rolls the write back. Recorded as `Q11`.
4. **`password_changed_at` is stamped on upgrade.** The Python reset flow does not set it; the column
   exists and the control centre displays it, so a credential that genuinely changed is now dated.
   An addition, recorded here rather than left implicit.
5. **Laravel 13 accepts `Sec-Fetch-Site: same-origin` as a CSRF substitute** on non-exempt unsafe
   requests (`PreventRequestForgery::hasValidOrigin()`). That header is browser-set and unforgeable, so
   the control is not weakened, but it *is* a behavioural difference from the legacy middleware, which
   always required a token. Recorded as `Q12` for the Phase 15 security review.

## Phase 5 — the read surface

The first route groups of `docs/migration/ROUTE_INVENTORY.md` are ported: the master-admin
control plane and the read half of the user panel. Every one of them is a **port of a specific
Python handler**, and each is registered at the path the running front-end already calls, so the
legacy UI can be pointed at the new server group by group without a rewrite.

| Group | Routes | Source |
|---|---|---|
| Control centre — dashboard | `/master-admin/api/dashboard/stats`, `/dashboard/activity` | `app/api/routes/master_admin.py` |
| Control centre — users | `/master-admin/api/users`, `/users/{username}` | ibid. |
| Control centre — audit trail | `/master-admin/api/audit-logs`, `/audit-logs/{event_id}` | ibid. |
| Control centre — sessions | `/master-admin/api/sessions` | ibid. |
| Control centre — diagnostics | `/master-admin/api/system-health`, `/search` | ibid. |
| User panel — pickers | `/get_users`, `/get_receivers` | `app/main.py` |
| User panel — profile | `/get_user_info`, `/get_user_info_report` | ibid. |
| User panel — calendar and shifts | `/get_today_date`, `/get_active_shifts` | ibid. |
| User panel — leave | `/get_leave_info`, `/get_leave_requests` | ibid. |

The support classes these needed are the ones that decide whether the two servers agree on the wire,
and each exists because Laravel's obvious equivalent is not equivalent:

* `LegacySerializer` — Python's `isoformat()` (`T`, exactly six fractional digits), `bytes` → hex,
  `Decimal` → float.
* `LegacySchema` — the declared column types. `pdo_sqlsrv` returns **every** scalar as a string, so
  `id` would be `"319"` and `is_active` would be `"0"` without it.
* `LegacyPagination` — the six-key envelope, including `pages: 1` for an empty result.
* `LegacyDate` — the Jalali conversion, checked against `persiantools`/`jdatetime` over 73,414
  consecutive days (1900–2100).
* `LegacyQuery` — FastAPI's `Query(...)` declarations, because `$request->validate()` answers a
  **302 redirect** for a request that does not send `Accept: application/json`.
* `LegacyResponseFactory` — `json_encode` without Laravel's default escaping.

### Three real defects the port found

These are behaviour differences in the **existing** application, recorded rather than quietly
“fixed”, because the brief makes the running server the source of truth:

1. **`GET /master-admin/api/audit-logs/{event_id}` raises `IndexError` in Python.** The handler calls
   `cursor.fetchone()` *after* a companion query has already exhausted the cursor, so a request for a
   row that exists answers HTTP 500 with the generic internal-error body. The Laravel version answers
   200 with the event. This is a genuine bug in the running server, not a porting mistake;
   `LegacyReadDatabaseTest::the_audit_event_detail_returns_the_event_instead_of_crashing` pins the
   new behaviour so the difference cannot be lost.
2. **Laravel renders a validation failure as a redirect.** FastAPI answers `422` with a
   machine-readable `{"detail": [...]}` list *before* the handler runs; `$request->validate()`
   answers `302` back to the referring page unless the request asks for JSON. The legacy front-end
   calls these endpoints with `fetch()` defaults and branches on the status code, so it would have
   seen a redirect to the page it was already on. `LegacyQuery` reproduces FastAPI's `type`/`msg`/
   `loc`/`input`/`ctx` exactly — captured from `127.0.0.1:5000` with `curl`, not from the Pydantic
   documentation.
3. **Laravel escapes non-ASCII and `/` in JSON.** `json_encode` defaults to options `0`, so a Persian
   message went out as `\u0644\u0627\u06af\u06cc\u0646 …` where Starlette's
   `json.dumps(..., ensure_ascii=False)` sent raw UTF-8 — a different `Content-Length`, a failed
   byte-for-byte diff, and two different resources to any cache keyed on the body. Fixed once in
   `LegacyResponseFactory` rather than at each call site.

### Two global decisions

1. **`TrimStrings` and `ConvertEmptyStringsToNull` are removed from the global middleware stack.**
   Both are Laravel defaults and neither existed in FastAPI. Together they collapsed
   `?username= admin` into `?username=admin` and `?username=` into “no `username` at all” — and the
   second of those made `/get_user_info_report?username=` answer `422 Field required` for a parameter
   the caller had supplied. Every place the Python application meant to trim, it trims explicitly
   (`.strip()` in `_require_admin` and `_ticket_actor`, `LTRIM(RTRIM(...))` around the stored
   column), and the Phase 4 services follow the same rule by hand — so nothing depended on the
   framework doing it implicitly. Removing them is one decision instead of a re-derived
   normalisation in every controller of every remaining phase.
2. **JSON is emitted unescaped, application-wide.** See defect 3. The binding is on
   `Illuminate\Contracts\Routing\ResponseFactory` — the key `response()` actually resolves — with the
   concrete class pointed at the same instance, because binding the concrete class alone silently
   changes nothing.

### One difference that remains

On the master-admin routes the request passes through the `master_admin` middleware **before** the
controller validates its parameters, so an *anonymous* request with a bad parameter is answered
`401` where FastAPI answered `422` (FastAPI resolves query parameters before the handler body, and
`_master_admin` is inside the handler). Both are rejections of the same request, the `422` body only
echoes the caller's own input, and no caller reaches these routes unauthenticated during the
side-by-side period — so this is recorded rather than restructured. `/get_user_info_report` *is*
ordered correctly, because its `_require_auth` check lives in the handler and can therefore run after
validation; the ordering is asserted in
`LegacyReadSurfaceTest::the_report_endpoint_resolves_its_required_parameter_before_it_authenticates`.

### Evidence

```text
php artisan test                                   519 passed, 35 skipped (3104 assertions)
HASTAMA_DB_TESTS=1 php artisan test                554 passed          (3832 assertions)
HASTAMA_DB_TESTS=1 ... --filter=LegacyReadDatabaseTest   20 passed     (654 assertions)
./vendor/bin/pint --test                           138 files, clean
```

`LegacyReadDatabaseTest` runs every case inside a transaction that is always rolled back, so the live
rows and the session registry are left exactly as they were found.

Six responses were compared byte-for-byte against the running FastAPI server over HTTP, and all six
are identical: `/get_user_info_report` (the 422 body), `?username=x` and `?username=` (the Persian
`401` body), `/get_today_date`, `/api/system-config` (419 bytes incl. Persian values) and `/health`.

---

## Verification checklist (§66 of the brief)

Every line is unticked except those actually exercised. Nothing is claimed as verified that has not
been run.

```text
[x] Laravel backend starts correctly            (php artisan serve, GET /up 200)
[x] Vue frontend builds correctly               (npm run build, 98 modules, code splitting)
[x] SQL Server connection works                 (sqlsrv, SQLEXPRESS/userDB, 44 tables, 3ms)
[x] Existing database works                     (all 43 models verified against the live schema; Persian round-trip verified)
[~] RTL works / dark theme works                (verified in a browser, not yet a test)
[ ] Existing database works
[x] Login works                                (live HTTP: generic failure for unknown account; full success path in AuthDatabaseTest)
[x] Logout works                               (live HTTP: 302 → /login, registry row revoked with terminated_by='self')
[x] CAPTCHA / verification flow works         (live HTTP: PNG, code in the session only, single-use, 3 attempts; no 5-digit code exists — see MIGRATION_AUDIT.md §31)
[x] Session handling works                     (registry row per login, revocation, idle check, rotation on login)
[x] Admin authorization works                  (EnsureAdmin: 401/403 with the legacy `error` key)
[~] User authorization works                   (the default role path and the guards are in place; `owner` scoping lands with the endpoints that need it)
[x] Password handling works                    (four legacy formats + first-login bcrypt upgrade, verified against real rows)
[x] Password reset works                       (request → approval → 8-char code; public half verified, admin approval is Phase 9)
[~] User Panel works                          (read half ported: `/get_users`, `/get_receivers`, `/get_user_info`, `/get_user_info_report`, `/get_today_date`, `/get_active_shifts`, `/get_leave_info`, `/get_leave_requests`; writes and the Vue pages are Phases 8–10)
[~] Admin Panel works                         (read half ported: dashboard stats/activity, users, audit trail, sessions, system health, global search; writes and the Vue pages are Phase 9)
[ ] Attendance works
[ ] Araz integration works
[ ] Card number mapping works
[ ] Overtime works
[ ] Leave works
[ ] Hourly Pass works
[ ] Ticketing works
[ ] Announcements work
[ ] Notifications work
[ ] Scheduler works
[ ] Background jobs work
[ ] WebSocket works where required
[ ] SSE works where required
[ ] Call Management works
[ ] Call Display works
[ ] Label printing works
[ ] Receipt printing works
[ ] File uploads work
[x] Jalali dates work                          (`LegacyDate` matches `persiantools`/`jdatetime` over 73,414 consecutive days, 1900–2100; `/get_today_date` byte-identical to the running server)
[ ] RTL works
[ ] Responsive UI works
[ ] Dark theme works
[ ] Cloudflare Tunnel works
[ ] HTTPS works
[ ] LAN access works
[ ] External access works
[ ] Security checks pass
[ ] Existing tests are reviewed
[ ] New regression tests pass
[ ] No critical TODO remains
[ ] No fake implementation remains
[ ] No accidental data loss occurred
```
