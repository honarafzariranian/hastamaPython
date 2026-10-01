# Testing

How the migration is verified, what is covered today, and what is deliberately **not** claimed.

Related: [MIGRATION_STATUS.md](MIGRATION_STATUS.md) (per-feature status),
[MIGRATION_AUDIT.md](MIGRATION_AUDIT.md) §24 (the existing test suite).

---

## 1. Two suites, one rule

| Suite | Command | What it proves |
|---|---|---|
| Legacy Python (unchanged) | `cd /e/Hastama && PYTHONIOENCODING=utf-8 ./.venv/Scripts/python.exe -m pytest -q --tb=no -rf -p no:randomly -W ignore::DeprecationWarning` | the running application has not regressed |
| Laravel (offline) | `cd /e/Hastama/laravel && php artisan test` | the new code's own behaviour |
| Laravel (live schema, opt-in) | `cd /e/Hastama/laravel && HASTAMA_DB_TESTS=1 php artisan test` | the models match the real `userDB`, the authentication flow works against real rows, and the Phase 5 read endpoints answer with the running server's exact shapes |
| Front-end | `cd /e/Hastama/laravel && npm run test` | *(Vitest is configured; no specs exist yet)* |

**The rule:** a normal test run must never write to the production database. `userDB` holds live data
for a working laboratory, and the brief forbids destroying it. So the Laravel suite is **offline by
default**, and the only tests that touch the real server are read-only and opt-in.

### Why the opt-in flag instead of a test database

There is no test database, and there cannot be one: every table holds live data, and the schema may not
be recreated or modified. `phpunit.xml` therefore leaves Laravel's placeholder `DB_CONNECTION=sqlite`
in place, which means a test that mistakenly reaches for a database **fails loudly** (`could not find
driver`) instead of quietly altering production. That failure mode is intentional.

`HASTAMA_DB_TESTS=1` turns on `tests/Feature/LegacySchemaTest.php`, which points the connection at
`127.0.0.1,1433 / userDB` itself (it does not trust the ambient environment — inheriting
`DB_DATABASE=:memory:` from `phpunit.xml` produced a misleading "login failed" earlier). Its
statements are catalogue queries, `COUNT(*)` and one transaction that is rolled back.

---

## 2. Baseline: the legacy Python suite

Recorded before any migration work and re-checked after every phase:

```
866 passed, 13 failed, 4 skipped   (~19–26 s)
```

The 13 failures are **pre-existing and unrelated to the migration**; the same 13 fail on the untouched
`8c619dc` checkout. They are open question `Q5` in
[docs/migration/OPEN_QUESTIONS.md](docs/migration/OPEN_QUESTIONS.md):

* 4 × `test_dark_theme.py::test_dark_theme_css_is_last_stylesheet`
* 3 × `test_dark_theme.py::test_user_panel_surfaces_have_dark_rules`
* `test_modals_and_popups_have_dark_rules[.popup-box-massageBox]`
* `test_final_report_print.py::test_no_screen_only_decoration_in_print_layer`
* `test_responsive_tables.py::test_every_table_has_a_mobile_pattern`
* 2 × `test_ticketing_service.py`
* `test_user_panel_theme.py::test_user_panel_dark_mode_rules_cover_core_surfaces`

**Zero regressions** is the claim, not "all tests pass" — and the difference is stated in
`MIGRATION_STATUS.md` rather than glossed over.

Note that `tests/conftest.py` fakes `pyodbc`, so the Python suite never touches SQL Server. The
consequence is important: real-row edge cases — trailing-space matching, `nchar` semantics, trigger
side effects — are *not* covered by it at all. That gap is what the Laravel live-schema test exists to
close.

---

## 3. What the Laravel suite covers today (Phase 3)

`php artisan test` → **6 skipped, 44 passed (383 assertions)**.
With `HASTAMA_DB_TESTS=1` → **50 passed (398 assertions)**.

### `tests/Unit/PersianTextTest.php` — the legacy text quirks, pinned

* `trim()` removes trailing padding only (`'admin     '` → `'admin'`) and never returns null;
* `normalizeLetters()` folds the Arabic yeh/kaf onto the Persian forms, asserted on the actual
  code points;
* `foldUsername()` combines folding, stripping and case folding;
* `normalizedColumnExpression()` produces the expression the Python application compares against,
  byte for byte, **and refuses anything that is not a plain identifier** — it builds a SQL fragment, so
  it must never accept caller data;
* Persian ↔ Latin digit conversion both ways;
* `LegacyValue::truthy()` accepts exactly `1 / true / yes / on / enabled` (case- and
  whitespace-insensitive) and rejects everything else;
* `integer()` clamps and falls back to the default; `json()` returns the default on malformed content;
* `LegacyRequestStatus` holds the exact Persian strings the data uses.

