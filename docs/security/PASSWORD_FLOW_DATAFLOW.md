# Password and Recovery Data-Flow

**Scope:** every path that creates, stores, verifies or replaces a credential in
Hastama, with the exact code location and the data transformation at each hop.
Nothing in this document contains real credentials.

```
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ 1. LOGIN                                                                     │
 └──────────────────────────────────────────────────────────────────────────────┘

 Browser (login.html + script.js)
   password typed into <input type="password">
   │  HTTPS (internal CA) — never sent in a URL, never logged
   ▼
 POST /login_user                              app/api/routes/auth.py
   │  captcha validated first (app/services/captcha.py, secrets.choice)
   │  rate limit: 15 failures / 600 s per IP, per-account counter
   ▼
 fetch_user_for_login(cursor, username)        app/core/password_utils.py
   │  SELECT username, role, password, [password_hash], [is_active]
   │      FROM user_table WHERE LTRIM(RTRIM(username)) = ?      ← parameterised
   │  (explicit columns — the hash is read for verification only and is never
   │   returned to the client)
   ▼
 verify_password(password, row)                app/core/password_utils.py
   ├─ bcrypt.checkpw(password, hash)            → bcrypt (modern rows)
   ├─ sha512(password) vs stored                → legacy SHA-512 rows, compare_digest
   └─ plaintext equality                        → oldest rows, compare_digest
   │  result: ok / fail; failures are counted, logged, and answered identically
   ▼
 on success                                    app/api/routes/auth.py
   session.clear()                              ← session fixation defence
   session["username"], session["is_admin"] (bool, never from the request body)
   session["sid"] = secrets.token_hex(32)       ← server-side session id
   session["csrf_token"] = secrets.token_hex(32)
   INSERT INTO user_sessions(...)               ← registry row (revocation anchor)
   audit event: AUTHENTICATION / login / success
```

```
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ 2. STORAGE FORMAT                                                           │
 └──────────────────────────────────────────────────────────────────────────────┘

 hash_password(password)                        app/core/password_utils.py
   bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=12))        → bytes
   ▼
 write paths (all of them clear the legacy column):
   • /add_user        → insert_user_with_optional_hash(...)  password_hash = <bcrypt>
   • /update_user     → setpassword → password = '' , password_hash = ?
   • /reset_password  → password = '' , password_hash = ? (ALTER TABLE fallback)
   • registration approval → same explicit-column pattern
   Legacy tolerance: rows that still hold SHA-512/plaintext keep working until
   the user logs in or the password is changed; no new plaintext is ever written.
```

```
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ 3. FORGOTTEN PASSWORD (two-person: requester + master admin)                │
 └──────────────────────────────────────────────────────────────────────────────┘

 Browser → POST /forgot_password                app/api/routes/auth.py
   │  limiter: 5 / 600 s per IP, 3 / 3600 s per username
   │  if HASTAMA_HMAC_SECRET missing → fail closed (message: recovery disabled)
   ▼
 fetch_user_for_login(cursor, username)
   ├─ unknown account → generate_request_id() (decoy) + identical body
   └─ known account   → create_password_reset_request(...)   app/services/audit.py
        code = 8 random chars from secrets.choice        (shown to nobody)
        code_hash = HMAC-SHA256(server secret, code)     ← stored, never the code
        INSERT INTO password_reset_requests(request_id, username, code_hash,
                                            code_expires_at, code_attempts,
                                            max_attempts, status='pending')
   ▼
 response: { success, unified message, request_id }   (same shape in both branches)

 Master administrator (session + _master_admin)        app/api/routes/master_admin.py
   POST /master-admin/api/password-resets/{id}/approve
     re-generates/reveals the code once, status → 'approved', audit event written

 User → POST /reset_password                    app/api/routes/auth.py
   │  limiter 5 / 600 s per IP
   ▼
 verify_recovery_code(request_id, code)         app/services/audit.py
   ├─ unknown request id         ─┐
   ├─ attempts >= max_attempts   ─┤ all four answer with CODE_REJECTED_MESSAGE
   ├─ code mismatch (compare_digest, constant time, counter incremented) ─┘
   └─ code matches → then, and only then:
        • expired  → "code expired"      (state UPDATEs remain internal)
        • not approved → "already processed"
        • success  → status='completed', completed_at=now
   ▼
 UPDATE user_table SET password = '', password_hash = <bcrypt> WHERE username = ?
 session_registry.revoke_user_sessions(username, "password_reset")
 audit: AUTHENTICATION / password_reset_complete (sessions_revoked = n)
```

## Invariants verified in this assessment

| # | Invariant | Evidence |
|---|---|---|
| 1 | No plaintext password is ever written by a new code path | `insert_user_with_optional_hash`, `/update_user`, `/reset_password`, registration approval all write `password_hash`; `tests/test_security_hardening.py::TestPasswordStorage` |
| 2 | A password is never logged, echoed or returned by an API | explicit column lists; `_safe_error_message` returns a generic string; error handlers log only the exception type |
| 3 | Recovery codes exist only as an HMAC digest at rest | `audit._hash_code` + `password_reset_requests.code_hash` |
| 4 | Recovery is unusable without `HASTAMA_HMAC_SECRET` (fail closed) | `recovery_codes_available()` → request refused; test `test_forgot_password_fails_closed_without_hmac_key` |
| 5 | Requests for unknown accounts are indistinguishable from known ones | identical body + decoy request id (`test_known_and_unknown_accounts_get_an_identical_answer`) |
| 6 | Rejection reasons are not an enumeration oracle | `verify_recovery_code` reveals reason only after the code matches (`test_pending_request_is_indistinguishable_without_the_code`) |
| 7 | A successful reset kills every existing session of that user | `session_registry.revoke_user_sessions` |
| 8 | Comparisons are constant time | `hmac.compare_digest` in `password_utils.py`, `audit.py`, `araz_api.py` |
| 9 | Password policy enforced on both set paths | `validate_password_policy` (≥8 chars, upper, lower, digit, symbol, ≤128) |
| 10 | Legacy plaintext rows are tolerated but never created | `verify_password` branch order + `password = ''` on every write |

## Unverified in this environment

* The live contents of `user_table` (how many rows still hold legacy SHA-512 or
  plaintext) require DBA access: see `MANUAL_VERIFICATION_CHECKLIST.md` M-2.
* bcrypt cost factor of existing hashes — read back on the DB host (`DBA-3`).
