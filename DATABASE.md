# Database

How the Laravel + Vue application talks to the existing SQL Server database.

This document describes what is **implemented**, not what is planned. Where a fact was measured, the
measurement is stated; where something is not done yet, it says so.

Related: [docs/migration/DATABASE_SCHEMA.md](docs/migration/DATABASE_SCHEMA.md) (all 44 tables,
column by column), [docs/migration/DATABASE_TRIGGERS.md](docs/migration/DATABASE_TRIGGERS.md)
(all 9 triggers, verbatim), [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 1. The database is the source of truth

The server is **`127.0.0.1,1433` (`SQLEXPRESS`)**, database **`userDB`**. It contains live production
data for a working laboratory and it is shared with the still-running FastAPI application.

Measured on 2026-09-30:

| Fact | Value |
|---|---|
| Base tables | **44** |
| Views | 0 |
| Application stored procedures | 0 (only SSMS' `sp_helpdiagrams` family) |
| Triggers | **9**, on 5 tables |
| Foreign keys | **15**, all on the ticketing and automation tables |
| Rows in `user_table` | 16 |
| SQL Server build | 16.00.1000 |

The migration therefore treats the schema as fixed. It has not renamed a table, renamed a column,
dropped a column, added a constraint or created a single object. That is a deliberate constraint from
the brief, and §6 below explains how it is enforced rather than merely intended.

---

## 2. Connection and driver

`laravel/.env`:

```dotenv
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=userDB
DB_USERNAME=
DB_PASSWORD=
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
```

**Empty credentials are deliberate.** Leaving `UID`/`PWD` off the connection string is how the driver
selects Windows authentication, and this project has no SQL login anywhere — the Python application
connects with `Trusted_Connection=yes`. There is no database password to leak because there is no
database user.

### Why `sqlsrv` and not ODBC

`pdo_odbc` was enabled and tested first, and **rejected on evidence**. It connects and reports all 44
tables, but Windows' ANSI ODBC layer converts `nvarchar` to the client code page: the server's correct
«مدیریت» (`45062F06CC063106CC062A06` in UTF-16LE) arrived from PDO as `"??????"`. Setting
`Client_CSet=UTF-8` and `ClientCharset=UTF-8` changed nothing. The whole interface is Persian, so the
Unicode-native Microsoft driver is required.

Installed: **Microsoft Drivers 5.13.3 for PHP** (PHP 8.5, NTS, x64) as
`C:\php\ext\php_sqlsrv.dll` and `C:\php\ext\php_pdo_sqlsrv.dll`, registered in `C:\php\php.ini`
(`extension=sqlsrv`, `extension=pdo_sqlsrv`). The DLLs must carry the **unversioned** names — PHP does
not discover a `_85_nts_x64` suffix by itself. Checksums and the pre-change `php.ini` backups are
recorded in [ARCHITECTURE.md](ARCHITECTURE.md) §7.

Verified after installation, through Laravel itself:

```
driver=sqlsrv  instance=SQLEXPRESS  database=userDB  tables=44  user_table rows=16  ~3 ms
'مدیریت' round-trips byte-identically to the server's own UTF-16LE hex
a write/read probe of «مدیریت آزمایشگاه» also passed (rolled back)
```

---

## 3. The model layer

43 Eloquent models live in `laravel/app/Models/`. Every one of the 43 application tables has a model;
the 44th table, `sysdiagrams`, is SQL Server's own diagram store and is deliberately **not** modelled
(it has no application meaning, and it is listed in
`LegacySchemaInspector::NON_APPLICATION_TABLES` so the completeness check does not report it).

Two abstract bases and three traits carry the compatibility rules:

| Piece | Responsibility |
|---|---|
| `LegacyModel` | `$timestamps = false`; `$guarded = ['*']` stays in force; `nextKeyValue()` for the tables whose keys the application allocates |
| `LegacyTimestampedModel` | the tables that really own `created_at` / `updated_at` (a model with only one of the two sets the other to `null`) |
| `TrimsLegacyStrings` | trims trailing padding on every string attribute read |
| `GuardsDatabaseOwnedTable` | turns "never write this table" into a `LogicException` |
| `#[Table(name: …, key: …, keyType: …, incrementing: …, timestamps: …)]` | explicit table, key and identity/timestamp behaviour per model |

### Table → model mapping

Legacy names do not derive from class names, which is exactly why each model declares its table:

| Model | Table | Model | Table |
|---|---|---|---|
| `User` | `user_table` | `Ticket` | `tickets` |
| `Attendance` | `hozoor` | `TicketMessage` | `ticket_messages` |
| `Shift` | `shiftha` | `TicketEvent` | `ticket_events` |
| `LeaveRequest` | `mrkhc_table` | `TicketAttachment` | `ticket_attachments` |
| `LeaveReport` *(read-only)* | `leave_report` | `TicketCategory` | `ticket_categories` |
| `HourlyPass` | `totalpass_table` | `TicketTag` | `ticket_tags` |
| `MorningPass` | `avalpss_table` | `TicketTagRelation` | `ticket_tag_relations` |
| `MiddayPass` | `beynpss_table` | `LegacyTicket` | `ticket_table` |
| `EveningPass` | `akhrpss_table` | `QueueTicket` | `queue_tickets` |
| `OvertimeRequest` | `ezafe_table` | `ReceptionCall` | `reception_calls` |
| `OvertimeTotal` *(read-only)* | `ezafe_total_table` | `DisplayQueue` | `display_queue` |
| `UserSession` | `user_sessions` | `WaitingQueue` | `waiting_queue` |
| `AuditLog` | `audit_logs` | `Slide` | `slides` |
| `SecurityEvent` | `security_events` | `Notification` | `notifications` |
| `SystemError` | `system_errors` | `NotificationTarget` | `notification_targets` |
| `AdminAction` | `admin_actions` | `UserNotification` | `user_notifications` |
| `AdminPayrollCalculation` | `admin_payroll_calculations` | `PushSubscription` | `push_subscriptions` |
| `SystemConfig` | `system_config` | `AutomationConversation` | `automation_conversations` |
| `PasswordResetRequest` | `password_reset_requests` | `AutomationMessage` | `automation_messages` |
| `UserRegistrationRequest` | `user_registration_requests` | `AutomationParticipant` | `automation_participants` |
| `CustomerSubscription` | `customer_subscriptions` | `AutomationAttachment` | `automation_attachments` |
| | | `AutomationReopenRequest` | `automation_reopen_requests` |

---

## 4. The three compatibility rules

These are the difference between "the API answers" and "the API answers correctly". All three were
measured against the live data during the audit and are enforced by code, not by convention.

### 4.1 Trailing space padding

The legacy generation of tables uses fixed-length columns — `user_table.password`, `.role` and
`.hozoor_num` are `nchar(10)`, and every `*pss_table.username` is `nchar(10)` — and later rows were
copied into `nvarchar` columns with their padding intact. Measured: **11 usernames, 13 `hozoor_num`
values and all 16 `role` values** come back with trailing spaces; `role` is read as `'admin     '`.

The Python application strips at nearly every read site and every lookup uses `LTRIM(RTRIM(...))`.
`TrimsLegacyStrings` reproduces that: `getAttributeValue()` rtrims every string attribute, which
covers `$model->column`, `toArray()`, `only()`, JSON serialisation and `fill()` round-trips. Writes
are untouched — SQL Server pads `nchar` on storage by itself.

`PersianText` keeps the two operations apart on purpose:

* `trim()` — rtrim only, for raw column padding;
* `strip()` — both ends, because that is what the Python application's `.strip()` does to
  usernames, passwords, statuses and configuration values.

Mixing them up is how `' Enabled '` stops being recognised as a flag, which is why both have tests.

### 4.2 Two spellings of yeh and kaf

Seven usernames were entered with the Arabic yeh `ي` (U+064A) and two with the Persian yeh `ی`
(U+06CC); the same split exists for kaf. A byte comparison treats two visually identical names as
different users, so the existing application folds them before comparing:

```sql
REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
```

`PersianText::normalizedColumnExpression()` reproduces that expression, and
`User::scopeWhereUsername()` is the only lookup the application should use for a login name.
`normalizedColumnExpression()` refuses anything that is not a plain identifier, because it builds a
SQL fragment.

`user_sessions` deliberately does **not** use the folding: the existing revocation helper compared with
`LOWER(LTRIM(RTRIM(username)))` alone, which is why 4 of its rows match no user even after normalising.
`UserSession::scopeForUser()` reproduces that narrower predicate, so revocation keeps matching exactly
the rows it matched before. The table held 303 rows at audit time and 305 at the end of Phase 3 — the
extra rows are real logins to the still-running Python application, which is a useful reminder that the
counts in these documents are snapshots and only the schema is fixed.

### 4.3 Status strings in Persian

Three approval queues (`mrkhc_table`, `totalpass_table`, `ezafe_table`) share one vocabulary, and the
database *compares* those strings rather than storing ids:

| Status | Meaning |
|---|---|
| `انتظار تایید` | awaiting an admin decision (also the column default) |
| `تایید شده` | approved — the value every report and both triggers aggregate on |
| `رد شده` | rejected |
| `انصراف` | withdrawn |

`LegacyRequestStatus` holds them once. Note the spelling: the data uses `تایید` without a hamza while
prose in templates sometimes shows `تأیید`; only the stored form matters.

---

## 5. Triggers and the tables the application may not write

Nine triggers live on five tables and are **part of the application's behaviour**:

| Trigger | Attached to | Effect |
|---|---|---|
| `trg_CalculateTotalTime` / `trg_AvalPssToTotalPass` | `avalpss_table` | sets `total_time_aval`; copies the row into `totalpass_table` |
| `trg_CalculateTotalTimeBeynpss` / `trg_BeynPssToTotalPass` | `beynpss_table` | sets `total_time_beyn`; copies the row into `totalpass_table` |
| `trg_CalculateTotalTimeAkhrpss` / `trg_AkhrPssToTotalPass` | `akhrpss_table` | sets `total_time_akhr`; copies the row into `totalpass_table` |
| `trg_UpdateLeaveReport` | `mrkhc_table` | recomputes `leave_report.total_days` / `.remaining_days` against a **hard-coded 30-day entitlement** |
| `trg_CalculateDailyOvertime` / `trg_UpdateTotalOvertime` | `ezafe_table` | sets `daily_overtime`; maintains `ezafe_total_table` |

Rules the model layer enforces:

* **`leave_report` and `ezafe_total_table` are read-only.** The Python application only ever `SELECT`s
  from them, and every write through `LeaveReport` / `OvertimeTotal` throws a `LogicException` naming
  the trigger that owns the row. The exception message points at
  [docs/migration/DATABASE_TRIGGERS.md](docs/migration/DATABASE_TRIGGERS.md).
* **`totalpass_table` is writable.** It *looks* trigger-owned — three triggers insert into it — but the
  application inserts the admin-approval row itself and updates `status` on approval, with the source
  comment «این جدول صف تأیید مدیریت است؛ بدون آن، درخواست تازه در پنل مدیر دیده نمیشود». An earlier draft of
  the triggers inventory wrongly listed it as untouchable; the correction is recorded in that document
  and the overlap it creates is `Q9` in [docs/migration/OPEN_QUESTIONS.md](docs/migration/OPEN_QUESTIONS.md).
* **The triggers are never dropped or altered**, and the migration does not reimplement them in PHP.

---

## 6. Migrations: there are none, and that is enforced

`laravel/database/migrations/` is **empty and must stay empty**. `php artisan migrate` runs against the
live `userDB`, so a migration in that directory is a change to production data.

Laravel's three scaffold migrations (`users`, `cache`, `jobs`) were moved to
`laravel/database/migrations-disabled/`, which the migrator does not scan, rather than deleted — so the
decision is visible and reversible.

Three tests in `laravel/tests/Feature/MigrationSafetyTest.php` hold the line:

1. `database/migrations` contains no PHP files;
2. the three scaffold migrations are present in `migrations-disabled`;
3. `DatabaseSeeder` contains no write.

The seeder deserves its own note: Laravel's scaffold seeder created a fake account through
`User::factory()->create()`. Once `App\Models\User` was remapped to the live `dbo.user_table`, that call
would have inserted a bogus row — naming columns that do not even exist — into production the moment
anyone ran `php artisan db:seed`. The seeder is now explicitly empty, the factory is deleted, and no
factory may exist for a live table.

---

## 7. Type handling decisions

| Column type | Handling | Why |
|---|---|---|
| `time` (`vrood`, `khoroj`, `from_time`, `pass_duration`, …) | kept as strings | the Python application formats them at display time; casting to `Carbon` would change how they compare |
| `date` | `'date'` cast | safe and lossless |
| `datetime2` | `'datetime'` cast | values are UTC; the application timezone is UTC |
| `varbinary(64)` (`password_hash`) | raw string | no Laravel cast exists for binary; the stored value is a 60-byte bcrypt hash |
| `nvarchar(max)` JSON (`payload_json`, `metadata`, `before_data`, …) | `'array'` cast | a malformed value decodes to `null` instead of throwing, matching the Python behaviour |
| `bit` (`is_active`, `is_test`, `sort_order`-like) | `'boolean'` | real bits here, unlike `ticket_table.is_read` which is `varchar` holding `0`/`1` |
| `decimal` (`customer_subscriptions.price`) | left as a string | no precision is declared in the schema, so it is not used in arithmetic |

### Weekly columns are spelled differently in two tables

`user_table` spell Wednesday **`chrshanbeh`**; `shiftha` spells it **`chaharshanbeh`**. Both are the
live schema and are reproduced exactly. Neither may be "corrected".

---

## 8. Known limitations, recorded rather than hidden

1. **Composite primary keys** — `automation_participants`, `notification_targets` and
   `ticket_tag_relations` have two-column keys. Eloquent cannot address a composite key, so each model
   declares one column as its key for *reads only* and the unique index remains the real guarantee.
   `hastama:schema-check` reports them as notes rather than failures.
2. **`IX_user_table_username` is not unique**, and after normalising `hozoor_num` there is one
   duplicate group. Three users have `hozoor_num IS NULL`. Username lookups take the first match; the
   audit records the group so a decision can be made deliberately rather than by accident.
3. **`user_table.id` is not an identity column.** New rows allocate their own key. `nextKeyValue()`
   returns `MAX(key) + 1` and documents that the caller must hold the table: a bare `MAX` followed by
   an `INSERT` in two transactions is a duplicate-key race. Callers use `DB::transaction()` plus an
   explicit `WITH (UPDLOCK, HOLDLOCK)` query.
4. **`lockForUpdate()` is a no-op on SQL Server.** Laravel's `SqlServerGrammar::compileLock()` returns an
   empty string, so it takes no lock at all. This is why `QueueTicket::nextNumber()` issues the raw
   statement and refuses to run outside a transaction instead of relying on the framework.
5. **The 4 orphan `user_sessions` rows** match no account and stay that way; reads tolerate them and the
   models expose no relation that would fail on them.
6. **Row counts in the documentation are a snapshot.** The FastAPI application keeps serving during the
   migration, so `audit_logs` and `user_sessions` grow between runs (3,509 → 3,515 and 303 → 305 during
   Phase 3). Only the schema is stable.

---

## 9. Verification

```
php artisan hastama:schema-check            # human-readable table
php artisan hastama:schema-check --json     # machine-readable
```

Every check is read-only (catalogue queries and `COUNT(*)`), so it is safe against production. For each
model it asserts:

* the table exists;
* the declared key is a real column, and matches the table's single-column primary key when there is one;
* `$incrementing` agrees with the presence of an identity column;
* **every `#[Fillable]` entry names a real column** — this is the check that matters most, because a
  column missing from a fillable list would silently discard submitted data;
* managed `created_at` / `updated_at` point at columns that exist;
* a write-guarded model names triggers that really exist (looked up across the database — the triggers
  live on the *source* tables, which the first version of this check got wrong);
* every application table has a model, so a later phase cannot miss one quietly.

Current result — **43 models, 43 tables, every model matches the live schema**:

```
Database: userDB   Models: 43   Tables: 43
...
INFO  Every model matches the live schema.
```

`HASTAMA_DB_TESTS=1 php artisan test --filter=LegacySchemaTest` runs the same verification plus the
Persian round-trip and queue-allocator assertions. See [TESTING.md](TESTING.md) for how to run it.