### `tests/Unit/LegacyPasswordTest.php` — the four verification paths

* plaintext in `password` **with `nchar(10)` padding**, which is how 15 of the 16 live accounts
  authenticate today;
* the attempt is stripped before comparison and before bcrypt, as the Python code does;
* bcrypt in `password_hash`; bcrypt that ended up in `password`; SHA-512 in both raw-digest and
  128-character hex shapes;
* **a populated `password_hash` does not fall through to stale plaintext** — a half-migrated row must
  not open a second door;
* hashes are interchangeable with Python's: `hash()` returns 60 bytes, and a `$2b$`-prefixed hash
  verifies.

### `tests/Feature/LegacyModelTest.php` — the model layer

* all 43 application tables have a model, every model declares `#[Table(...)]` explicitly, the table
  name is a plain identifier, and the default `$guarded = ['*']` is still in force;
* an unfillable attribute **throws** instead of being dropped
  (`Model::preventSilentlyDiscardingAttributes`), which is what makes a typo in a controller a bug
  report rather than silent data loss;
* `User`: role/username/card-number padding, the password columns being hidden from serialisation,
  the exact login-status rule from `auth.py`, and the auth contract (including the fact that there is
  no remember token);
* both database-owned models refuse `save()` and `delete()` and name their trigger in the exception —
  and `HourlyPass` deliberately does **not**, because `totalpass_table` is the admin approval queue;
* `QueueTicket::nextNumber()` refuses to run outside a transaction;
* the ticket transition table matches `ALLOWED_TRANSITIONS` from `services/ticketing.py`;
* the master-admin config whitelist and the notification `type`/`priority`/`target_type` vocabularies
  match the existing validator and endpoint exactly;
* only internal (`/…`, not `//…`) notification action links are accepted.

### `tests/Feature/MigrationSafetyTest.php` — the protections

* `database/migrations` is empty;
* the three scaffold migrations sit in `migrations-disabled`;
* `DatabaseSeeder` contains no write (checked on comment-stripped source, since its own docblock quotes
  the call it removed);
* no model factory exists for a live table;
* the deployed `.env` points at `sqlsrv` / `userDB` with empty credentials (Windows authentication),
  and the connection block carries no SQL login.

### `tests/Feature/LegacySchemaTest.php` — the real database *(opt-in)*

* every model matches the live schema (delegates to `hastama:schema-check`, exit code 0);
* the database is `userDB` with 43 application tables, and the ORM sees exactly the row count the raw
  query sees;
* **Persian text round-trips** — the regression that rejected the ODBC driver, asserted on a value that
  exists in the live data so a `??????` regression cannot slip through;
* the trigger ownership facts hold: `leave_report` itself has no trigger, `mrkhc_table` and
  `ezafe_table` do, and the three named triggers exist;
* `QueueTicket::nextNumber()` returns `MAX(ticket_number) + 1` inside a transaction that is rolled
  back — which is also the proof that the allocator works against the real table without writing;
* no application table is left without a model.

## 3b. What the Laravel suite covers today (Phase 4) — authentication

The Phase 4 tests are split by what they *need*, so the default suite stays runnable on any machine.

### `tests/Unit/LegacyInputTest.php` — the ported validators, pinned

* the username pattern accepts the live installation's own names in **both** alphabet spellings (the
  Arabic yeh `ي` of U+064A and the Persian `ی` of U+06CC are different characters and the data contains
  each), and rejects markup, `@`, `/`;
* every rejection message is asserted **verbatim**, because a "tidied" string is a visible change to the
  interface;
* the request-id format and its two distinct failures (too long vs. malformed) — the length check runs
  first, as in Python;
* recovery codes are exactly 8 characters and upper-case-only, rejected before any lookup;
* the password policy's five rules, their order, the score, and the two-way tie at "ضعیف";
* `LegacyIds` round-trips: 200 generated identifiers all satisfy `isValid()` **and**
  `validateRequestId()`, with the UTC date in the middle and no collisions in 500 draws.

### `tests/Feature/AuthSurfaceTest.php` — everything that needs no database

* **the CSRF bridge is in the `web` group.** Asserted on the configured middleware stack, because no
  request-level test can see it: Laravel switches CSRF validation off while running tests
  (`PreventRequestForgery::runningUnitTests()`), so a `post()` proves nothing about the control. The
  middleware itself is exercised through a subclass that turns that bypass back off;
* a rejected unsafe request answers `403 {"success":false,"error":"CSRF token mismatch."}` — not
  Laravel's `419` page, and not a stack trace when `APP_DEBUG` is on;
* the legacy header scheme: a matching `X-CSRF-Token` is accepted, a mismatched one is refused, an
  absent one is refused;
