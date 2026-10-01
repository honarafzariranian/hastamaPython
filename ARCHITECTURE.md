# ARCHITECTURE — Hastama on Laravel + Vue 3

This document describes **what exists today**. Nothing here is aspirational: every path, port and
command below has been run. Features not yet migrated are listed as `Pending` in
[MIGRATION_STATUS.md](MIGRATION_STATUS.md) rather than described here.

Current status: **Phase 2** (structure, environment, SQL Server wiring, Vue foundation shell).
The legacy FastAPI application is still the production application and is deliberately untouched.

---

## 1. Where the new application lives

```text
E:\Hastama\                     the repository root
├── app\  tests\  scripts\ ... the running FastAPI application (still production)
├── docs\migration\             the migration inventories
├── MIGRATION_AUDIT.md          Phase 1 deliverable — the current system, described
├── MIGRATION_STATUS.md         the tracker
├── ARCHITECTURE.md             this file
└── laravel\                    ← the new application
    ├── app\{Console,Events,Exceptions,Http\{Controllers,Middleware,Requests},Jobs,
    │        Models,Notifications,Policies,Services,Support}
    ├── bootstrap\app.php
    ├── config\
    ├── database\{migrations,migrations-disabled}
    ├── public\{fonts,images,build}
    ├── resources\{css\app.css, js\…, views\app.blade.php}
    ├── routes\{api.php,web.php,console.php}
    └── tests\{Unit,Feature}
```

The new application is a **subdirectory**, not the repository root, on purpose: the Python
application's launcher scripts, `.env` and paths all reference `app\main.py` from the root, so
relocating them would break production. The tree is promoted to the root only at Phase 17, after the
replacement is Verified.

A consequence worth stating plainly: the brief's expected `app/{Console,…}` layout is satisfied
*inside* `laravel/`, and the repository now has two `app/` directories with different meanings
(`E:\Hastama\app` = Python source, `E:\Hastama\laravel\app` = Laravel source). That is the price of
keeping the old system running while the new one is built.

## 2. Side-by-side runtime

```text
cloudflared ──▶ 127.0.0.1:5000   FastAPI (uvicorn)      ← PRODUCTION, unchanged
                                 launched by the \HastamaServer task

                127.0.0.1:8000   Laravel (php artisan serve)   ← the new application
```

Verified: port 5000 is owned by `python.exe` and answers `/health` with `200` while the new
application runs on 8000. The two never share a port, a process or a database *connection*.

At cutover the tunnel target moves from 5000 to 8000 and the apex hostname is switched — a one-line
change, which is what makes the agreed side-by-side rollout cheap to reverse.

## 3. Stack

| Layer | Choice | Version on this machine |
|---|---|---|
| Language | PHP | 8.5.1 (NTS, VC17, x64) |
| Framework | Laravel | 13.34.0 |
| Frontend | Vue | 3.5 with `<script setup>` |
| Build tool | Vite | 8.3 (via `laravel-vite-plugin` 3.x) |
| Routing | Vue Router | 4.x, `createWebHistory` |
| State | Pinia | 3.x |
| HTTP | axios | 1.x |
| Styling | Tailwind CSS 4 + ported design tokens | 4.x |
| Frontend tests | Vitest + @vue/test-utils + jsdom | configured, no tests yet |
| Database | Microsoft SQL Server Express | instance `SQLEXPRESS`, database `userDB` |
| Database driver | `pdo_sqlsrv` + `sqlsrv` (Microsoft Drivers) | 5.13.3, built for PHP 8.5 NTS x64 |
| Cache / queue / session | file / sync / file | no database drivers, deliberately |
| Public ingress | Cloudflare Tunnel | unchanged |

## 4. Request flow

```text
Browser
  │
  ├─ GET /…  ─────────────────▶ Laravel
  │                              routes/web.php
  │                              Route::fallback → resources/views/app.blade.php
  │                              (the SPA shell: RTL, local font, theme bootstrap, @vite)
  │                            Vue Router then takes over in the browser
  │
  └─ GET /api/… ──────────────▶ Laravel
                                 routes/api.php
                                 Controller → App\Support\ApiResponse
                                 {"success":true,"data":{…},"message":null}
```

