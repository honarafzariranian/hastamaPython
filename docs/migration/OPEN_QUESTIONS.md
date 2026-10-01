# Open questions — decisions required before Phase 2

Phase 1 (inspection) is complete and recorded in [MIGRATION_AUDIT.md](../../MIGRATION_AUDIT.md).
The items below could not be resolved from the code and the database alone. Each one is a decision
for the operator; the audit's recommendation is stated so that work can proceed on the default if no
answer arrives.

---

## Q1 — How does Laravel read the Araz Access database? *(blocking)*

`app/services/araz_connector.py::ArazAccessDB` reads
`E:\Hastama\database\Arazdb.mdb` through
`DRIVER={Microsoft Access Driver (*.mdb, *.accdb)}` with `ARAZ_ACCESS_PASSWORD`.
This is the **authoritative** attendance source for `get_hozoor` and the bridge.

PHP has no supported Microsoft Access ODBC driver that can be relied on in this stack (the
`pdo_odbc` extension can reach installed ODBC drivers in principle, but the Microsoft Access driver's
bitness/threading behaviour under PHP-FPM/`artisan serve` on this host is unverified, and a failure
here silently degrades attendance to `hozoor`-only).

| Option | Consequence |
|---|---|
| **A. Keep a small documented helper process for the MDB read only** (recommended) | The brief's definition of done explicitly permits "a specific external integration [that] still requires a separate Python process", provided it is documented with a reason. Lowest risk; no data movement; the helper can be the existing `tools/bridge_agent.py` extended, or a tiny .NET/Python one-shot invoked by Laravel |
| B. Install/verify a commercial or 64-bit Access ODBC driver for PHP | No extra process, but a new licensed dependency and an unverified driver on a production Windows host |
| C. Replicate `TPrsInOut` into SQL Server on a schedule and read it from Laravel | Removes the Access dependency from the request path entirely, but introduces a second copy of attendance truth and a sync lag |

**Also required by Q1:** the real column list of `TPrsInOut` was not read live (the audit read the
query text, not the table). Confirm `CardNo`, `Date`, `Time`, `InOutType` types and the `Date` literal
format before writing the PHP query.

## Q2 — Legacy plaintext passwords

15 of 16 accounts have a populated plaintext `user_table.password` (`nchar(10)`) and a NULL
`password_hash`. Only one account has a bcrypt hash.

| Option | Consequence |
|---|---|
| **A. Verify legacy plaintext, then hash on first successful login** (recommended) | No user is locked out, no password leaves the database, and the plaintext column can be emptied after every account has migrated. Needs a counter/telemetry so the operator can see progress |
| B. Run `tools/migrate_passwords.py` for all rows before cutover | Immediate, but a bulk write to a production identity table, and any account whose plaintext does not match what the user actually types stays broken |
| C. Require a password reset for everyone | Most secure, most disruptive; the reset workflow is manual (admin approval), so it is a queue of 16 human interactions |

**Never:** print, log, export or commit the plaintext values. Any migration script must be reviewed
before it runs against production, and the audit trail must record counts only.

## Q3 — `hozoor_num` integrity

* 3 users have `hozoor_num = NULL` → they can never match an Araz card.
* 1 pair of users shares the same normalised `hozoor_num` → one card would attribute to two people.
* 13 of 16 values carry trailing spaces.

| Option | Consequence |
|---|---|
| **A. Preserve the data and report it in the migration as a known data issue** (recommended) | Behaviour stays identical to today; the operator fixes the cards in the Araz software and the user table as a separate, deliberate action |
| B. Fix the duplicates/NULLs during migration | Changes who gets whose attendance — a business-behaviour change that the brief forbids without a documented reason |

## Q4 — Rollout shape

The brief's §58 regression requirement (old behaviour vs new behaviour, per feature) needs both
implementations reachable at the same time.

