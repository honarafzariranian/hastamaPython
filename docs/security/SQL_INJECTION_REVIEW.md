# SQL Injection Review — Hastama

**Scope:** every dynamic SQL statement in `app/` (AST scan, not grep-only).
**Method:** AST scan of all `.execute()` / `.executemany()` calls, then manual
reading of every non-constant argument.
**Date:** 2026-09-19
**Branch:** `arena/01a0b5ec-hastama-lab` (working tree, HEAD `38ca85f` + uncommitted hardening)

---

## 1. Scan method

`/tmp/sqlscan.py` walks `app/**/*.py` with `ast`, finds every call whose
attribute is `execute`, `executemany` or `prepare`, and reports the first
argument unless it is a plain string constant:

* `JoinedStr` (f-string) → all `FormattedValue` expressions are printed;
* `Name` (variable) → the variable name is printed and traced to its assignment;
* `BinOp` / `Call` (concatenation, `.format`) → printed verbatim.

Result: **52 flagged call sites**. Every one was read.

## 2. Result summary

| Class | Count | Verdict |
|---|---|---|
| f-string built only from fixed literal fragments (allow-listed) | 30 | SAFE |
| Variable holding a fully constant statement | 17 | SAFE |
| Schema/migration files read from disk (`read_text`) | 3 | SAFE (repo-controlled SQL) |
| Column/assignment lists built from fixed literals | 3 | SAFE |
| Table name interpolated into SQL text | 2 | 1 allow-listed (fixed), 1 in an unused migration script (LOW, informational) |

**No user-controlled value reaches SQL text.** Every value — including the
`LIKE` search terms — is bound as a parameter (`?` placeholders, `pyodbc`
parameter binding).

## 3. Evidence per family

### 3.1 `master_admin.py` — filter clauses (7 functions)

```
app/api/routes/master_admin.py:215-233   where.append("event_type = ?"); params.append(event_type)
app/api/routes/master_admin.py:312-317   where.append("LTRIM(RTRIM(LOWER(role))) = ?"); params.append(role.lower())
app/api/routes/master_admin.py:504-506   where.append("is_active = 1") / "username LIKE ?" + params
app/api/routes/master_admin.py:562, 633, 706, 780   fixed column predicates + params
```

`clause = " WHERE " + " AND ".join(where)` contains only the fixed fragments
above; each fragment has exactly one `?` per appended value. Search terms are
`f"%{search}%"` **bound values**, not SQL text. Verdict: **SAFE**.

### 3.2 `notifications.py`

* `:292` / `:547` — clause fragments are `"1=1"`, `"n.title LIKE ?"` and
  `f"n.{field} = ?"` where `field` iterates over a hard-coded tuple
  (`status`, `type`, `priority`) and the value must pass an allow-listed set
  before the clause is appended. **SAFE**.
* `:145` — `condition` is selected from a 4-entry literal dictionary keyed by
  `target_type`; `target_type` outside the dictionary raises `KeyError`
  (no fall-through to SQL). **SAFE**.
* `:695` `_owned_update(...)` — `expression` is one of three literals supplied
  by the three call sites (`read_at=...`, `read_at=NULL`,
  `dismissed_at=...`), never from the request. Notification id and username are
  parameters. **SAFE** (documented as an internal helper contract).

### 3.3 `registration.py:400,406`

`where_sql` is `" AND ".join(where_clauses)`; the only fragments are
`"status = ?"`, `"status IN ('pending', 'approved', 'rejected')"` and a
four-column `LIKE ?` group. Values bound. **SAFE**.

### 3.4 `services/ticketing.py`

* `:280` / `:282` — `where` from fixed visibility/status/priority fragments;
  `order` is chosen from a 3-key literal dictionary with a literal default
  (`sort` cannot inject). **SAFE**.
* `:512` — assignment list from fixed literals (`"status=?"`,
  `"resolved_at=SYSUTCDATETIME()"`, …); values bound. **SAFE**.

### 3.5 `araz_api.py:541`

`updates` holds only `"vrood = ?"` / `"khoroj = ?"`. **SAFE**.

### 3.6 `core/password_utils.py:271`

Column list is `["username", "role", "password"]` plus
`"password_hash" if has_hash else "NULL AS password_hash"` — the conditional
is driven by the DB schema introspection helper, not by user input. **SAFE**.

### 3.7 `main.py`

* `:1378` `_notify_requester_status(table, ...)` — `table` was a caller literal
  at all five call sites (`mrkhc_table`, `totalpass_table`, `ezafe_table`), but
  the helper is now additionally guarded by the allow-list
  `_NOTIFY_STATUS_TABLES` (`main.py:1376-1387`); an unexpected name is logged
  and the function returns without querying. **SAFE (hardened)**.
* `:2668` — `set_clauses` from the fixed literals `"password = ''"`,
  `"password_hash = ?"`, `"password = ?"`. **SAFE**.
* `:1479, 3009, 3049, 3059, 3103, 3139, 3188, 3235, 3242, 3308, 3406, 3436,
  3478, 3485, 3544, 4069` — the flagged `Name` arguments are variables that
  hold *constant* statement text (verified by reading each assignment in the
  same function); all values are parameters. **SAFE**.

### 3.8 `services/araz_connector.py:609`

`query` is assigned from constant text inside the function. **SAFE**.

### 3.9 `app/tempexport.py:18,45` — LOW / informational

```python
cursor.execute(f"SELECT COLUMN_NAME, ... WHERE TABLE_NAME = '{table_name}'")
cursor.execute(f"SELECT * FROM {table_name}")
```

`table_name` comes from `INFORMATION_SCHEMA.TABLES` (the database catalogue),
so no HTTP input reaches it. The script is **not imported by the application**,
hard-codes its DSN and writes `SELECT *` output (including `user_table`
credentials) to `exported_data.sql` — the artefact that has been quarantined
(see `EXPORTED_DATA_SQL_HASH.txt`). Finding: *tempexport.py must not be shipped
or run in production*; see the report's file-handling / data-exposure section.

## 4. Parameterisation spot checks (runtime)

`tests/test_security_hardening.py` asserts that the login lookup and the
registration read-back select explicit columns, and
`tests/test_security_regressions.py` greps for `SELECT *` patterns on
`user_table`. The AST scan above is the primary evidence for this section.

## 5. Verdict

**SQL injection: NOT CONFIRMED for any application code path.** The only
interpolation of an identifier is guarded by an allow-list, and the only
unguarded interpolation lives in a non-shipped migration script. Residual
actions: delete/retire `app/tempexport.py` (recommended) and keep the
`_NOTIFY_STATUS_TABLES` allow-list in place.
