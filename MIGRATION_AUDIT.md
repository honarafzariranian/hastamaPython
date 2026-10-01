# MIGRATION_AUDIT — Hastama (FastAPI/Jinja2 → Laravel + Vue 3)

**Status:** Phase 1 complete — inspection only. **No application code was changed.**
**Date:** 2026-09-30
**Source of truth:** the running application at `E:\Hastama` on branch `master` (HEAD `8c619dc`),
the live SQL Server database `userDB` on `localhost\SQLEXPRESS`, and the machine that runs it.

This document describes **what actually exists**. Where the migration brief assumed something
that is not true in this codebase, the correction is recorded in
[§31 Corrections to the migration brief](#31-corrections-to-the-migration-brief) and the
unchanged assumption is *not* carried forward.

Evidence produced by the audit (kept, not deleted):

| Artifact | Contents |
|---|---|
| `docs/migration/ROUTE_INVENTORY.md` | all **249** endpoints, machine-extracted with file:line, handler name and the auth guard each handler really uses |
| `docs/migration/TEMPLATE_INVENTORY.md` | all 20 Jinja2 templates with vars, forms, scripts, API references |
| `docs/migration/JS_INVENTORY.md` | all 28 JavaScript modules with fetch/DOM/WebSocket/SSE counts |
| `docs/migration/FEATURE_INVENTORY.md` | every feature, old implementation → target Laravel/Vue mapping, status |
| `docs/migration/DATABASE_SCHEMA.md` | all 44 tables, columns, types, PK/FK/index, row counts |
| `docs/migration/DATABASE_TRIGGERS.md` | the 9 triggers verbatim — business logic that lives in the database |
| `docs/migration/OPEN_QUESTIONS.md` | the unknowns this audit could not resolve from the code alone |

---

## 1. Current architecture

```text
Internet users  ─┐
                 ├─▶ https://hastama.ir  (the only address users are given)
LAN users       ─┘
                        │
                 Cloudflare edge ── TLS, WAF, www→apex 301, HSTS
                        │
                 cloudflared tunnel (Windows service, AUTOSTART, LocalSystem)
                        │  http://127.0.0.1:5000
                        ▼
                 uvicorn app.main:app          ← launched ONLY by the \HastamaServer task
                        │
                 ┌──────┴──────────────────────────────────────┐
                 │ SQL Server Express  localhost\SQLEXPRESS     │
                 │   database userDB  (44 tables, 9 triggers)   │
                 │ Microsoft Access   database\Arazdb.mdb       │
                 │   table TPrsInOut (attendance device feed)   │
                 └──────────────────────────────────────────────┘
                        │
                 APScheduler (in-process) · SSE · WebSocket · Windows print spooler
```

* **Stack:** Python 3.11 · FastAPI · Uvicorn 0.23.2 · Jinja2 server-rendered pages + vanilla JS
  (no framework, no bundler, no `package.json`) · pyodbc → SQL Server.
* **One process.** There is no worker, no message queue and no second service for the application
  itself. `apscheduler.schedulers.background.BackgroundScheduler` runs **inside** the uvicorn
  process (`app/services/background_tasks.py`), started from the lifespan handler.
* **One listener.** Uvicorn binds `127.0.0.1:5000` only. LAN reachability is an optional,
  runtime-toggled **byte-transparent relay** (`app/services/lan_access.py`), not a second server.
* **Static assets** are served by `app.mount("/static", StaticFiles(directory="app/static"))`.
  No CDN; all fonts/images are local (`app/static/fonts/Vazir.woff2`, `app/static/images/*`).
* **No build step.** `HASTAMA_MINIFY_CLIENT_ASSETS=1` (set only by the production launcher)
  enables a runtime in-process minifier middleware (`app/services/client_assets.py`), which is the
  closest thing the project has to an asset pipeline.

## 2. Directory structure

```text
E:\Hastama\
├── app\
│   ├── main.py                    5623 lines — 87 routes, all middleware, the page layer
│   ├── api\routes\                auth, notifications, ticketing, automation, health,
│   │                              call_system, araz_api, master_admin, registration
│   ├── core\                      config, database, db_context, net, sessions, session_cookie,
│   │                              password_utils, rate_limit, validation, paginator, console
│   ├── services\                  iran_access, lan_access, outage, captcha, login_experience,
│   │                              audit, ticketing, ticket_print, printer, label_render,
│   │                              araz_connector, automation, presence_summary, attendance,
│   │                              background_tasks, client_assets, system_config
│   ├── data\                      iran_ip_ranges.txt (generated) + iran_ip_ranges_extra.txt
│   ├── static\                    css(29) js(28) fonts images audio slides vendor uploads sw.js
│   └── templates\                 20 Jinja2 templates + partials\
├── database\                      Arazdb.mdb (48 MB) + 16 .sql schema/feature scripts
├── docs\                          deployment, network, security, araz, offline, guides, migration\
├── logs\                          application, watchdog, autostart, dev-session, error logs
├── ml\ model\ notebooks\ arazin\ tools\ offline\   (arazin\ = 59 475 files, vendor material)
├── scripts\                       15 Persian-named .bat launchers + ASCII .ps1 helpers + .py
├── tests\                         40 test modules, 879 collected tests
├── .env  .env.example             runtime secrets and the documented template
├── pyproject.toml                 dependencies + pytest config
└── Caddyfile (retired)  Dockerfile (unused)  docker-compose.yml (unused)
```

## 3. FastAPI routes

**249 endpoints.** Full table: [docs/migration/ROUTE_INVENTORY.md](docs/migration/ROUTE_INVENTORY.md).

| Source module | Routes | Prefix |
|---|---:|---|
| `app/main.py` | 87 | *(none)* |
| `app/api/routes/master_admin.py` | 57 | `/master-admin/api` |
| `app/api/routes/call_system.py` | 36 | `/api` |
| `app/api/routes/notifications.py` | 21 | `/api` |
| `app/api/routes/registration.py` | 11 | `/registration` |
| `app/api/routes/automation.py` | 10 | `/api/automation` |
| `app/api/routes/ticketing.py` | 9 | `/api/tickets` |
| `app/api/routes/araz_api.py` | 8 | `/api/araz` |
| `app/api/routes/auth.py` | 8 | *(none)* |
| `app/api/routes/health.py` | 2 | *(none)* |

Real guard distribution (measured from the handler bodies, not from documentation):

| Guard | Routes |
|---|---:|
| `admin session` (`is_admin`) | 84 |
| `master-admin session` (`is_master_admin`) | 68 |
| `none (public)` | 51 |
| `authenticated session` | 39 |
| same-site `Origin` + per-IP rate limit (kiosk, deliberately session-free) | 6 |
| bridge shared secret (`hmac.compare_digest`, fails closed) | 1 |

There are **no** FastAPI dependencies, routers-in-routers or path-operation groups to inherit;
every handler guards itself. The Laravel route file must therefore classify all 249 routes
explicitly. See §10, §11.

## 4. Jinja2 templates

20 templates, 12 637 lines total. Full table:
[docs/migration/TEMPLATE_INVENTORY.md](docs/migration/TEMPLATE_INVENTORY.md).

The five that matter, by size (they are the whole application surface):

| Template | Lines | `<form>` | Element ids | Loaded JS |
|---|---:|---:|---:|---:|
| `ticket-kiosk.html` | 3 499 | 0 | 52 | 2 |
| `admin.html` | 3 091 | 9 | 294 | 1 |
| `user-panel.html` | 1 336 | 8 | 167 | 1 |
| `master-admin.html` | 686 | 0 | 69 | 1 |
| `login.html` | 645 | 4 | 45 | 1 |

Two rendering styles coexist and both must be reproduced:

1. **Server-rendered data.** `admin.html` and `user-panel.html` receive data through
   `TemplateResponse(..., {…})` — `main.py` runs the queries and the template interpolates the
   result. This is not an SPA shell.
2. **Client-rendered panels.** `master-admin.html` renders an empty frame and
   `master-admin.js` (2 225 lines) builds every section in the browser from
   `/master-admin/api/*`.

`partials/label_queue.html` is included by `ticket-kiosk.html`. There is **no** Jinja base
template and **no** `{% extends %}` anywhere — each page carries its own `<head>`, which is why
CSS is duplicated across pages and why the dark-theme rule ordering is fragile (see §23, §28).

## 5. JavaScript modules

28 modules, 19 707 lines. Full table: [docs/migration/JS_INVENTORY.md](docs/migration/JS_INVENTORY.md).

| Module | Lines | `fetch()` | DOM ops | Real-time |
|---|---:|---:|---:|---|
| `admin.js` | 6 086 | 38 | 778 | — |
| `user-panel-script.js` | 2 590 | 22 | 345 | — |
| `master-admin.js` | 2 225 | 1 | 245 | — |
| `responsive-tables.js` | 1 303 | 0 | 92 | — |
| `call-system-standalone.js` | 1 259 | 25 | 159 | **WebSocket** |
| `notification-system.js` | 441 | 1 | 132 | **SSE** |
| `call-display.js` | 557 | 3 | 52 | **WebSocket** |
| `label-system.js` | 551 | 2 | 13 | — |

* 778 DOM operations in `admin.js` alone. This is imperative DOM code, not declarative rendering;
  it must be decomposed into Vue components, not wrapped.
* `master-admin.js` has only 1 `fetch()` because it goes through one shared request helper that
  builds `/master-admin/api/...` paths dynamically — the API surface is far larger than the
  literal count suggests (14 literal API paths in the template + dynamic construction).
* `dom-escape.js` (`escapeHtml`) and `number-format.js` are the two existing shared utilities and
  are the correct precedent for `resources/js/utils/`.
* `csrf-bootstrap.js` fetches `/api/csrf-token` and patches `fetch` to add `X-CSRF-Token` —
  a pattern that Laravel Sanctum + axios (or a small interceptor) replaces directly.

## 6. CSS files

29 stylesheets, 45 245 lines. `admin.css` alone is 12 443 lines.

| File | Lines | Role |
|---|---:|---|
| `admin.css` | 12 443 | admin panel |
| `user-panel-style.css` | 5 530 | user panel |
| `dark-theme.css` | 3 575 | dark theme, **must load last** (asserted by tests) |
| `admin-mobile-redesign.css` | 2 970 | mobile admin |
| `final-report-modern.css` | 2 417 | print/report |
| `login-style.css` | 2 261 | login |
| `call-system-standalone.css` | 1 928 | call management |
| `ticketing.css` | 1 927 | ticketing |
| `master-admin.css` | 1 391 | control centre |
| `vazir.css` | 29 | `@font-face` for local Vazir |

Convention: design tokens live in `call-tokens.css` (88 lines) and `hastama-ux.css` (803 lines);
everything else is page-scoped and mostly handwritten, with significant duplication between
`admin.css` / `admin-mobile-redesign.css` / `responsive-mobile.css`. **No preprocessor, no
Tailwind, no PostCSS.**

## 7. Database tables

**44 tables in `dbo`, 0 views, 0 application stored procedures, 9 triggers.**
Full detail: [docs/migration/DATABASE_SCHEMA.md](docs/migration/DATABASE_SCHEMA.md).

> **Corrections applied during Phase 3** (building the model layer forced each one to be checked
> against the live server and the source, and all four are fixed in the documents):
>
> 1. The generated schema document printed **one global foreign-key list under all 44 tables**. It was
>    also incomplete — 15 constraints exist, not 11. The repeated blocks were removed and an
>    authoritative list read from `sys.foreign_keys` was added.
> 2. **The aggregate tables own no triggers of their own.** `trg_UpdateLeaveReport` is attached to
>    `mrkhc_table` and writes `leave_report`; the overtime triggers are attached to `ezafe_table`. Only
>    `leave_report` and `ezafe_total_table` are genuinely read-only.
> 3. **`totalpass_table` is application-writable.** It is the admin approval queue: the application
>    inserts the queue row on submission and updates `status` on approval. Listing it as
>    trigger-owned (an earlier revision of `DATABASE_TRIGGERS.md` did) would have broken the hourly-pass
>    workflow. The overlap this creates is recorded as `Q9` in OPEN_QUESTIONS.
> 4. Row counts in these documents are **snapshots**: the FastAPI application keeps serving during the
>    migration, so `audit_logs` (3 509 → 3 515) and `user_sessions` (303 → 305) grow between runs.
>    Only the schema is stable.

> **Additions applied during Phase 4** (implementing authentication against the live server corrected
> four things this audit stated or implied):
>
> 1. **There is no separate password-upgrade step in the legacy code.** `verify_password()` supports four
>    formats and the reset flow writes `password = ''` plus a bcrypt `password_hash`; nothing in the
>    Python application ever converts a plaintext row outside a reset. The first-login upgrade is a
>    **migration decision**, and it is now implemented in exactly one place
>    (`App\Services\Auth\LegacyCredentialWriter`) so the login path and the reset path cannot diverge.
> 2. **`password_changed_at` is written by nobody in the legacy application.** It is read by the control
>    centre's user list and detail views and is `NULL` for all 16 rows. The migration stamps it when it
>    replaces a credential, which is an addition rather than a port — recorded in
>    `MIGRATION_STATUS.md` (Phase 4, decision 4).
> 3. **`password_hash` is `varbinary(64)`, not `varbinary(max)`.** The Python fallback path that would
>    have run `ALTER TABLE user_table ADD password_hash VARBINARY(MAX)` is dead code on this
>    installation: the column exists with its own width, and SQL Server rejects an implicit `nvarchar`
>    → `varbinary` assignment, so the write has to name the conversion explicitly.
> 4. **The CSRF exemption list contains an entry with no route.** `/verify_recovery_code` is exempted and
>    nothing answers it; the live recovery flow verifies inside `POST /reset_password`. The entry is kept
>    verbatim and recorded as `Q10`.

Name-prefix census — three generations of schema in one database, which is the single most
important structural fact for the migration:

**Generation 1 — legacy Persian/Araz payroll tables** (small, trigger-driven):
`user_table` (16), `hozoor` (59), `shiftha` (4), `mrkhc_table` (4), `leave_report` (0),
`avalpss_table` (5), `beynpss_table` (1), `akhrpss_table` (0), `totalpass_table` (6),
`ezafe_table` (2), `ezafe_total_table` (1), `ticket_table` (1), `akhrpss_table`,
`sysdiagrams` (0).

**Generation 2 — first Hastama build:**
`audit_logs` (3 509), `security_events` (4), `user_sessions` (303), `password_reset_requests` (7),
`system_config` (12), `admin_actions` (133), `admin_payroll_calculations` (54),
`customer_subscriptions` (1).

**Generation 3 — newest features:**
`notifications` (2), `notification_targets` (2), `user_notifications` (2), `push_subscriptions` (3),
`tickets` (3), `ticket_messages` (19), `ticket_events` (19), `ticket_categories` (4),
`ticket_tags` (0), `ticket_tag_relations` (0), `ticket_attachments` (0),
`queue_tickets` (42), `display_queue` (1), `waiting_queue` (1), `reception_calls` (3),
`slides` (4), `automation_conversations` (4), `automation_messages` (6),
`automation_participants` (8), `automation_attachments` (0), `automation_reopen_requests` (0),
`user_registration_requests` (0), `system_errors` (0).

**`user_table` — the real schema (verified, not assumed).** 25 columns, PK `id` (not identity),
index `IX_user_table_username` (**non-unique**):

| Column | Type | Note |
|---|---|---|
| `id` | `int NOT NULL` | PK, **not** an identity column — inserts must supply it |
| `username` | `nvarchar(50)` | **not** `nchar(10)`; see the padding note below |
| `password` | **`nchar(10)`** | **legacy plaintext, still populated for 16/16 users** |
| `password_hash` | `varbinary(64)` | populated for **1** user only |
| `role` | `nchar(10)` | values `admin` (3) and `user` (13), space-padded |
| `hozoor_num` | `nchar(10)` | Araz `CardNo`; **3 rows are NULL**, 13 are space-padded |
| `name`,`last_name`,`department`,`substitute` | `nvarchar(50)` | |
| `work_hours` | `nvarchar(max)` | |
| `shanbeh`,`yekshanbeh`,`doshanbeh`,`seshanbeh`,`chrshanbeh`,`panjshanbeh` | `nvarchar(max)` | **`chrshanbeh` — not `chaharshanbeh`**; `shiftha` spells it `chaharshanbeh` for the same weekday |
| `profile_image` `nvarchar(255)`, `employment_status` `nvarchar(20)`, `is_active` `nvarchar(10)` | | `is_active` = `'active'` for all 16 |
| `last_login`,`password_changed_at` `datetime2`, `failed_login_count int default 0` | | |
| `customer_id` `nvarchar(128)`, `customer_name` `nvarchar(255)` | | multi-tenant hook, 1 row used |

Measured data hazards (all counts, no PII):

* **11 of 16 usernames carry real trailing spaces** (`DATALENGTH = 2 × LEN`), 12 rows are 20 bytes
  wide = 10 `nchar` characters — legacy `nchar(10)` data widened to `nvarchar(50)`.
  There is **no** column where the spaces were trimmed. Every lookup in the codebase compensates
  with `LTRIM(RTRIM(username))`.
* **13 of 16 `hozoor_num` values are space-padded**, 3 are NULL.
* `role` is padded in all 16 rows.
* 7 usernames use **Arabic yeh `ي` (U+064A)**, 2 use **Persian yeh `ی` (U+06CC)**, and
  `main.py` compares with `REPLACE(REPLACE(…, N'ي', N'ی'), N'ك', N'ک')`. Username identity is
  therefore *normalised*, not literal.
* **`hozoor_num` is not unique after normalisation** — 1 duplicate group. Araz `CardNo → user`
  is not a 1:1 mapping today.
* **4 `user_sessions` rows reference a username that exists nowhere in `user_table`**, even after
  trim + yeh/kaf normalisation.

**The plaintext-password finding is the highest-risk item in this audit.** 15 of 16 accounts
authenticate through the plaintext `nchar(10)` column; only `password_hash` (bcrypt, 60 bytes) is
populated for 1 account. A Laravel implementation that only understands `password_hash` locks out
15 users the moment the new login page goes live. `app/core/password_utils.py::verify_password`
implements the dual path; `tools/migrate_passwords.py` exists but has evidently only been run for
one account.

## 8. SQL queries

There is no ORM and no query-builder: **all SQL is handwritten and inline**, across `main.py`
(5 623 lines) and the route modules. Measured characteristics:

* Raw `pyodbc` with **parameter binding** for every user-supplied value. `docs/security/SQL_INJECTION_REVIEW.md`
  records a reviewed 0 unparameterised value interpolations; the only f-string SQL uses
  server-controlled identifiers (`ORDER BY {col}`, and the `f"SELECT COUNT(*) FROM [{sch}].[{name}]"`
  pattern is audit-tooling only).
* Correct concurrency in the newest code: `queue_tickets` numbering uses
  `SELECT ISNULL(MAX(ticket_number),0)+1 … WITH (UPDLOCK, HOLDLOCK)` so two kiosks cannot mint the
  same number; `hozoor` check-in uses `WITH (UPDLOCK, HOLDLOCK)` on the read before write.
* Legacy code is *not* consistently modern: `main.py` opens connections with the module-level
  `pyodbc.connect(_db_connection_string())` instead of `app.core.database.connection()`, and
  `get_hozoor` holds **two** live connections (SQL Server + Access) at once.
* Connection shape: `DRIVER={ODBC Driver 17 for SQL Server};SERVER=localhost\SQLEXPRESS;DATABASE=userDB;Trusted_Connection=yes;`
  overridable with the `DATABASE_URL` environment variable. `Trusted_Connection=yes`
  (Windows authentication) — **there is no DB username/password to migrate.** The brief's
  `DB_USERNAME`/`DB_PASSWORD` are not applicable to the supported deployment.

## 9. Business logic

Where the logic actually lives (this is the migration's core semantic surface):

| Area | Location | Notes |
|---|---|---|
| Attendance read | `main.py::get_hozoor` | joins Access `TPrsInOut` **and** `hozoor`; `weekday_map {0..5}` over `shanbeh…panjshanbeh`; per-month `shiftha` overrides win over `user_table` defaults |
| Attendance write | `main.py::sabt_hozoor`, `sabt_hozoor_checkin`, `sabt_hozoor_checkout` | one row per `(username, date)` in `hozoor` |
| Attendance status | `app/services/attendance.py` | pure function → `not_checked_in` / `checked_in` / `checked_out` |
| Attendance progress ring | `app/services/presence_summary.py` | `build_presence_summary` — scheduled vs worked minutes, **overtime = minutes past `work_end`**, `green_percent`/`blue_percent` |
| Work-hour resolution | `main.py` `resolve_work_hours()` | `shiftha` month/day range first, else weekday column, else `work_hours`, else `00:00-00:00` |
| Overtime requests | `ezafe_table` + 2 triggers | `daily_overtime`, `ezafe_total_table.total_ezafe_time` |
| Hourly passes | `avalpss_table` / `beynpss_table` / `akhrpss_table` + 3 sum triggers → `totalpass_table` | three pass kinds: `aval` (before shift), `beyn` (mid), `akhr` (after) |
| Leave | `mrkhc_table` → trigger → `leave_report` | `remaining_days = 30 − SUM(approved days)` — **the 30 is hard-coded in the trigger** |
| Legacy tickets | `ticket_table` | `Parent_id` makes a threaded conversation |
| New ticketing | `tickets`/`ticket_messages`/`ticket_events` | SLA fields (`first_response_at`, `sla_due_at`) |
| Call queue | `queue_tickets` | **continuous** numbering, never resets per day (documented in the code) |
| Payroll/Karaneh | `admin_payroll_calculations` + `PAYROLL_CALCULATION_TYPES` | read/write of saved calculation payloads; **read-only with respect to payroll computation** |
| Iran-only filter | `app/services/iran_access.py` | IP allow-list gate, 1 600 v4 + 571 v6 ranges + operator exceptions |
| Outage gate | `app/services/outage.py` | configurable target list, interval and failure threshold → serves a maintenance page |
| LAN relay | `app/services/lan_access.py` | byte-transparent HTTP relay with header sanitisation |

**Overtime threshold — the brief's assumption is wrong.** The brief asks to "confirm the
>10-minutes-after-`WorkEnd` threshold in code". No such constant exists. Overtime in this codebase is:

1. **Live on-screen overtime** (`presence_summary.py`): `overtime_minutes = max(0, now − effective_end)`
   where `effective_end` is `work_end` (+24 h if the shift crosses midnight). **Threshold: zero
   minutes**, the ring turns blue the moment the clock passes `work_end`.
2. **Overtime requests** (`ezafe_table`): a *user-submitted* `from_time`/`to_time` pair, summed by a
   trigger into `daily_overtime` and `total_ezafe_time`. No automatic threshold anywhere.

So there is no 10-minute rule to preserve — and none may be invented.

**Karaneh — the brief's assumption is also wrong.** There is no `karaneh` module, table or route.
What exists is `PAYROLL_CALCULATION_TYPES = {"overtime", "comprehensive", "hourly", "summary"}`
(`main.py:3067`) saved through `POST /api/admin/payroll/save` and read by
`GET /api/admin/payroll/load` into `admin_payroll_calculations.payload_json`. The application
**stores and replays** calculations produced elsewhere; it does not compute payroll. "If it is
read-only today, keep it read-only" therefore applies to the whole feature: preserve
`save`/`load` of the JSON payload and nothing more.

## 10. Authentication

Implemented in `app/api/routes/auth.py` + `app/core/sessions.py` + `app/core/session_cookie.py`.

```text
GET  /login                → login.html (CAPTCHA optional)
GET  /api/csrf-token       → CSRF token, also written to a readable csrf_token cookie
GET  /captcha              → server-rendered PNG, code kept in the session only
POST /login_user           → JSON {username, password, captcha}
                             ├ captcha check  (skipped when system_config.captcha_enabled = 0)
                             ├ length caps (MAX_USERNAME_LENGTH / MAX_PASSWORD_LENGTH)
                             ├ per-IP and per-account failure throttling (429)
                             ├ fetch_user_for_login → (username, role, password, password_hash, is_active)
                             ├ account active?  (is_active not in {disabled, inactive, locked, 0, false})
                             ├ verify_password(legacy_plaintext, hash, supplied)
                             ├ session.clear()  ← session-fixation protection
                             ├ mint sid (secure RNG) + register in user_sessions
                             ├ mint CSRF token, store in signed session, write csrf_token cookie
                             ├ set is_admin, is_master_admin = username ∈ MASTER_ADMIN_USERNAMES
                             ├ audit: log_event(action="login") + track_session_login
                             └ redirect: is_master_admin → /master-admin
                                         is_admin        → /admin/dashboard
                                         else            → /user_panel
GET  /logout               → clears session, revokes the server-side session record
```

**Mechanics:**

* **Cookie sessions** signed by `itsdangerous` with `SESSION_SECRET_KEY`/`SECRET_KEY`. The
  application **refuses to start with `DEBUG=False` and no stable session key**.
* **Server-side session registry** in `dbo.user_sessions` (`session_key`, `username`, `ip_address`,
  `user_agent`, `login_at`, `last_activity`, `logout_at`, `is_active`, `terminated_by`) so a session
  can be revoked server-side. 58 rows are currently `is_active = 1`, belonging to only 2 distinct
  usernames (stale rows are never reaped — see §28).
* **Idle timeout** from `system_config.idle_timeout_seconds` (300 s) when
  `idle_timeout_enabled = 1`; absolute `SESSION_MAX_AGE_SECONDS` (28 800 s).
* **Roles** are exactly two: `admin` and `user`, plus the orthogonal master-admin flag derived from
  the `MASTER_ADMIN_USERNAMES` env var (default `ali`). A master admin is **not** a third role in
  the database.
* **Passwords**: bcrypt via `app/core/password_utils.py`, with a legacy-plaintext fallback path.
  `tools/migrate_passwords.py` upgrades a plaintext row to a hash (used for 1 account so far).
* **Password reset** is a **request/approval workflow, not email**:
  `POST /forgot_password` → row in `password_reset_requests` (unified message, no account
  enumeration) → master admin approves in `/master-admin/password-resets` →
  `POST /password-resets/{id}/approve` returns an **8-character** recovery code
  (`A–Z0–9`, `secrets.choice`, TTL from `system_config.password_reset_code_ttl_minutes` = 60 min,
  attempt limit, `HASTAMA_HMAC_SECRET` present or the flow **fails closed**) →
  `POST /reset_password` verifies the code (`hmac.compare_digest`) and sets the new password.
  `recovery_code` is never returned by the history endpoint.

**The 5-digit login verification code does not exist.** There is no second factor after
username/password. The two numeric-code concepts in the system are the 6-character CAPTCHA and the
8-character reset code. See §31.

## 11. Authorization

Two enforcement points, both server-side:

1. **Route rendering** — `main.py` checks the session before returning a page:
   `/master-admin` and `/master-admin/{section}` require `is_master_admin`;
   `/admin*` requires `is_admin`; `/call-management` goes through `_require_call_page_access`
   (master admin only). A failure redirects to `/login` (HTTP 303) or returns 403.
2. **Per-handler guards** —
   `_require_admin`, `_require_auth`, `_ticket_actor`, `get_is_admin_from_session`,
   `get_user_from_session` in `main.py`; `_master_admin()` in `master_admin.py`;
   `_require_admin()` in `araz_api.py`; `_actor(request, admin=…)` in `call_system.py`,
   `notifications.py`, `automation.py`, `ticketing.py`.

Distinct behaviours that must survive the migration:

* `master_admin.py` accepts **only** `is_master_admin` — deliberately **not** `is_admin`. Its
  module docstring explains why: `/password-resets/{id}/approve` returns the recovery code, so
  accepting `is_admin` would be a one-request account takeover. Every rejected attempt is written
  to `security_events` by `_log_denied_master_admin`.
* Ownership checks are per-row and inside handlers, e.g. `main.py:1898` returns 403 when
  `not is_admin and session_user != username` for `/get_user_info`.
* `call_system.py` kiosk writes are **session-free by design**; the boundary is a same-site
  `Origin` check plus a per-IP limit (`CALL_SYSTEM_WRITE_LIMIT = 120/min`), and
  `_guard_queue_pii` adds a 30/min limit for patient PII. These paths are CSRF-exempt, which is
  exactly why the Origin check exists.

## 12. Background jobs

Exactly one scheduled job exists:

* `app/services/background_tasks.py::publish_due_notifications` — every **1 second**, calls
  `notifications._publish_due(cursor)` which flips `notifications.status` to published for rows
  whose `scheduled_at` has passed. The docstring states this is a *safety net*: create/update
  requests publish due notifications themselves.
* A second, non-APScheduler loop: `app/services/outage.py` runs its own monitor with a configurable
  interval, target list and failure threshold, and can log every session out when
  `outage_logout` is enabled.
* `app/services/automation.py` lazily executes `database/automation.sql` on first use
  (a `_SCHEMA_READY` flag + lock), i.e. schema bootstrapping at runtime rather than in a migration.
  The same lazy-`_ensure_schema` pattern exists in `call_system.py` and `notifications.py`.

## 13. Scheduler

`BackgroundScheduler(timezone=utc, daemon=True)` started in the FastAPI lifespan
(`start_background_tasks()`), stopped on shutdown, one interval job. **In-process** — it dies with
the web process and cannot run without it. Laravel needs a documented Windows execution method
(Task Scheduler → `php artisan schedule:run` every minute, or `schedule:work` as a supervised
process); the 1-second cadence has no direct `schedule:run` equivalent and must become either a
1-minute task, a `queue:work` loop, or a publish-on-write path with the sweep as a safety net.

## 14. WebSocket

One endpoint: `GET /api/ws/call-display` (`call_system.py:1743`).

* **Unauthenticated by design** (a TV has no session). The security boundary is: reject the
  handshake when `Origin` is present and does not match `Host` (CSWSH), cap concurrent sockets at
  `MAX_WS_CONNECTIONS`, cap frame size at `MAX_WS_FRAME_CHARS`, rate-limit inbound frames, and
  re-broadcast **only** `{"type":"audio_activated"}`.
* Client protocol: optional first `{"tag": "display"|"preview"}`, then `"ping"` → `{"type":"pong"}`
  keepalive. The `preview` tag exists so the admin preview does not inflate display state.
* Broadcasts sent by the server include `queue_ticket_taken`, call events, reset/refresh display,
  and slide changes, delivered through a `display_manager` connection registry with per-socket tags.

## 15. SSE

Two endpoints, both in `notifications.py`:

* `GET /api/notifications/stream` — per-user notification stream.
* `GET /api/notifications/admin-stream` — admin stream.

They are ordinary SSE (`text/event-stream`) over a plain HTTP response. This matters for
deployment: the LAN relay forwards bytes, so SSE keeps working on the LAN origin; and
`GET /api/notifications/poll` + `GET /api/notifications/unread-count` exist as the polling
fallback. The brief's instruction to "keep SSE or move to WebSocket but never downgrade to page
reloads" is satisfied by preserving all three: stream, admin-stream and poll.

## 16. Notifications

Tables: `notifications` (title, content, type, priority, status, target_type, action_label,
action_url, created_by, `published_at`, `scheduled_at`, `archived_at`, `push_tag`),
`notification_targets` (audience), `user_notifications` (per-user `delivered_at`, `read_at`,
`dismissed_at`), `push_subscriptions` (Web-Push endpoints: `endpoint`, `p256dh`, `auth`,
`last_activity`, `disabled_at`).

API: 10 admin routes (create/update/publish/read/unread/archive/delete/delete-all/targets/list),
9 user routes (list, unread-count, poll, stream, admin-stream, get, read, unread, delete, read-all).
Publishing is status-driven (`scheduled_at` → `published_at`), which is what the scheduler sweeps.
A service-worker-based **offline fallback** (`app/static/sw.js`, `offline.html`) is separate and
only intercepts failed top-level navigations.

## 17. Araz integration

`app/services/araz_connector.py` (797 lines) + `app/api/routes/araz_api.py` + `tools/bridge_agent.py`
+ `tools/bridge_config.json` + `tools/card_mapping.json`.

Two transports, one of them authoritative in production:

1. **Device protocol (Araz T7 native).** `ArazDevice` speaks the vendor protocol directly over
   TCP — header `ARAZREQPROTO0002` / `ARAZRESPROTO0002` (16 bytes), separators FS/GS/RS/US,
   default device `192.168.3.200:1001`, device number 1, record format
   `YYMMDD\tCardNo\tHHMM\tInOutType\tFlag`. Verbs and record types are enumerated in `Verb` /
   `RecordType`. **This path is not used for routine sync** — it exists for device tests, time
   reads/writes and a direct pull.
2. **Access database (authoritative).** `ArazAccessDB` opens
   `DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=<path>;PWD=<ARAZ_ACCESS_PASSWORD>;` and
   reads `SELECT CardNo, Date, Time, InOutType FROM TPrsInOut` (plus `TPrsNames.FirstName/LastName`
   for name resolution). `Araz.exe` writes this MDB; Hastama only reads it.

**Actual paths — the brief's paths are wrong.** The configured default is
`E:\Hastama\database\Arazdb.mdb` (`ARAZ_ACCESS_PATH`), and the file is present (48 631 808 bytes).
There is **no** `D:\python\database\Arazdb.mdb`, no `Perdata.mdb`, and no `Server.ini` in this
project. `T7PrsInOutLast.txt`, `T7PrsInOut.txt` and `T7PrsNames.txt` appear only in historical
investigation documents (`docs/araz/ARAZ_T7_PROTOCOL_REPORT.md`,
`docs/ALL_PROJECT_DOCS.md`) as *analysis of the vendor's own files*, not as files the application
reads. `arazin\` (59 475 files) is vendor/backup material and is not read at runtime.

**The bridge:** `POST /api/araz/bridge-sync` receives
`{"records":[{"username","tarikh","vorood","khorooj"}], "secret": "…"}` from
`tools/bridge_agent.py` running on the PC whitelisted by the device. It authenticates with
`hmac.compare_digest` against `ARAZ_BRIDGE_SECRET`, **fails closed with 503 when the secret is
unset**, is rate-limited to 60/min/IP, caps the batch at `MAX_BRIDGE_RECORDS`, converts Jalali
`tarikh` with `persiantools.jdatetime.JalaliDate`, and writes into `hozoor` using its **own**
connection (`app.core.database.connect`) rather than the process-wide cursor — the comment explains
that sharing the pool connection was a real thread-safety bug.

Other routes: `GET/POST /api/araz/config`, `GET /api/araz/test`, `GET /api/araz/time`,
`POST /api/araz/time/sync`, `GET /api/araz/records`, `POST /api/araz/sync` — all admin-guarded.

## 18. Card-number mapping (`hozoor_num` ↔ `CardNo`)

The mapping is **a plain string equality on `user_table.hozoor_num` vs the Access `CardNo` value**,
with no mapping table in between:

* `get_hozoor` reads `hozoor_num` for the user and queries the MDB with
  `WHERE CardNo = ? AND Date BETWEEN ? AND ?` — passing the **padded** `nchar(10)` value through.
* `tools/card_mapping.json` exists as an operator-written side file; `tools/bridge_agent.py` uses it.
* Hazards the migration must reproduce rather than "fix" silently: **3 users have `hozoor_num = NULL`**
  (they can never match) and **1 pair of users shares a normalised `hozoor_num`** (a card would
  attribute to two people). Both are data problems, not code problems; they must be *reported*, and
  any decision to change them is an operator decision, recorded in `OPEN_QUESTIONS.md`.

## 19. Overtime

See §9. Summary: on-screen overtime has a **zero** threshold measured against `work_end`; overtime
*requests* are user-submitted time ranges in `ezafe_table`, summed by triggers
(`trg_CalculateDailyOvertime`, `trg_UpdateTotalOvertime`). Admin approval routes:
`/get_overtime_requests`, `/update_overtime_status`, `/update_overtime_Indivisual_status`,
report pages `/overtime_report_page`, `/overtime_report`, `POST /get_overtime_report`.

## 20. Printing

`app/services/printer.py` (295) + `app/services/ticket_print.py` (778) + `app/services/label_render.py`
(370) + `app/services/label_render` template `label_print_document.html` + `label-print.css` +
`app/static/js/label-system.js`.

* **Enumerated real printers** are read from the Windows spooler (`raw_printers()`), and the
  configured target lives in `system_config.label_target_printer` = **`EPSON TM-T88III Receipt`**.
  The network queue form `\\192.168.3.31\EPSON TM-T88III Receipt` is documented in the module
  docstring. `ZDesigner TLP 2844` and `EPSON TM-T88IIIP` appear only in the brief and in legacy
  documents — **not** in the running configuration.
* **No ESC/POS byte stream is generated.** The primary path is:
  HTML → render → PNG/PDF via Microsoft Edge headless → `System.Drawing.Printing` /
  `System.Printing` in PowerShell → spooler, with an explicit
  `PageMediaSize(Unknown, w*100, h*100)` in 1/100 mm and the image drawn into that rectangle.
  Fallbacks in order: `edge-lp` (POSIX `lp`), `edge-printto` (Edge `--print-to`), then
  `_print_text_windows` (`$Input | Out-Printer`).
* **Label calibration lives in data, not code**: `system_config.label_print_settings` =
  `{"width_mm":76,"height_mm":105,"template":"queue","rotate":false,"show_name":true,"show_time":true,"show_hint":true,"layout_version":6}`.
  These are the values that must not change.
* `_print_pdf_windows` uses `_ps_quote()` and `Start-Process -Verb PrintTo -ArgumentList …`; the
  PowerShell is generated per request from the HTML, so server-side printing is intrinsically tied
  to the Windows host — the migration must keep a **local printing component** (the brief's
  "local printing architecture" requirement is unavoidable) or run it as a small helper service.

## 21. Call management

`app/api/routes/call_system.py` (1 832 lines), templates `call-management.html`,
`call-display.html`, `ticket-kiosk.html`, JS `call-system-standalone.js`, `call-display.js`,
CSS `call-system-standalone.css`, `call-display.css`, `call-tokens.css`.

* **Two distinct queues.** (a) `reception_calls` + `display_queue` + `waiting_queue` for the
  "call a patient to a desk" flow driven by `/api/calls*`; (b) `queue_tickets` for the
  **numbered ticket** flow driven by `/api/queue/*`. Both broadcast to the same WebSocket display.
* **Numbering is continuous and global, not per-day:** `SELECT ISNULL(MAX(ticket_number),0)+1 FROM
  dbo.queue_tickets WITH (UPDLOCK, HOLDLOCK)` with no date filter, deliberately (the code comment
  states a queue is a continuous stream and the date is stored only for daily reporting).
  `ticket_date` is still recorded. The brief's "1–2000" is different: `2000` appears only as
  `total_expected` for the *exhaustive-queue* check in `/api/calls/audio-status` and as an input
  range bound (`if number < 1 or number > 2000`). It is **not** the queue's numbering range.
* Services offered include **«نمونه‌گیری»** (`queue_tickets.service`), and `display_queue` holds
  `slot_position` for the TV layout. `MAX_DISPLAY_SLOTS` drives slot shifting when a call is
  removed.
* `slides` table + `/api/calls/slides*` provide the rotating image slideshow on the display,
  managed by an admin (`_require_admin`).
* Persian numerals are produced by `to_persian_numbers()` for every number shown on screen.

## 22. Admin functionality

`/admin` shell (`admin.html` + `admin.js`) with sections
`ADMIN_SECTIONS = {dashboard, coworkers, vacation, overtime, hourly-pass, tickets, shifts, attendance, payroll}`
plus non-section endpoints: `add_user`, `update_user`, `get_shifts`, `add_shift`, `update_shift`,
`delete_shift`, `get_active_shifts`, `get_leave_requests`, `update_leave_status`,
`generate_individual_report`, `fetch_user_data`, `get_hourly_pass_requests`,
`change_hourly_pass_status`, `update_hourly_pass_status`, `get_overtime_requests`,
`update_overtime_status`, `update_overtime_Indivisual_status`, `get_ticket_requests_admin`,
`delete-ticket`, `update_ticket`, `get_ticket_requests`, `update_ticket_status`,
`get_hozoor/{username}`, `get_hozoor_today`, `get_hozoor_filtered`, `sabt_hozoor*`,
`api/admin/employment-status`, `api/admin/payroll/save|load`, report pages
(`leave_report_page`, `hourlypass_Report_page`, `overtime_report_page`, `payroll_report_page`,
`final_report_page`, `download_pdf`), `get_users`, `get_user_info`,
`get_user_info_final_report_page/{username}`, `get_leave_info`, `get_receivers`,
`upload-profile-image`, `delete-profile-image`, `add_ticket_response`.

The **master-admin control centre** (`/master-admin`, `master-admin.html`, `master-admin.js`) is a
separate 57-route plane: dashboard stats/activity, subscriptions, audit logs, users, sessions
(incl. bulk terminate), password resets, security events, errors, admin actions, system health,
search, tickets, config, LAN access, outage, Iran access, login experience, printers, notification
administration, and internal automation.

## 23. User functionality

`/user_panel` (`user-panel.html` + `user-panel-script.js`): today's presence ring
(`build_presence_summary`), check-in/check-out, attendance history, leave request + status,
hourly pass (three kinds) request + status, overtime request + status, ticket create/reply,
announcements, notifications, profile (incl. `upload-profile-image`/`delete-profile-image`),
work schedule (`get_shifts`, `get_active_shifts`), reports, plus `/register` for self-registration
(`user_registration_requests`, admin-approved) and `/rules` + `/training*` content pages.

## 24. File uploads

| Upload | Endpoint | Storage | Limits |
|---|---|---|---|
| Profile image | `POST /upload-profile-image` | `app/static/uploads/` | **5 MB** (documented in `Caddyfile` and enforced in code) |
| Ticket attachment | `POST /api/tickets/{id}/attachments` | `TICKETING_PRIVATE_DIR` = `app/private_uploads/tickets` — **outside `/static`, never web-served** | **10 MB** |
| Automation attachment | `POST /api/automation/...` | conversation-scoped | same family of checks |
| Display slide | `POST /api/calls/slides/upload` | `app/static/slides/` | admin only |
| Registration national-ID document | `POST /registration/submit` | `user_registration_requests` | public, rate-limited 5/10 min/IP |

Ticket attachment download goes through `GET /api/tickets/{id}/attachments/{attachment_id}`, which
authorises the requester before streaming — the file itself is not reachable by URL.
`docs/security/SECURITY_CONTROL_MATRIX.md` records extension, MIME, size and magic-byte checks;
the migration must keep all of them (see §25).

## 25. Security mechanisms

Already implemented (this is the bar the migration must not fall below):

* **CSRF** — `_CSRFMiddleware` in `main.py`, session-bound token, `X-CSRF-Token` header checked
  against the signed session, plus a readable `csrf_token` cookie for the JS interceptor. The
  ticket-kiosk and call paths are CSRF-exempt **only** because they are Origin-checked and rate-limited.
* **XSS** — `dom-escape.js::escapeHtml` for client-built DOM; server-side Jinja autoescaping; tests
  assert no `innerHTML` receives unescaped user data in the audited modules.
* **SQL injection** — parameter binding everywhere for user values
  (`docs/security/SQL_INJECTION_REVIEW.md`).
* **Rate limiting** — `app/core/rate_limit.py` for login failures (per IP and per account),
  registration submit, `bridge-sync` (60/min), kiosk writes (120/min), queue PII (30/min).
* **Sessions** — signed cookie + server-side registry, rotation on login, idle timeout, absolute
  lifetime, revocation on logout/password-reset/session-terminate, `Secure`/`HttpOnly`/`SameSite`
  with a documented LAN exception (`_LanCookieMiddleware`).
* **Real client IP** — a single trust model in `app/core/net.py`: `TRUSTED_PROXY_IPS`
  (default `127.0.0.1,::1`), last valid `X-Forwarded-For` entry, and the LAN relay *strips and
  rewrites* every forwarding header so the LAN cannot poison rate limits or the audit log.
* **Security headers** — `_SecurityHeadersMiddleware` (HSTS, `nosniff`, frame options, referrer
  policy); `--no-server-header` on the production uvicorn command line; `TrustedHostMiddleware`
  with `HASTAMA_ALLOWED_HOSTS`.
* **Host allow-list** — only `hastama.ir`, `www.hastama.ir`, `localhost`, `127.0.0.1`, `::1`
  are answered.
* **Access gates** — `_IranOnlyGateMiddleware` (Iran-only IP policy, with `EXEMPT_PREFIXES` and
  `JSON_PREFIXES` so API clients get JSON instead of HTML) and `_OutageGateMiddleware`
  (incident-mode maintenance page).
* **Audit/observability** — `audit_logs` (3 509 rows), `security_events`, `admin_actions` (133),
  `system_errors`, and a security/audit retention policy
  (`audit_retention_days` 365, `security_retention_days` 730).
* **Secrets** — plaintext `nchar(10)` passwords are the outstanding weakness (see §7, §28);
  bcrypt is the target; recovery codes are HMAC-protected and fail closed.

## 26. Existing tests

40 modules under `tests/`, **879 tests collected**. Latest full run:

```text
866 passed, 13 failed, 4 skipped   (~20 s)
```

The 13 failures are **pre-existing** and unrelated to any migration work — they are asset/CSS
ordering and DOM-shape assertions plus two ticketing-service failures:
`test_dark_theme.py::test_dark_theme_css_is_last_stylesheet` (×4),
`test_user_panel_surfaces_have_dark_rules` (×3),
`test_modals_and_popups_have_dark_rules[.popup-massageBox]`,
`test_final_report_print.py::test_no_screen_only_decoration_in_print_layer`,
`test_responsive_tables.py::test_every_table_has_a_mobile_pattern`,
`test_ticketing_service.py` (×2),
`test_user_panel_theme.py::test_user_panel_dark_mode_rules_cover_core_surfaces`.

Largest modules: `test_security_hardening.py` (114), `test_security_regressions.py` (62),
`test_iran_access.py` (51), `test_label_printer_api.py` (50), `test_ticket_print_server.py` (41),
`test_lan_access.py` (39), `test_outage_page.py` (38), `test_client_hardening.py` (37).

They are **not** deleteable: they encode real invariants (secret handling, session bulk actions,
label geometry, LAN header sanitisation, production supervision, dev-session scripts, Persian
console encoding). The migration must re-express their intent as PHPUnit/Pest + Vitest tests and
record old/new behaviour in the regression table (§58 of the brief).

`tests/conftest.py` **fakes `pyodbc`**, so no test touch a real SQL Server. Real-row behaviour
(trailing-space matching, `nchar` semantics, `DELETE FROM dbo.user_sessions`, trigger side effects)
is verified by the Python app at runtime but **not** by the suite.

## 27. External dependencies

Python (`pyproject.toml`): `fastapi`, `uvicorn==0.23.2`, `pydantic>=2`, `requests`, `loguru`,
`joblib`, `scikit-learn`, `pyodbc`, `jdatetime`, `jinja2`, `pdfkit`, `persiantools`,
`itsdangerous`, `python-multipart`, `apscheduler>=3.10.4,<4`, `websockets`, `bcrypt`.

Runtime/system dependencies **outside** the Python package set — these are the ones a Laravel
migration can actually lose:

| Dependency | Used by | Migration impact |
|---|---|---|
| **ODBC Driver 17 for SQL Server** | every DB access | same driver works from PHP |
| **Microsoft Access Driver (*.mdb, *.accdb)** + `Arazdb.mdb` | Araz attendance | PHP has **no** supported Access ODBC path in this stack — needs a decision (see §31) |
| **Microsoft Edge headless** (`msedge --headless --print-to-pdf`) | receipt/label rendering | must be shelled out from PHP |
| **Windows print spooler** (PowerShell `System.Printing`) | label + receipt printing | must be shelled out from PHP |
| **pillow** (implicit, via CAPTCHA) | `app/services/captcha.py` generates PNGs | Laravel needs an image library or a rewritten CAPTCHA |
| **`persiantools` / `jdatetime`** | Jalali conversion everywhere | PHP equivalent needed (`morilog/jalali` or ICU) |
| **Wkhtmltopdf/pdfkit** | `GET /download_pdf` | needs a PHP PDF path |
| **cloudflared** (Windows service) | public ingress | unchanged |
| `HASTAMA_MINIFY_CLIENT_ASSETS` minifier | production asset serving | replaced by Vite |

Unused project scaffolding: `Dockerfile`, `docker-compose.yml`, `Makefile`'s docker targets,
`Caddyfile` (explicitly retired, "not loaded by any service on the production host"),
`arazin\` (59 475 files), `notebooks\`, `ml\`.

## 28. Environment variables

`.env` keys actually present on the server:

| Key | Purpose |
|---|---|
| `SECRET_KEY` | session signing (`SESSION_SECRET_KEY` wins if both are set) |
| `DEBUG` | must be `False` in production; the app refuses to start with `False` and no key |
| `MODEL_PATH`, `MODEL_NAME`, `MEMOIZATION_FLAG` | ML scaffolding, not used by any request path |
| `HASTAMA_HMAC_SECRET` | recovery-code HMAC — **empty disables password recovery (fails closed)** |
| `ARAZ_ACCESS_PASSWORD` | Access MDB password; `get_hozoor` raises without it and falls back to SQL data |
| `HASTAMA_ALLOWED_HOSTS` | `TrustedHostMiddleware` allow-list |
| `ARAZ_ACCESS_PATH`, `TRUSTED_PROXY_IPS`, `SESSION_MAX_AGE_SECONDS`, `MASTER_ADMIN_USERNAMES`, `TICKETING_PRIVATE_DIR`, `ARAZ_BRIDGE_SECRET`, `DATABASE_URL`, `DB_CONNECT_TIMEOUT` | documented in `.env.example` |

Deliberately **absent**, because the deployment does not use them: `DB_USERNAME`, `DB_PASSWORD`
(Windows trusted connection), and any DB host other than `localhost\SQLEXPRESS`.

Runtime settings that live in the **database** (`system_config`, 12 rows) and therefore are *not*
`.env` concerns: `audit_retention_days=365`, `captcha_enabled=1`, `idle_timeout_enabled=1`,
`idle_timeout_seconds=300`, `label_print_settings={…76×105mm…layout_version:6}`,
`label_target_printer=EPSON TM-T88III Receipt`, `lockout_duration_minutes=30`,
`max_login_attempts=5`, `outage_manual=0`, `password_reset_code_ttl_minutes=60`,
`security_retention_days=730`, `session_timeout_minutes=480`.
`lan_access_enabled`, `iran_only_*`, `outage_*` and `login_experience_*` have **no row** —
they use code defaults.

## 29. Deployment architecture

```text
HastamaServer      scheduled task, LogonType=Password, RunLevel=HighestAvailable, user <…>-500
                   runs scripts\راه‌اندازی_سرور_تولید.bat  →  uvicorn 127.0.0.1:5000
HastamaWatchdog    scheduled task → scripts\watchdog_server.ps1
                   APPLICATION_DOWN / RESTART_FAILED / RECOVERY_CONFIRMED / PUBLIC_HEALTH_OK
cloudflared        Windows service, AUTOSTART, LocalSystem → http://127.0.0.1:5000
LAN relay          in-process, toggled by system_config.lan_access_enabled (no row = off)
LAN firewall       scripts\lan_access_firewall.ps1 (one-time, administrator)
```

Constraints discovered in production and relevant to Laravel's Windows deployment:

* The production launcher rotates a boot log, waits for SQL Server, sets the UTF-8 environment and
  `HASTAMA_MINIFY_CLIENT_ASSETS=1`, and starts uvicorn with `--proxy-headers`,
  `--forwarded-allow-ips 127.0.0.1` and `--no-server-header`.
* `scripts\run_server.bat` currently exists as an **ASCII bridge** because naming a batch file in
  Persian broke the scheduled task's command line during an earlier reorganisation. A `.bat` cannot
  reference a Persian-named sibling (child command lines are built through the console code page,
  so even `chcp 65001` does not help); `findstr` cannot open such files either. The task must be
  re-registered against the Persian launcher by an administrator.
* Operator actions that require administrator rights today: enabling autostart, opening/closing the
  LAN firewall port, re-registering the scheduled task.

## 30. Known technical debt

| # | Debt | Evidence | Consequence for the migration |
|---|---|---|---|
| 1 | **15/16 passwords are plaintext** | `password_hash` NULL for 15 rows | must keep legacy verification working; **never** log or bulk-print |
| 2 | `user_table.id` is not an identity column | `IDENTITY` absent, `id` supplied by `add_user` | Laravel `Model::create()` cannot rely on auto-increment |
| 3 | Trailing-space `nchar` legacy data | 11 usernames, 13 `hozoor_num`, 16 `role` padded | every comparison must trim; a naive `where('username',$u)` will fail |
| 4 | Arabic/Persian yeh + kaf variants | 7 vs 2 usernames use different yeh | identity is normalised, not literal |
| 5 | `hozoor_num` not unique | 1 duplicate group | Araz attribution is ambiguous for those users |
| 6 | 3 users have NULL `hozoor_num` | — | they can never receive device attendance |
| 7 | `chrshanbeh` (user_table) vs `chaharshanbeh` (shiftha) | column mismatch | the weekday mapping must handle both names |
| 8 | 58 stale `is_active` sessions for 2 users | `user_sessions` | reaping logic must not be "fixed" into a security change |
| 9 | 4 dangling session usernames | normalisation-proof | evidence that user deletion does not clean sessions |
| 10 | 9 triggers hold business logic | `leave_report` 30-day rule, overtime sums, pass totals | Laravel migrations must not touch them; app code must not duplicate them inconsistently |
| 11 | Lazy `_ensure_schema` at runtime | `automation.py`, `call_system.py`, `notifications.py` | schema bootstrap must move to real migrations |
| 12 | `main.py` is 5 623 lines | 87 routes + all middleware | the split into controllers/services is the bulk of Phase 5 |
| 13 | Two ticket systems | `ticket_table` and `tickets`/`ticket_messages` | both are live; both must migrate |
| 14 | Two attendance sources | Access `TPrsInOut` + `hozoor` | merge semantics must be preserved exactly |
| 15 | Doubled connections | `get_hozoor` holds SQL Server + Access links | connection lifecycle must be explicit in PHP |
| 16 | CSS duplication + fragile ordering | 45 245 lines, `dark-theme.css` must be last | a component-scoped rebuild must keep dark rules winning |
| 17 | No front-end build or tests | no `package.json`, `tests/js` is minimal | Vite + Vitest are genuinely new infrastructure |
| 18 | 13 pre-existing test failures | dark theme / responsive tables / final report / ticketing | must be triaged, not inherited silently |
| 19 | Retired files still shipped | `Caddyfile`, `Dockerfile`, `docker-compose.yml` | candidates for confirmed-unused removal only after review |
| 20 | In-process scheduler | 1-second APScheduler interval | needs a documented Windows execution method |

## 31. Corrections to the migration brief

The brief is authoritative about *intent*; these specific factual assumptions do not match the
codebase and must not be implemented as written.

| Brief said | Reality | Action |
|---|---|---|
| "5-digit login verification code after username/password" | **Does not exist.** Login is CAPTCHA (6 chars, `system_config.captcha_enabled`) + username/password. The only numeric codes are the 6-char CAPTCHA and the **8-char** password-reset recovery code | Do **not** invent a 5-digit step. Preserve the CAPTCHA + password flow and the 8-char reset-code workflow |
| "`username` may be SQL Server `nchar(10)`" | `username` is `nvarchar(50)`; it is **`password`** and `hozoor_num`/`role` that are `nchar(10)` — and the *stored data* still carries legacy padding | Trim on read, parameterise with trimmed values; never assume `nchar` on `username` |
| ">10 minutes after WorkEnd" overtime threshold | **No threshold exists.** Live overtime starts at `work_end` (0-minute threshold); overtime *requests* are user-submitted ranges | Preserve zero-threshold live overtime; do not add a 10-minute rule |
| "Karaneh … check `PAYROLL_CALCULATION_TYPES`" | No Karaneh module exists. `PAYROLL_CALCULATION_TYPES = {overtime, comprehensive, hourly, summary}` only **stores/replays** payloads | Migrate `save`/`load` of saved calculations only; do not add payroll computation |
| Araz paths `D:\python\database\Arazdb.mdb`, `Perdata.mdb`, `Server.ini`, `T7PrsInOut*.txt` | Actual: `E:\Hastama\database\Arazdb.mdb` (48 MB, present). The `.txt`/`Perdata.mdb`/`Server.ini` items exist only inside historical vendor-analysis documents | Use the real path and the real `TPrsInOut` query; treat the `.txt` description as vendor documentation |
| Printers `EPSON TM-T88IIIP`, `ZDesigner TLP 2844` | Configured target is `EPSON TM-T88III Receipt`; printers are enumerated live from the spooler. The other two names appear only in the brief and legacy docs | Read the printer list from the OS; keep `label_target_printer` as data |
| "raw/ESC-POS" printing | No ESC/POS bytes are produced. HTML → Edge headless → PNG/PDF → `System.Printing`/`System.Drawing.Printing` → spooler, with 1/100 mm `PageMediaSize` | Port the render+print pipeline, not an ESC/POS encoder |
| Call numbering "possibly 1–2000" | Numbering is `MAX(ticket_number)+1` over the **whole** table with `UPDLOCK/HOLDLOCK`; it never resets. `2000` is only a `total_expected` figure and an input bound | Preserve continuous global numbering |
| `DB_USERNAME`/`DB_PASSWORD` in `.env` | The deployment uses `Trusted_Connection=yes` (Windows auth) | Keep trusted connection; do not introduce SQL auth |
| `notes`/`session_timeout_minutes` as an env var | `session_timeout_minutes=480` lives in `system_config`, as do 11 other runtime settings | Read settings from the DB; keep only secrets in `.env` |
| "`user_table` … `chaharshanbeh`" | The actual column is **`chrshanbeh`** | Map both spellings explicitly |
| "`nchar(10)` … handle trailing-space padding so auth does not break" — as a *maybe* | It is not a maybe: 11 usernames are affected in live data, and 4 session rows already fail to match any user | Treat trimming as a **required** compatibility behaviour |

## 32. Features that must be preserved

Every item below exists in the code today; none may be dropped or simplified.

**Identity & access:** login with optional CAPTCHA; generic failure messages (no user enumeration);
per-IP and per-account throttling; session rotation; server-side session revocation; logout;
admin vs user roles; the separate master-admin plane that rejects plain admins; rejected
master-admin attempts written to `security_events`; password request/approval/reset with an 8-char
HMAC recovery code and fail-closed behaviour; 2FA-free login exactly as it is today; self-registration
with admin approval.

**Attendance:** dual-source attendance (Access `TPrsInOut` + `hozoor`); the four-slot day model
(`EntryTime`, `ExitTime`, `EntryTime2`, `ExitTime2` from the sorted first four punches); `shiftha`
per-month weekday overrides beating `user_table` weekday defaults beating `work_hours`;
the `chrshanbeh`/`chaharshanbeh` mapping; check-in/check-out; the presence ring semantics
(green = worked, blue = overtime, zero threshold); `hozoor_num` → `CardNo`; the empty-day padding
of the report range; Jalali date entry.

**Requests:** leave (with `mrkhc_table` + trigger-maintained `leave_report`, 30-day year),
three hourly-pass kinds (`aval`/`beyn`/`akhr`) with `totalpass_table` totals,
overtime requests with `ezafe_table` daily/total sums, and the substitution field.

**Call system:** both queues; continuous ticket numbering; `نمونه‌گیری` and the other services;
display slots and slot shifting; slides slideshow; the kiosk working without a login under
same-site Origin + rate limit; WebSocket push to TVs; `audio_activated`; full-screen readable
display; Persian numerals.

**Notifications:** scheduled publish, targets, per-user read/unread/dismiss, archive, delete-all,
SSE stream, admin stream, polling fallback, Web Push subscriptions.

**Ticketing:** the legacy `ticket_table` thread model **and** the new
`tickets`/`ticket_messages`/`ticket_events` model with SLA fields, attachments served through an
authorising endpoint, categories/tags, and both admin and user sides.

**Admin & control centre:** all 9 admin sections; the 57 master-admin API routes; audit logs;
security events; system errors; admin actions; subscriptions; system health; global search;
session management incl. bulk terminate; printer administration; LAN/outage/Iran-access/login
experience settings; internal automation conversations.

**Platform behaviours:** Iran-only IP gate with an operator supplement file; outage/maintenance
gate; the offline service-worker fallback; LAN relay with header sanitisation and the HTTP-only
cookie exception; trusted-proxy client-IP model; host allow-list; security headers; content
minification switch; the watchdog/autostart supervision contract; label + receipt printing with
the stored 76×105 mm calibration; `sw.js`; local Vazir font; Persian RTL and the dark theme;
Jalali/Gregorian handling.

**Branding (exact strings must survive):** `samanehlogo.png`/`lab-logo.png`/`newlogo.png`,
«آزمایشگاه دکتر امینی», «همگام با تکنولوژی روز، به پشتوانه تجربه دیروز».

## 33. Potential obsolete code — identified, **not removed**

Nothing may be deleted before its replacement is Implemented → Tested → Verified. Candidates,
with the evidence that makes them candidates:

| Candidate | Evidence |
|---|---|
| `Caddyfile` | header says "RETIRED … not loaded by any service on the production host" |
| `Dockerfile`, `docker-compose.yml` | no container runs; `Makefile` `deploy` targets are unused |
| `Makefile` `run-lan` target | prints instructions for the retired Caddy topology |
| `arazin\` (59 475 files) | vendor/backup material, not read by the application |
| `notebooks\`, `ml\` model scaffolding | `MODEL_PATH`/`MODEL_NAME`/`MEMOIZATION_FLAG` are read from `.env` but no request path uses a model |
| `app/api/routes/araz_api.py::ArazDevice` device-protocol path | the Access MDB path is the production source; the device protocol is used for tests/time reads |
| `akhrpss_table` | 0 rows — but its triggers are live and its feature is reachable; **not** a removal candidate |
| `test_table`/`ticket_table` legacy ticketing | 1 row, still reachable from the admin panel; **not** a removal candidate |
| `app/data/iran_ip_ranges.txt` regeneration scripts (`scripts/refresh_iran_ip_ranges.py`) | live; keep |
| Legacy docs under `docs/` | superseded reports; keep as history, do not ship to users |

## 34. Open questions for the operator

Recorded in full in [docs/migration/OPEN_QUESTIONS.md](docs/migration/OPEN_QUESTIONS.md). The ones
that block or shape Phase 2:

1. **Araz from PHP.** PHP has no supported Microsoft Access ODBC driver in this stack.
   Options: (a) keep a small Python or .NET helper process solely for the MDB read and document it
   as the one permitted non-Laravel process, (b) install a commercial Access ODBC driver for PHP,
   (c) migrate `TPrsInOut` into SQL Server with a replicated reader. The 15 plaintext passwords are
   unaffected by this; the brief's definition of done explicitly allows a documented Python process
   for an external integration, so (a) is the low-risk default.
2. **Legacy plaintext passwords.** Upgrade-on-first-successful-login (recommended, no lockout) vs a
   bulk `tools/migrate_passwords.py` run with an operator-reviewed inventory.
3. **`hozoor_num` integrity.** 1 duplicate group and 3 NULLs — fix the data, or preserve and report?
4. **Serving strategy.** Replace the running site in place, or run Laravel on a second port behind
   the same Cloudflare Tunnel for side-by-side regression comparison (recommended: the regression
   table in §58 of the brief needs both implementations alive).
5. **Pre-existing test failures.** Triage and fix in the Python app first, or carry them into the
   parity matrix as known differences?

---

## 35. Corrections found during Phase 2

Further brief assumptions were checked against the code while the new application was scaffolded.
All four are recorded here so the correction lives with the audit that made the claim.

| Brief said | Reality (verified) |
|---|---|
| Branding slogan «همگام با تکنولوژی **روز**، به پشتوانه تجربه دیروز» | The real string is «همگام با تکنولوژی **امروز**، به پشتوانه تجربه دیروز», and it is `clinic_slogan` in `app/services/label_render.py:236` and `app/services/ticket_print.py:259`. It appears on **printed labels and receipts only** — no web page shows it, so it must not be added to the web chrome |
| `samanehlogo.png` | **Does not exist anywhere in the repository.** The real logo assets are `app/static/images/lab-logo.png`, `newlogo.png`, `login-mobile-logo.png`, `login-mobile-logo-dark.png` |
| «آزمایشگاه دکتر امینی» used for branding | True, and it is used in exactly one place: the `cs-header-sub` subtitle of `app/templates/call-management.html:42` |
| A health endpoint is a detail | **It is a supervision contract.** `scripts/watchdog_server.ps1` requires `GET http://127.0.0.1:5000/health` → 200, the public check uses `https://hastama.ir/health`, and the FastAPI handler returns `{"status":"ok"}` **without touching the database** on purpose (the script's own comment: "the port is listening is NOT a health condition"). `GET /health/database` is the separate DB probe and answers `503 {"status":"error"}` on failure. Both shapes are reproduced byte for byte in Laravel |

Two further findings that change how the new application must be built:

* **Laravel 13 hard-codes `config('app.timezone') = 'UTC'`** — there is no `APP_TIMEZONE` binding, so
  setting one in `.env` silently does nothing. UTC is also what this database already stores
  (`SYSUTCDATETIME()` defaults), so it is kept. The Tehran wall clock matters only at date
  boundaries ("today's attendance", Jalali month edges) and will get an explicit helper in the
  attendance phase rather than a global timezone switch that would make Carbon reinterpret every
  stored datetime.
* **`pdo_odbc` cannot carry Persian text.** Laravel supports reaching SQL Server through ODBC
  (`'odbc' => true` + `'odbc_datasource_name'`, with a dedicated `processInsertGetIdForOdbc` using
  `SCOPE_IDENTITY()`), and it does connect — 44 tables, instance `SQLEXPRESS`. But Windows' ANSI
  ODBC layer converts `nvarchar` to the client code page: a value the server stores correctly as
  `45062F06CC063106CC06EC…` («مدیریت») came back from PDO as `"??????"`. `Client_CSet=UTF-8` and
  `ClientCharset=UTF-8` make no difference. Since the whole UI is Persian, the Unicode-native
  `pdo_sqlsrv` driver is required; the ODBC path is rejected with evidence.

## Audit method and limits

* Every route, template, JS module, CSS file, table, column, index, trigger and row count in this
  document was **extracted mechanically** from the code or queried from the live database — none of
  the numbers are estimates.
* `arazin\` (59 475 files) and `notebooks\` were not read in depth; they are vendor/reference material
  and are not on any request path.
* The Access `TPrsInOut` table was **not** queried with real data: `ARAZ_ACCESS_PASSWORD` is
  configured but the machine may not have the Access ODBC driver active at audit time. The query
  text and the column names come from the source, not from a live read.
* Front-end runtime behaviour was read from source, not observed in a browser. The template and JS
  inventories are therefore structural, not behavioural; behavioural parity claims stay `Pending`
  until the regression pass.
* No file in `app/`, `tests/`, `scripts/` or `database/` was modified by this audit. The only
  changes to the workspace are this document, `MIGRATION_STATUS.md`, the five files under
  `docs/migration/`, three lines in `.gitignore`, and the throw-away `.audit/` directory.

## Regenerating these inventories

The extraction harness lives in the git-ignored `.audit/` directory and is deliberately
re-runnable, so the inventories can be refreshed against the live tree and the live database
instead of being hand-maintained (hand-maintained inventories drift, and a drifted inventory is
worse than none):

```bash
cd /e/Hastama
PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe .audit/dump_schema.py    # → .audit/schema.json
PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe .audit/routes.py         # → .audit/routes.json
PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe .audit/frontend.py       # → .audit/frontend.json
PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe .audit/routes_md.py      # → ROUTE_INVENTORY.md
PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe .audit/gen_docs.py       # → schema/triggers/template/JS
```

The probe scripts (`pw.py`, `pad.py`, `norm.py`) produced the password, padding and
normalisation findings in §7; each one prints **counts only**, never a username or a password.