Two routes are declared **before** the fallback and must stay there:

| Route | Contract |
|---|---|
| `GET /health` | `200 {"status":"ok"}`, no database access. Byte-identical to the FastAPI endpoint so the Windows watchdog and the public monitor need no change. |
| `GET /health/database` | `200 {"status":"ok"}` or `503 {"status":"error"}`. Also byte-identical to FastAPI. |

Why they cannot be left to the fallback: the fallback answers **200 with the HTML shell for any
unknown path**, so a supervisor probing `/health` would report a completely broken backend as
healthy. This is a real trap, not a theoretical one.

The Vue client uses the envelope-carrying `/api/health` and `/api/health/database` instead.

## 5. Frontend structure

```text
resources/js/
├── app.js                    bootstrap: theme, Pinia, router, mount
├── router/index.js           history-mode routes; meta.title drives document.title
├── layouts/AppLayout.vue     shell: brand header, theme toggle, <RouterView>
├── pages/                    StatusPage.vue, NotFound.vue  (lazy-loaded)
├── stores/system.js          Pinia store for /api/health*
├── services/api.js           the ONLY place that talks to the network
├── composables/useTheme.js   theme contract, ported from app/static/js/theme.js
└── utils/numbers.js          Persian/Latin numeral conversion
```

Rules the code follows:

* **One network layer.** Components never call `fetch`/`axios`; they use `services/api.js`, which
  owns CSRF (axios + Laravel's `XSRF-TOKEN` cookie), timeouts, envelope unwrapping and turning every
  failure into `{success:false, message, status, errors, offline}` with a Persian message.
* **No internal leakage.** The API layer never surfaces a stack trace; the backend logs the detail
  and answers with a Persian sentence.
* **Pinia only for shared state.** `system.js` holds health because any page may need it. Page-local
  state stays in components.
* **Route-level code splitting.** `NotFound.vue` is already emitted as its own chunk; every future
  page is imported the same way (verified in the build output).
* **`meta.requiresAuth` / `meta.role` are navigation convenience only.** Every API is authorised
  again server-side; a Vue route guard is never the boundary.

## 6. Styling, fonts, RTL, theming

* **No CDN anywhere.** The Laravel scaffold's `bunny('Instrument Sans')` webfont plugin was removed;
  the three Vazir files the current application serves were copied to `public/fonts/` and are
  preloaded from the shell.
* **Design tokens are the real ones.** `resources/css/app.css` declares Tailwind theme tokens and the
  legacy custom properties (`--c-*`, `--dk-*`) with the values copied from
  `app/static/css/call-tokens.css` and `app/static/css/dark-theme.css`, so ported stylesheets keep
  working and the product looks like Hastama rather than a generic template.
* **RTL** is set on `<html lang="fa" dir="rtl">`; layouts use logical properties
  (`inset-inline-start`) so they mirror correctly.
* **Theme contract is preserved bit for bit:** localStorage key `hastama-theme`, values
  `light`/`dark`, **default light** (the old app never follows `prefers-color-scheme`), the legacy
  classes `dark-mode` and `dark-theme` applied to both `<html>` and `<body>` alongside
  `data-theme`, plus `documentElement.style.colorScheme`. The shell applies it in an inline
  `<head>` script before first paint so there is no white flash, and `useTheme.js` keeps Vue in step.
* **Print is always light**, as in the current application.

## 7. Database

* SQL Server is kept as-is. No table, column, index or trigger is created, renamed or dropped by the
  migration so far.
* `config/database.php` keeps Laravel's `sqlsrv` connection and changes the defaults to this
  deployment: `127.0.0.1:1433`, database `userDB`, **empty username/password** (omitting UID/PWD is
  how the driver selects Windows authentication — there is no SQL login in this project),
  `encrypt=no`, `trust_server_certificate=true` because the local instance has no trusted
  certificate.
* **Use `127.0.0.1`, never `localhost`.** `localhost` resolves to `::1` first and SQL Server listens
  on IPv4 only: `localhost,1433` is refused, `127.0.0.1,1433` reaches `SQLEXPRESS` / `userDB` /
  44 tables. Verified both ways.
* **The ODBC route was evaluated and rejected — recorded here because the next reader will
  wonder.** Laravel supports `'odbc' => true` with `'odbc_datasource_name'`, and its SQL Server
  processor even carries a dedicated `processInsertGetIdForOdbc`. It works — `pdo_odbc` connects
  and reports 44 tables — but it **destroys Persian text**: a value the server stores correctly
  (`45062F06CC063106CC06EC…`, i.e. «مدیریت») comes back from PDO as `"??????"`, because Windows'
  ANSI ODBC layer converts `nvarchar` to the client code page. `Client_CSet=UTF-8` and
  `ClientCharset=UTF-8` change nothing. A Persian product cannot use it, so the Unicode-native
  `pdo_sqlsrv` is required. The rejection is recorded in the `config/database.php` comment beside
  the connection, and in `MIGRATION_AUDIT.md` §35.
* **Installed driver (system change, approved).** Microsoft Drivers 5.13.3 for PHP for SQL Server,
  Windows build, files `php_sqlsrv_85_nts_x64.dll` and `php_pdo_sqlsrv_85_nts_x64.dll` from
  `Windows_5.13.3RTW.zip` (release published 2026-08-07), verified as x64 PE binaries before
  installation:

  | File installed as | SHA-256 |
  |---|---|
  | `C:\php\ext\php_sqlsrv.dll` | `9a8290abcc614e4f976bb97615040f09da1cbf83c0759043d2d9f214e0d81628` |
  | `C:\php\ext\php_pdo_sqlsrv.dll` | `57ba837342a3f97e313f961c1229b21248df7d290170a39e3090f4308471159c` |

  They are installed under the **unversioned** names, which is what `extension=sqlsrv` and
  `extension=pdo_sqlsrv` look for — PHP does not discover a `_85_nts_x64` suffix on its own, and a
  first attempt with the versioned names failed to load for exactly that reason.

  `C:\php\php.ini` was backed up before each edit and patched byte-exactly (no `sed`, which would
  have rewritten the whole file with LF endings):

  | Backup | Purpose |
  |---|---|
  | `C:\php\php.ini.bak-pre-odbc-20260930` | original file, before any change |
  | `C:\php\php.ini.bak-pre-sqlsrv-20260930-062607` | after `extension=pdo_odbc`, before the driver |

  Current additions: `extension=pdo_odbc`, `extension=sqlsrv`, `extension=pdo_sqlsrv`.
  `pdo_odbc` is left enabled; it is a stock extension and harmless, and can be removed with the same
  byte-exact script if desired.
* **Verified against the live database**, not assumed: driver `sqlsrv`, instance `SQLEXPRESS`,
  database `userDB`, 44 base tables, 16 `user_table` rows, ~3 ms latency, and — the point of the
  whole exercise — «مدیریت» round-trips as the correct UTF-8 string whose bytes match the server's
  own UTF-16 hex (`45062f06cc063106cc062a06`). A write/read probe of «مدیریت آزمایشگاه» through a
  temporary table (rolled back) confirmed the same in both directions.
* **PHP sees the same legacy padding as Python does:** a space-padded `nchar` column comes back as
  `'user      '`, so every comparison and every displayed value must be trimmed. A trimmed lookup
  works for Latin *and* Persian usernames (`آی تی` matches exactly). This is the compatibility
  behaviour `MIGRATION_AUDIT.md` §31 flags as required, now confirmed on the PHP side too.
* **`php artisan migrate` must never run against `userDB`.** The schema is the source of truth and
  already exists, so the migration writes Eloquent models that match it rather than migrations that
  build it. Laravel's three default migrations would have created `users`, `cache`, `cache_locks`,
  `jobs`, `job_batches` and `failed_jobs` inside the production database; they were moved to
  `database/migrations-disabled/`, which the migrator does not scan. `database/migrations/` is
  **empty and stays empty**, and `MigrationSafetyTest` fails if anything appears there.

### 7.1 The model layer (Phase 3)

43 Eloquent models in `app/Models/` — one for each application table. `sysdiagrams` is SQL Server's own
store and is deliberately unmodelled; the completeness check knows to ignore it.

Two abstract bases and three traits carry the compatibility rules, because these tables are *not* a
Laravel schema:

| Piece | Responsibility |
|---|---|
| `LegacyModel` | `#[Table(name: …, key: …, keyType: …, incrementing: …, timestamps: …)]` on every model, `$timestamps = false`, Laravel's default `$guarded = ['*']` kept, `nextKeyValue()` for the tables whose keys the application allocates |
| `LegacyTimestampedModel` | the tables that really own `created_at` / `updated_at`; a model with only one of the two sets the other to `null` |
| `TrimsLegacyStrings` | trims trailing padding on **every** string attribute read — 16 of 16 `role` values arrive as `'admin     '` |
| `GuardsDatabaseOwnedTable` | turns "the triggers own this table" into a `LogicException` naming the trigger |
| `App\Support\Legacy\*` | `PersianText` (yeh/kaf folding, `trim` vs `strip`), `LegacyPassword` (the four legacy credential formats), `LegacyRequestStatus` (the Persian approval vocabulary), `LegacyValue` (flag/int/JSON coercion), `LegacySchemaInspector` (read-only introspection) |

The rules they encode, and the evidence for each, are in [DATABASE.md](DATABASE.md). The short version:
legacy padding is trimmed on read, usernames compare through `REPLACE(REPLACE(LTRIM(RTRIM(...)), …))`,
`leave_report` and `ezafe_total_table` are read-only because triggers own them, `totalpass_table` is
**not** (it is the admin approval queue), and `lockForUpdate()` is unusable on SQL Server because
`SqlServerGrammar::compileLock()` returns an empty string — so `QueueTicket::nextNumber()` issues the
raw `WITH (UPDLOCK, HOLDLOCK)` statement and refuses to run outside a transaction.

Verification is a command, not a claim:

```
php artisan hastama:schema-check      # 43 models / 43 tables, read-only, exit 0
```
* Consequently `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`: the database
  drivers would each want a table that does not exist.
* **Resolved in Phase 4.** The session bridge is a deliberate pairing rather than a replacement.
  Laravel owns the signed cookie (its `session` guard, its flash data, its CSRF token);
  `dbo.user_sessions` keeps owning **revocability** — "is this session still allowed to exist" — because
  that is the question a signed cookie cannot answer, and it is the question password resets, account
  disables, role changes and the control centre's "terminate session" action all ask.
  `ValidateLegacySession` joins the two, and it is the only piece that knows about both.
  The two cookie names do not collide: Laravel's is `hastama-session`, the legacy one is `session`.

### 7.2 The authentication layer (Phase 4)

Authentication is the one place where the legacy schema cannot be reconciled with a framework default,
so the layer is explicit about every substitution it makes.

| Piece | Responsibility |
|---|---|
| `App\Auth\LegacyUserProvider` | the `legacy` auth driver: padded/letter-folded username lookup, credential validation that includes the account-active rule, and the upgrade hook |
| `App\Services\Auth\LegacyCredentialWriter` | **the only** code that writes credential material — bcrypt into `password_hash` (via `CONVERT(varbinary(64), …, 2)`), plaintext column emptied, `password_changed_at` stamped |
| `App\Services\Auth\SessionRegistry` | `dbo.user_sessions` — issue, validate, touch, revoke; validation cached for a few seconds; **fails open on infrastructure errors**, a recorded residual risk |
| `App\Services\Auth\LoginThrottle` | the brute-force limits, over Laravel's atomic cache limiter rather than per-process memory |
| `App\Services\Settings\SystemSettings` | cached, fail-soft `system_config` reads; unavailable settings degrade to the caller's default, never to an error |
| `App\Services\Audit\{AuditLogger,RecoveryCodeService}` | `audit_logs` / `security_events` / `admin_actions`, which never break the caller; and the recovery-code workflow, which fails closed without `HASTAMA_HMAC_SECRET` |
| `App\Http\Middleware\VerifyLegacyCsrf` | replaces `PreventRequestForgery` in the `web` group: Laravel's token **and** the legacy `csrf_token` cookie + `X-CSRF-Token` header, the legacy prefix exemptions, the same-site origin check on exempt writes, and the `403` body |
| `App\Http\Middleware\ValidateLegacySession` | the revocability and idle-timeout check that Laravel's signed cookie cannot express |
| `App\Http\Middleware\{EnsureAdmin,EnsureMasterAdmin}` | the two authorisation levels, with the legacy bodies (`error` for one, `detail` for the other) |
| `App\Http\Middleware\SecurityHeaders` | `nosniff`, `SAMEORIGIN`, `same-origin` referrer, a `Permissions-Policy`. No CSP yet — that is Phase 15, decided against the finished front-end |

Four decisions that a later phase must not quietly reverse:

1. **`LegacyUserProvider`, not `EloquentUserProvider`.** 15 of the 16 live accounts hold a plaintext
   password; `Hash::check()` against that column would reject every one of them.
2. **The plaintext comparison is kept until a successful login replaces it.** Nobody is locked out at
   cutover, and no row is rewritten without a password that has just been proven correct.
3. **`csrf_token` is excluded from cookie encryption.** The existing front-end reads it with
   `document.cookie` and echoes it in a header; an encrypted cookie could never match the session token.
4. **The client-side and server-side idle timeouts are different settings, on purpose.**
   `system_config.idle_timeout_seconds` (300) drives the browser timer the existing JavaScript owns;
   `HASTAMA_IDLE_TIMEOUT_SECONDS` (1800) is what the registry enforces. Merging them would silently
   change one of the two behaviours.

## 8. Conventions

* Modern PHP: `declare`-free files, backed enums where useful, constructor promotion, `readonly`
  promoted properties, first-class callable syntax, `final` on leaf classes, explicit return types.
* No literal Python-to-PHP translation: behaviour is re-expressed with controllers, form requests,
  services, policies, jobs and events.
* Response envelope and validation errors come from `App\Support\ApiResponse` only.
* Persian belongs in user-facing strings; identifiers, comments in code that explain *why*, and all
  documentation stay in English so the codebase stays reviewable.
* Never log credentials, session identifiers or password material.

## 9. Environment

`.env` (not committed) and `.env.example` (committed, no real secrets) carry the same keys. Notable
decisions:

| Key | Value | Why |
|---|---|---|
| `APP_URL` | `http://127.0.0.1:8000` locally, `https://hastama.ir` in production | the twin origins during side-by-side |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `fa` / `en` | Persian UI; framework strings fall back until `lang/fa` is written |
| `DB_CONNECTION` | `sqlsrv` | Microsoft SQL Server only |
| `DB_USERNAME` / `DB_PASSWORD` | empty | Windows authentication |
| `SESSION_LIFETIME` | 480 | mirrors `system_config.session_timeout_minutes` |
| no `APP_TIMEZONE` | — | Laravel 13 hard-codes `config('app.timezone') = 'UTC'` and the database already stores UTC (`SYSUTCDATETIME()` defaults). Changing the PHP timezone would make Carbon reinterpret every stored datetime. The Tehran wall clock matters only at date boundaries ("today's attendance", Jalali month edges) and gets an explicit helper during the attendance phase. |

Runtime settings are **not** environment variables. The current application keeps twelve of them in
`system_config` (`captcha_enabled`, `idle_timeout_seconds`, `label_print_settings`,
`label_target_printer`, retention windows, …). Those stay in the database; only secrets and
deployment facts belong in `.env`.

## 10. What is deliberately not here yet

Listed so that absence is not mistaken for an oversight:

* no `routes/api.php` endpoints beyond health — every other endpoint is `Pending` in
  `MIGRATION_STATUS.md`
* no auth/session bridge, no policies — the models exist (Phase 3) but nothing authenticates yet
* no `lang/fa` framework translations
* no WebSocket, SSE, queue or scheduler wiring
* no Araz, printing or attendance code
* no `ARCHITECTURE.md` claim about any of the above