| Option | Consequence |
|---|---|
| **A. Run Laravel on a second loopback port behind the same Cloudflare Tunnel on a staging hostname, then switch the apex** (recommended) | Side-by-side comparison is possible, rollback is one tunnel route change, and the FastAPI app keeps serving users throughout |
| B. Replace the running site in place | Fastest to "done", but rollback means redeploying Python and there is no live comparison for the regression table |

Either way, the existing rule holds: the Python application is not deleted until its replacement is
Implemented → Tested → Verified.

## Q5 — Pre-existing test failures

13 tests fail today in the Python suite (dark-theme CSS ordering ×4, user-panel dark rules ×4,
final-report print layer, responsive tables, ticketing service ×2, user-panel theme). They are
unrelated to the migration.

| Option | Consequence |
|---|---|
| **A. Triage them first and record which are real defects and which are stale assertions** (recommended) | The parity matrix needs a trustworthy baseline; otherwise a real regression can hide inside "those 13 were already failing" |
| B. Carry them into the parity matrix as known differences | Faster, but the baseline stays unreliable |

## Q6 — Scheduler execution on Windows

The only job runs every **1 second** in-process (`publish_due_notifications`).

| Option | Consequence |
|---|---|
| **A. Publish on write, with a 1-minute `schedule:run` sweep as the safety net** (recommended) | Matches the existing code's own intent (the sweep is documented as a safety net); `schedule:run` every minute is the standard Windows Task Scheduler pattern and is well understood |
| B. `php artisan schedule:work` as a supervised second process | Closer to the original cadence, but adds a second supervised process to the watchdog contract |

## Q7 — WebSocket transport for the call display

`/api/ws/call-display` is unauthenticated by design and capped/rate-limited.

| Option | Consequence |
|---|---|
| **A. Laravel Reverb on loopback behind the same tunnel, reproducing the Origin check, the connection cap, the frame cap and the `audio_activated`-only inbound rule** (recommended) | First-party, same protocol shape, and the security rules stay enforceable in the app layer |
| B. Keep a thin Python/Node WebSocket sidecar | Fewer unknowns, but it is a second runtime for a core real-time feature and conflicts with the definition of done |

## Q8 — `arazin\`, `notebooks\`, `ml\` and the retired files

`arazin\` is 59 475 files of vendor/backup material; `Caddyfile` self-describes as retired;
`Dockerfile`/`docker-compose.yml` are unused; `MODEL_PATH`/`MODEL_NAME`/`MEMOIZATION_FLAG` are read
from `.env` but no request path uses a model.

| Option | Consequence |
|---|---|
| **A. Leave all of them in place for the whole migration; decide removals only at the Clean-Up phase with evidence** (recommended) | Matches the brief's §60 rule and keeps the repository diff reviewable |
| B. Remove the clearly-retired files now | Smaller tree, but removes the ability to diff "before" behaviour |

---

## Q9 — `totalpass_table` may receive two rows for one hourly-pass request *(needs an operator decision)*

Discovered while building the model layer (Phase 3), not during the audit.

Three triggers on `avalpss_table` / `beynpss_table` / `akhrpss_table` copy a row into
`totalpass_table` — the admin approval queue — whenever `total_time_*` is not null:

```sql
CREATE TRIGGER trg_AvalPssToTotalPass ON avalpss_table AFTER INSERT, UPDATE
AS BEGIN
    INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)
    SELECT username, date, 'avalpss', total_time_aval, 'انتظار تایید'
    FROM inserted WHERE total_time_aval IS NOT NULL;