* the exempt paths really are exempt **by prefix** (`/api/queue/take/5` as well as `/api/queue/take`),
  and a cross-site write to one is refused while a same-site write and a request with no `Origin` at all
  (the Araz bridge, the documented `curl` recipes) are allowed. `Origin: null` is refused;
* the two guards' exact bodies — `error` for `EnsureAdmin`, `detail` for `EnsureMasterAdmin` — plus the
  control plane rejecting an ordinary administrator and accepting a master administrator;
* the readable `csrf_token` cookie: plain text, not `httpOnly`, `SameSite=Lax`, and expiring in
  `SESSION_MAX_AGE_SECONDS` rather than in that many *minutes*;
* `/api/system-config` publishes every key the login page reads and keeps them **strings**, because the
  existing JavaScript compares `res.data.idle_timeout_enabled !== '0'`;
* `/api/me` answers 401 with `unauthenticated: true` when anonymous; `/logout` redirects to `/login`;
* the CAPTCHA: the PNG magic bytes, the anti-cache headers, the code absent from the body, the
  unambiguous alphabet, the data-URI refresh, the countdown, single use, case-insensitivity, and the
  `expired` vs `missing` vs `mismatch` distinction the login endpoint branches on;
* session tokens are 43 URL-safe characters with no collisions in 100 draws;
* the recovery endpoints reject a malformed request id and a weak password without touching the
  database, and answer with the legacy Persian messages.

### `tests/Feature/AuthDatabaseTest.php` — the real database *(opt-in)*

Nine tests that drive the **real HTTP kernel** against the **real `userDB`**, wrapped in a transaction
that is always rolled back:

* the registered provider is the legacy one (not `EloquentUserProvider`, which would reject 14 of the 15
  live accounts);
* a live plaintext account verifies through the provider, and the padded/letter-folded username lookup
  finds the same row as the model's own accessor;
* **a real account logs in and its credential is upgraded** — `password` emptied, a bcrypt hash in
  `password_hash` that verifies the password just proven, `password_changed_at` stamped, a
  `user_sessions` row created, `is_admin` decided, and the redirect target matching the role;
* a second login uses the upgraded hash and does **not** rewrite the row again;
* a wrong password is refused with the generic message, leaves the credential alone, and increments
  `failed_login_count`;
* an unknown username is indistinguishable from a wrong password — same status, same body;
* a disabled account cannot log in even with the correct password, and its credential is not rewritten;
* a wrong CAPTCHA stops the attempt **before** the credentials are checked — proven by the row still
  being plaintext after a request whose password was correct;
* `/logout` revokes the registry row it created, keeping it as history with `terminated_at` and
  `terminated_by`.### Style

```
cd /e/Hastama/laravel && ./vendor/bin/pint --test     # 138 files, clean
```

---




## 3c. What the Laravel suite covers today (Phase 5) — the read surface

| File | Tests | Needs a database |
|---|---|---|
| `tests/Unit/LegacyDateTest.php` | fixture-driven Jalali conversion, 73,414 days | no |
| `tests/Unit/LegacySerializerTest.php` | `isoformat()`, `bytes` → hex, `Decimal` → float | no |
| `tests/Unit/LegacyPaginationTest.php` | the six-key envelope | no |
| `tests/Unit/LegacyQueryTest.php` | the ported `Query(...)` declarations | no |
| `tests/Feature/LegacyReadSurfaceTest.php` | routing, guards, and the bodies that need no data | no |
| `tests/Feature/LegacyJsonEncodingTest.php` | the raw wire encoding of every JSON body | no |
| `tests/Feature/LegacyReadDatabaseTest.php` | **shape parity with the running server** | yes, opt-in |

### `tests/Feature/LegacyReadDatabaseTest.php` — the real database *(opt-in)*

Twenty tests against the real `userDB`, in a transaction that is always rolled back. This is the
acceptance test for the read surface, and it is written to fail against a *plausible* implementation:

* `id` is the integer `319`, not the string `"319"` that `pdo_sqlsrv` returns;
* `role` is still `'admin     '` — the `nchar(10)` padding is real data, not whitespace to tidy;
* `created_at` is `2026-09-30T08:53:41.864000` — `T`, six digits — not `2026-09-30 08:53:41.864`;
* `is_active` is a boolean, and `session_key` is published **only** by the session list, because that
  is the handle the terminate action needs;
* the user detail view carries no credential — `user_table` holds `password` in plaintext for fifteen
  of its sixteen rows, so a widened `SELECT` here would publish them;
* a `404` from `/master-admin/api/users/{username}` is `{"detail": "کاربر یافت نشد."}`;
* `?limit=0` and `?limit=201` are `422` with FastAPI's exact `detail` list, and `?limit=abc` reports
  `int_parsing` rather than a bound;