END;
```

And the application **also** inserts its own queue row for the same submission
(`app/main.py`), with the source comment «این جدول صف تأیید مدیریت است؛ بدون آن، درخواست تازه در پنل
مدیر دیده نمی‌شود» (*this table is the admin approval queue; without it a new request is invisible in
the admin panel*).

So a single submission can in principle produce **two** pending rows: one from the trigger and one
from the application. The comment suggests the application insert exists *because* the trigger path is
not reliable at insert time (the `total_time_*` value is computed by a sibling trigger on the same
table, so `inserted` may still be null), which is consistent with the two paths not always overlapping.

**What the migration does:** reproduces the existing behaviour exactly — the application keeps
inserting its queue row and the triggers are left alone (rule 1 of `DATABASE_TRIGGERS.md`). It does
**not** add a de-duplication step, because inventing one would change functional behaviour and could
hide a request the admin expects to see.

| Option | Consequence |
|---|---|
| **A. Keep both inserts exactly as they are; record the overlap as known behaviour** (recommended, and what was implemented) | No behaviour change; the admin sees whatever the old system showed |
| B. Add a guard so a second pending row for the same `(username, request_date, pass_title)` is skipped | Fewer duplicates, but a legitimate second request in the same day could be suppressed |
| C. Count the duplicate rows on the live data first | Evidence-based, but 6 rows exist and the pattern may not have occurred yet |

---

## Q10 — `/verify_recovery_code` is CSRF-exempt but has no endpoint

`CSRF_EXEMPT_PREFIXES` in `app/main.py` names `/verify_recovery_code`, and **no route answers it**.
The recovery flow verifies the code inside `POST /reset_password` instead.

**What the migration does:** keeps the entry verbatim, because the exemption list is transcribed from
the running application and "tidying" it would be a silent change to a security control's surface. The
prefix is harmless — an exemption only matters when a route exists behind it.

| Option | Consequence |
|---|---|
| **A. Keep the entry, note it here** (what was done) | The list stays a faithful transcription; the dead entry is documented |
| B. Remove the entry | The list becomes "correct" but no longer matches the application, and a future reader cannot tell which mismatch was deliberate |
| C. Add an endpoint | Invents an API the front-end has never called |

---

## Q11 — should a real account's credential be upgraded during verification?

The migration's decision is to verify the legacy plaintext and then replace it with bcrypt **on the
first successful login**. That is intended behaviour, but it is also a permanent change to a live row.

**What the migration does:** does **not** perform a successful login by hand against the live database.
The full path — including the `UPDATE`, the `user_sessions` row and the role flags — is driven by
`AuthDatabaseTest` through the real HTTP kernel against the real database, inside a transaction that is
rolled back, and the live row is inspected afterwards to confirm it is unchanged.

| Option | Consequence |
|---|---|
| **A. Leave the first real login to the operator** (recommended, and what was done) | No production row changes during verification; the code path is still proven |
| B. Upgrade one account deliberately during cutover | Same result, but with a human watching a named account |
| C. Run the `tools/migrate_passwords.py` bulk migration instead | All 15 accounts move at once — a bigger, irreversible step that needs its own decision and backup |

---

## Q12 — Laravel 13 accepts `Sec-Fetch-Site: same-origin` in place of a CSRF token

`PreventRequestForgery::hasValidOrigin()` returns true when the request carries
`Sec-Fetch-Site: same-origin`, so an unsafe, non-exempt, token-less request from the same origin is
accepted. `Sec-Fetch-Site` is a browser-forbidden header that page script cannot set, so this does not
open a cross-site hole. It is nonetheless a *behavioural difference* from the legacy middleware, which
required a valid token on every non-exempt unsafe method.

This matters for the side-by-side period in one direction only: a request from the **legacy** front-end
will normally carry the header *and* the token, so nothing changes for it. A same-origin request that
was previously rejected (a script running on the page) is now accepted.

**What the migration does:** leaves Laravel's behaviour in place rather than disabling the origin check,
because disabling it would remove a protection that the framework added deliberately. Recorded here so
Phase 15 (security review) can reconsider it with the finished front-end in hand.

| Option | Consequence |
|---|---|
| **A. Keep the framework behaviour; record the difference** (what was done) | No regression, one documented delta |
| B. Force token-only validation, matching the legacy middleware exactly | Byte-for-byte parity, at the cost of removing a browser-verified signal |
| C. Keep both and require *either*, documented as the new contract | Honest, but needs the front-end reviewed to confirm it never relies on the token-less path |

---

## Q13 — Laravel rewrites input that FastAPI left alone *(decided, recorded)*

**The problem.** Two of Laravel's global middlewares have no counterpart in the running application:

* `TrimStrings` trims every string in the query and request bags;
* `ConvertEmptyStringsToNull` turns every `''` into `null`.

Both change what an endpoint can distinguish. `GET /get_user_info_report?username= admin` is a
different request from `?username=admin` — the Python compared `username.strip()` for the ownership
check but passed the **untrimmed** value to `WHERE username = ?`. And `?username=` (present but empty)
is not the same request as no `username` at all: FastAPI answered `200` with the handler's own
`نام کاربری ارائه نشده است` body for the first and `422` for the second, while
`ConvertEmptyStringsToNull` collapsed both into `null` and the replacement answered
`422 Field required` for the one the caller *had* supplied.

**Decision.** Both are removed in `bootstrap/app.php`. The Python application called `.strip()`
exactly where it meant to — `_require_admin` and `_ticket_actor` did, the SQL predicates wrapped the
*stored* column in `LTRIM(RTRIM(...))` — and every Phase 4 service follows the same rule by hand
(`LoginThrottle`, `SessionRegistry`, `LoginController` all call `trim()` or
`mb_strtolower(trim(...))` explicitly). Nothing depended on the framework doing it implicitly, so
removing them is one decision rather than a re-derived normalisation in every controller of every
remaining phase.

**Not a security change.** The CSRF comparison and the session registry read the session and the
headers, not the input bags.

**Consequence for tests.** A test that builds a `Request` by hand bypasses the middleware stack
entirely, so it can pass while the running server behaves differently. Anything about a query
parameter has to be driven through the HTTP kernel — see `TESTING.md` §4.

## Q14 — an anonymous request with a bad parameter: `422` or `401`?

**The problem.** FastAPI resolves a handler's declared `Query(...)` parameters *before* the handler
body runs, and the guards of the `master_admin` router (`_master_admin(request)`) are inside the body.
So the running server answers **`422`** for `/master-admin/api/sessions?page=abc` even with no session
at all. In Laravel the `master_admin` middleware runs before the controller, so the same request is
answered **`401`**.

**Decision — recorded, not restructured.** Both are rejections of the same request; the `422` body
only echoes the caller's own input, so nothing is disclosed either way; and no caller reaches these
routes unauthenticated during the side-by-side period. Making the order match would mean moving
validation into a middleware that runs ahead of the guard, which buys nothing a client can observe.

`GET /get_user_info_report` **is** ordered correctly, because its `_require_auth` check lives in the
handler and can therefore run after validation. That ordering is asserted in
`LegacyReadSurfaceTest::the_report_endpoint_resolves_its_required_parameter_before_it_authenticates`,
so the two cases cannot be confused with each other later.

---

## Items the audit could not verify (not questions — gaps)

These are recorded so that no later claim of `Verified` rests on them:

1. **`TPrsInOut` was not read live.** The Access driver/DB connection could not be confirmed during
   the audit. Column names come from the source query text.
2. **Front-end runtime behaviour was not observed.** The template and JS inventories are structural
   (lines, ids, fetches, DOM call sites), not behavioural. Live behaviour must be captured before
   parity claims are made.
3. **The LAN relay and the Iran-only gate were not exercised** during this audit.
4. **Real-row edge cases are untested by the suite** because `tests/conftest.py` fakes `pyodbc`:
   trailing-space matching, `nchar` semantics, `DELETE FROM dbo.user_sessions` and trigger side
   effects are only proven by the running application.
5. **Printing was not run.** Printer names were read from `system_config` and from the source, not
   from a live spooler enumeration.
6. **The legacy front-end was not pointed at the new endpoints.** The authentication routes reproduce
the running application's paths and bodies, and were exercised over real HTTP with `curl`, but no
browser session of the *old* interface has yet submitted to them. That is the cutover, and it belongs to
a later phase.