* **every** pydantic bool spelling (`true/1/on/t/yes/y`, `false/0/off/f/no/n`) is honoured, and the
  three it rejected (`''`, `maybe`, `2`) are refused;
* the audit-event detail returns **200**, where the Python raises `IndexError` and answers 500.

### `tests/Feature/LegacyJsonEncodingTest.php` — the wire, not the value

Asserts on the **raw response body**, because `$response->json()` decodes the escaped and unescaped
forms to the same value — which is exactly why nothing else in the suite can catch it.
`{"success":false,"error":"لاگین نکرده‌اید."}` and its `\u0644…` twin are the same array to a decoder
and two different HTTP responses to everything else.

The traps this surface added to the list at the end of this document are the empty-string/whitespace
rewriting (`TrimStrings`, `ConvertEmptyStringsToNull`), the `302`-instead-of-`422` rendering of a
validation failure, and the JSON encoding — see §4.

---

## 4. What is *not* covered yet

Stated plainly so no status line over-claims:

| Area | State |
|---|---|
| Most of `docs/migration/ROUTE_INVENTORY.md`: the call system, notifications, registration, automation, ticketing, Araz, and the write half of the user panel and control plane | **not implemented** — the rest of Phase 5 |
| The admin half of password recovery (list / approve / reject / delete requests) | **not implemented** — Phase 9 |
| Self-registration with admin approval | **not implemented** — a later phase |
| The login **page** itself (`resources/js/pages/Login.vue`) | **not implemented** — Phase 6. The endpoints it will call are live, but nothing renders them yet |
| Front-end component tests | Vitest is configured, no specs yet |
| A successful login against the live database performed by hand | **deliberately not done** — it rewrites a real credential (`Q11`). Proven instead by `AuthDatabaseTest`, which rolls the write back |
| The legacy front-end talking to the new endpoints | **not done** — that is the cutover, and it needs Phase 6+ |
| Legacy Python failures (`Q5`) | **not triaged**, and not fixed by this migration |
| Printing, Araz, WebSocket/SSE, the scheduler | **not implemented** — Phases 11–14 |

### Five traps worth knowing before writing more tests here

1. **CSRF cannot be tested through the HTTP layer.** `PreventRequestForgery::runningUnitTests()` skips
   validation while the application is in the `testing` environment, so a `post()` that ought to be
   rejected will succeed. Test the middleware directly (see `AuthSurfaceTest::csrf()`), or assert on the
   configured middleware group.
2. **Not every wiring mistake shows up in a request.** The CSRF replacement that pointed at a class the
   `web` group does not contain was invisible to both the suite and `route:list`, and only appeared
   against the running server. Anything configured rather than called deserves an assertion on the
   configuration.
3. **A container binding must name the key the *caller* resolves.** `response()->json()` resolves the
   **contract** `Illuminate\Contracts\Routing\ResponseFactory`, which `RoutingServiceProvider` binds;
   the concrete `Illuminate\Routing\ResponseFactory` is a separate key. Binding the concrete class
   looked correct, changed nothing on the wire, and fails no existing test. `LegacyJsonEncodingTest`
   asserts the resolved class before it asserts the bytes.
4. **Input bags are rewritten before your controller sees them.** Laravel's global `TrimStrings` and
   `ConvertEmptyStringsToNull` turn `?username= admin` into `admin` and `?username=` into `null`. Both
   are removed in `bootstrap/app.php` because FastAPI had neither — but a test that builds a `Request`
   by hand bypasses the middleware stack, so it can pass while the running server behaves differently.
   When a query parameter is the thing under test, drive it through the HTTP kernel.
5. **The test harness is not the wire.** Assert on `$response->getContent()` when the *encoding* is
   what matters; `$response->json()` decodes away exactly the difference you are trying to pin down.

Nothing in the list above is marked `Verified` anywhere in `MIGRATION_STATUS.md`. The distinction the
brief asks for — *never claim something works without testing it* — is why §66 of
`MIGRATION_STATUS.md` still has almost every line unticked.

---

## 5. Adding a test in a later phase

1. Keep it offline unless it genuinely needs the server. Models can be populated in memory with
   `forceFill()`, which is how `LegacyModelTest` asserts real behaviours without a connection.
2. If it needs data, **do not** write it: assert against existing rows, or wrap writes in a transaction
   and roll back.
3. If it must touch the live schema, gate it behind `HASTAMA_DB_TESTS` exactly as `LegacySchemaTest`
   does, point the connection explicitly rather than trusting the ambient environment, and prefer
   read-only statements. When a write is genuinely the thing under test — as it is for the credential
   upgrade — wrap it in a transaction and roll back (`AuthDatabaseTest`), then check afterwards that the
   live row is unchanged.
4. Never add a factory for a table that holds live data.
5. Run `pint` and the full suite before claiming anything is tested.
