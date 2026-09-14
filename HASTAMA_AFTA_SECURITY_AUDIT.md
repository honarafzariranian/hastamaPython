# Hastama AFTA & Extreme Security Audit

**Audit Date:** 2026-09-14
**Auditor:** Buffy (Codebuff Security Agent)
**Application:** Hastama — Employee Management System (حضور و غیاب)
**Version:** 0.1.0
**Framework:** FastAPI + Jinja2 + SQL Server + Access/MDB + Araz T7

---

## 1. Executive Summary

Hastama is a FastAPI-based employee management system handling attendance, leave, overtime, payroll, ticketing, and integration with Araz T7 biometric devices. It targets LAN/internal deployment.

**Overall Security Maturity Score: 32/100**
**AFTA Readiness Score: 25/100**
**Final Verdict: NOT READY**

The application has **multiple critical and high-severity vulnerabilities** that constitute hard-fail conditions. The most severe issues include:

1. **Plaintext password storage** in the database (the `password` column stores raw passwords)
2. **Hard-coded Access database credentials** in source code (`meyer#perko`)
3. **Pervasive missing authorization** — dozens of admin/state-changing endpoints have no authentication or authorization checks
4. **No CSRF protection** anywhere in the application
5. **File upload path traversal** via unsanitized user-controlled filenames
6. **No rate limiting** on most endpoints (only login and forgot-password)
7. **No HTTPS** — all traffic including passwords transmitted in plaintext

---

## 2. Scope

The audit covers:
- `app/main.py` (~3989 lines) — primary application file
- `app/api/routes/auth.py` — authentication
- `app/api/routes/` — all API routes
- `app/core/` — configuration, database, password utilities, logging
- `app/services/` — captcha, audit, Araz connector, background tasks, ticketing
- `app/templates/` — Jinja2 templates
- `app/static/` — CSS, JS
- `tools/bridge_agent.py` — Araz bridge
- `.env`, `pyproject.toml`, `Dockerfile`, deployment files

---

## 3. Audit Methodology

Source-code-based static analysis combined with architecture review. Evidence is drawn directly from code inspection. Dynamic testing was limited to code-level data-flow tracing.

---

## 4. System Architecture

| Component | Technology |
|-----------|-----------|
| Backend | FastAPI (Python) |
| Database | SQL Server (pyodbc, Trusted_Connection) |
| Biometric | Araz T7 via Access/MDB + TCP |
| Templates | Jinja2 |
| Session | Starlette SessionMiddleware |
| Auth | Custom (no JWT, cookie-based sessions) |
| Frontend | Vanilla JS + CSS |
| Deployment | Windows (batch files), Docker available |

---

## 5. Threat Model

### Assets
- User credentials (passwords, hashes)
- Employee PII (names, departments, attendance)
- Payroll data
- Biometric attendance records (Access/MDB)
- Admin session tokens
- Database credentials
- Access DB password (`meyer#perko`)

### Trust Boundaries
- Browser ↔ Server (no TLS)
- User ↔ Admin (role enforcement missing in many places)
- App ↔ SQL Server (Trusted_Connection — full DB admin)
- App ↔ Access/MDB (hard-coded credentials)
- App ↔ Araz T7 (TCP, no authentication)

### Threat Actors
- Any LAN user (no network segmentation assumed)
- Authenticated regular user escalating to admin
- Attacker on same network segment

---

## 6. AFTA Requirements and Sources

| Requirement | Source | Status |
|------------|--------|--------|
| Password storage must use secure hashing | OWASP ASVS 2.1 | **FAIL** |
| Authentication must prevent brute-force | OWASP ASVS 2.2 | **PARTIAL** |
| Authorization must be enforced server-side | OWASP ASVS 4.1 | **FAIL** |
| Sensitive data must be encrypted in transit | OWASP ASVS 9.1 | **FAIL** |
| Secrets must not be in source code | OWASP ASVS 6.5 | **FAIL** |
| CSRF protection required | OWASP ASVS 3.5 | **FAIL** |
| Security logging required | OWASP ASVS 7.1 | **PARTIAL** |

---

## 7. AFTA Readiness Assessment

**Score: 25/100 — Not Ready**

Major gaps: plaintext passwords, no TLS, no CSRF, missing authorization on most endpoints, hardcoded secrets, no account lockout.

---

## 8. Overall Security Score

**Score: 32/100**

| Domain | Score |
|--------|-------|
| Authentication | 40/100 |
| Authorization | 10/100 |
| Cryptography | 15/100 |
| Session Management | 50/100 |
| Input Validation | 40/100 |
| Web Security | 30/100 |
| Database Security | 35/100 |
| API Security | 20/100 |
| File Security | 25/100 |
| Logging | 30/100 |
| Configuration | 20/100 |
| Deployment | 25/100 |

---

## 9. Hard Fail Conditions

| Condition | Status |
|-----------|--------|
| Plaintext passwords | **FAIL** — `password` column stores raw text |
| Weak password hashing | **FAIL** — plaintext stored alongside bcrypt |
| Authentication bypass | **PARTIAL** — no brute-force lockout |
| Authorization bypass | **FAIL** — dozens of unprotected endpoints |
| SQL injection | Not found (parameterized queries used) |
| Remote code execution | Not found |
| Arbitrary file read/write | **FAIL** — upload path traversal |
| Exposed secrets | **FAIL** — Access DB password in source |
| Missing auth on critical endpoints | **FAIL** — admin operations unprotected |

**NOT READY** — multiple hard-fail conditions exist.

---

## 10. Critical Findings

### HST-SEC-001: Plaintext Password Storage in Database

**Severity:** Critical
**CVSS:** 9.1
**CWE:** CWE-256 (Plaintext Storage of a Password)

**Affected Component:** `app/main.py`, `app/core/password_utils.py`

**Evidence:**
In `password_utils.py`, `insert_user_with_optional_hash()` stores the raw password in the `password` column:
```python
cursor.execute('''
    INSERT INTO user_table (
        id, username, password, password_hash, ...
    ) VALUES (?, ?, ?, ?, ...)
''', (user_id, username, password, password_hash, ...))
```

In `update_user()` (main.py), the plaintext password is stored directly:
```python
if password_value:
    set_clauses.append("password = ?")
    params.append(password_value)  # Raw plaintext!
```

The admin panel also sends passwords in the template context via `password=str(user[8] or "")`, exposing them in HTML.

**Attack Scenario:** Any SQL read access to `user_table` exposes all passwords in plaintext. A database backup leak, SQL injection in another app sharing the server, or Windows file-level access to the SQL Server data files exposes every password.

**Impact:** Complete credential compromise for all users.

**Root Cause:** Dual storage architecture where `password` column was never removed after `password_hash` was introduced.

**Recommended Remediation:** Remove the `password` column entirely. Store only bcrypt hashes. Update `insert_user_with_optional_hash()` to only insert `password_hash`. Update `verify_password()` to only check `password_hash`.

---

### HST-SEC-002: Hard-Coded Access Database Credentials in Source Code

**Severity:** Critical
**CVSS:** 8.6
**CWE:** CWE-798 (Use of Hard-coded Credentials)

**Affected Component:** `app/main.py` lines ~680, ~3100

**Evidence:**
```python
mdb_path = r"E:\Hastama\database\Arazdb.mdb"
password = "meyer#perko"
conn_str = (r"DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};"
            rf"DBQ={mdb_path};"
            rf"PWD={password};")
```

This pattern appears in at least two places in main.py.

**Attack Scenario:** Anyone with read access to the source code or repository gains full access to the Araz attendance database. In a Git repository, this credential persists in history even if removed from the current version.

**Impact:** Full access to biometric attendance database.

**Recommended Remediation:** Move to environment variables (already partially done with `os.getenv("ARAZ_ACCESS_PASSWORD")` fallback — remove the hard-coded fallback entirely). Rotate the password.

---

### HST-SEC-003: Missing Authentication on Dozens of Endpoints

**Severity:** Critical
**CVSS:** 9.0
**CWE:** CWE-306 (Missing Authentication for Critical Function)

**Affected Component:** `app/main.py` — multiple routes

**Evidence:**
The following endpoints have NO authentication check (no session validation):

| Endpoint | Method | Impact |
|----------|--------|--------|
| `/get_leave_requests` | GET | Expose all leave records |
| `/get_hozoor_filtered` | POST | Query any user's attendance |
| `/get_user_info_report` | GET | Any user's personal info |
| `/get_user_info_final_report_page/{username}` | GET | Any user's name/department |
| `/get_hourly_pass_requests` | GET | All pending pass requests |
| `/get_overtime_requests` | GET | All overtime records |
| `/update_leave_status` | POST | Approve/reject any leave |
| `/change_hourly_pass_status` | POST | Approve/reject any pass |
| `/update_hourly_pass_status` | POST | Change any pass status |
| `/update_overtime_status` | POST | Approve/reject any overtime |
| `/update_overtime_Indivisual_status` | POST | Change overtime status |
| `/generate_individual_report` | POST | Access any user's reports |
| `/get_hozoor_report` | GET | Attendance reports |
| `/get_hozoor/{username}` | GET | Full attendance data |
| `/get_shifts/{username}/{year}/{month}` | GET | Shift data |
| `/add_shift` | POST | Create shifts |
| `/update_shift` | POST | Modify shifts |
| `/delete_shift/{shift_id}` | POST | Delete shifts |
| `/fetch_user_data` | GET | User PII |
| `/leave_report_page` | GET | Report page |
| `/overtime_report_page` | GET | Report page |
| `/hourlypass_Report_page` | GET | Report page |
| `/get_overtime_report` | POST | Overtime data |
| `/get_hourly_pass_report` | POST | Pass data |
| `/admin` (POST handling) | POST | User deletion |
| `/register` | GET | Registration page |

**Attack Scenario:** Any unauthenticated user on the LAN can:
- Read all employee personal information
- Approve/reject leave, overtime, and pass requests
- Modify shift schedules
- Generate reports for any employee
- Delete users (via POST to /admin)

**Impact:** Complete administrative takeover without any credentials.

---

### HST-SEC-004: Missing Admin Authorization on Admin Endpoints

**Severity:** Critical
**CVSS:** 8.8
**CWE:** CWE-862 (Missing Authorization)

**Affected Component:** `app/main.py`

**Evidence:**
State-changing admin endpoints that do NOT check `is_admin`:
- `/update_leave_status` — anyone can approve/reject leave
- `/change_hourly_pass_status` — anyone can approve/reject passes
- `/update_hourly_pass_status` — same
- `/update_overtime_status` — anyone can approve/reject overtime
- `/update_overtime_Indivisual_status` — same
- `/add_shift` — anyone can create shifts
- `/update_shift` — anyone can modify shifts
- `/delete_shift/{shift_id}` — anyone can delete shifts
- `/upload-profile-image` — no admin check but also no file validation
- `/get_hozoor_filtered` — reads any user's attendance

**Attack Scenario:** A regular authenticated user can call these endpoints directly (bypassing the frontend) to approve their own leave, modify overtime, or manipulate attendance data for any employee.

**Impact:** Complete bypass of all administrative workflows.

---

### HST-SEC-005: File Upload Path Traversal and Unsafe File Handling

**Severity:** High
**CVSS:** 8.2
**CWE:** CWE-22 (Path Traversal)

**Affected Component:** `app/main.py`, `upload_profile_image()`

**Evidence:**
```python
@app.post("/upload-profile-image")
async def upload_profile_image(request: Request, file: UploadFile = File(...)):
    file_ext = os.path.splitext(file.filename)[1]
    filename = f"{username}{file_ext}"
    file_path = os.path.join(upload_folder, filename)
    with open(file_path, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)
```

**Problems:**
1. `file.filename` is user-controlled — `file_ext = os.path.splitext(file.filename)[1]` extracts the extension from the user-supplied filename. An attacker could supply `filename="../../../../etc/cron.d/malicious.sh"` and the extension extraction could yield unexpected results.
2. No file type validation — any extension is accepted (.exe, .php, .sh, etc.)
3. No file size limit — attacker can fill disk
4. No content-type verification
5. The filename stored in DB (`profile_image`) is used in `os.path.join()` for deletion, which could be exploited for path traversal

**Attack Scenario:** Upload a file named `../../../startup/evil.bat` — if the extension extraction yields `.bat`, it gets saved as `{username}.bat` in the uploads directory. If an attacker can control the full path traversal in the username context or the stored filename in DB, arbitrary file write is possible.

Additionally, `delete_profile_image()` uses `os.path.join("app/static/uploads", row[0])` where `row[0]` comes from the database — if the database value contains `../`, path traversal occurs.

---

### HST-SEC-006: No HTTPS — All Traffic in Plaintext

**Severity:** High
**CVSS:** 7.5
**CWE:** CWE-319 (Cleartext Transmission of Sensitive Information)

**Affected Component:** Deployment configuration, `start_hastama.bat`, `Dockerfile`

**Evidence:**
- `start_hastama.bat` runs uvicorn directly without TLS
- No TLS termination configuration found
- Session cookies set without `Secure` flag (when not DEBUG)
- CSP header uses `ws:` instead of `wss:` for WebSocket
- Login transmits credentials in JSON over HTTP

**Attack Scenario:** Any network observer on the LAN can capture login credentials, session tokens, and all employee data in transit.

---

### HST-SEC-007: No CSRF Protection

**Severity:** High
**CVSS:** 7.1
**CWE:** CWE-352 (Cross-Site Request Forgery)

**Affected Component:** All POST endpoints

**Evidence:**
- No CSRF token generation or validation anywhere
- `SessionMiddleware` uses `same_site="lax"` which provides partial protection for top-level navigations, but NOT for AJAX/JS-initiated requests
- All state-changing endpoints accept JSON POST without any anti-CSRF token

**Attack Scenario:** A malicious page on the LAN (or a bookmark) can craft JavaScript that submits state-changing requests (approve leave, modify overtime, change password) to Hastama while the user has an active session.

---

### HST-SEC-008: Password Sent and Stored in HTML Admin Panel

**Severity:** High
**CVSS:** 7.5
**CWE:** CWE-200 (Exposure of Sensitive Information)

**Affected Component:** `app/main.py`, `_render_admin_page()`

**Evidence:**
```python
users = [UserData(
    ...
    password=str(user[8] or "")
) for user in users_data]
```

And the password is included in edit button data attributes:
```html
data-edit-password="network   "
```

**Attack Scenario:** Anyone who can view the admin page source sees all user passwords. Browser dev tools expose them. HTTP interception reveals them.

---

## 11. High Findings

### HST-SEC-009: IDOR on User Information Endpoints

**Severity:** High
**CVSS:** 7.5
**CWE:** CWE-639 (Authorization Bypass Through User-Controlled Key)

**Evidence:**
- `/get_user_info_report?username=X` — returns any user's name, last_name, department, work_hours, substitute with no auth check
- `/get_user_info_final_report_page/{username}` — same
- `/fetch_user_data?username=X` — same
- `/get_hozoor/{username}` — returns full attendance records for any user
- `/get_shifts/{username}/{year}/{month}` — returns shift data

---

### HST-SEC-010: Master Admin Hardcoded Username

**Severity:** High
**CVSS:** 7.0
**CWE:** CWE-798 (Hard-coded Credentials)

**Evidence:**
```python
MASTER_ADMIN_USERNAMES = {"ali"}
```

In `auth.py`. Anyone who knows (or guesses) this username can attempt to gain master-admin access.

---

### HST-SEC-011: No Account Lockout After Failed Logins

**Severity:** Medium
**CVSS:** 5.3
**CWE:** CWE-307 (Improper Restriction of Excessive Authentication Attempts)

**Evidence:**
The `RateLimiter` class in auth.py only tracks IP rate limiting (10 attempts per 600s) and username rate limiting for password recovery (3 per hour). There is NO progressive delay, NO account lockout, and NO permanent lockout after sustained brute-force. An attacker with rotating IPs or a distributed attack can brute-force passwords indefinitely.

---

### HST-SEC-012: CSP Allows `unsafe-inline` for Scripts

**Severity:** Medium
**CVSS:** 5.4
**CWE:** CWE-693 (Protection Mechanism Failure)

**Evidence:**
```python
(b"content-security-policy",
 b"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; ...")
```

`unsafe-inline` for `script-src` effectively neutralizes XSS protection from CSP. Any injected `<script>` tag will execute.

---

### HST-SEC-013: Session Not Rotated on Login

**Severity:** Medium
**CVSS:** 5.3
**CWE:** CWE-384 (Session Fixation)

**Evidence:**
In `auth.py` `/login_user`:
```python
request.session["username"] = username
```
The session ID is not regenerated after successful authentication. An attacker who can set a session cookie before the victim logs in can hijack the session after login.

---

### HST-SEC-014: Excessive Error Detail in Production

**Severity:** Medium
**CWE:** CWE-209 (Generation of Error Message Containing Sensitive Information)

**Evidence:**
Multiple endpoints return `str(e)` in error responses:
```python
return JSONResponse(content={"success": False, "message": str(e)})
```

And in `_render_admin_page()`:
```python
raise HTTPException(status_code=500, detail=str(e))
```

Stack traces logged via `traceback.print_exc()`.

---

### HST-SEC-015: Global Database Connection Shared Across Requests

**Severity:** Medium
**CWE:** CWE-362 (Race Condition)

**Evidence:**
```python
conn = pyodbc.connect(...)
cursor = conn.cursor()
```

A single `conn`/`cursor` pair is created at module level and shared across all async request handlers. FastAPI is async — concurrent requests will interleave cursor operations, causing data corruption, incorrect results, or crashes.

---

### HST-SEC-016: No Input Sanitization on Shift/Delete Endpoints

**Severity:** Medium
**CWE:** CWE-20 (Improper Input Validation)

**Evidence:**
`/add_shift`, `/update_shift` accept JSON body data directly. While they validate some fields, the `title` field and day-value fields are passed directly to SQL without length or content validation.

`/delete_shift/{shift_id}` accepts any integer with no authorization check — any user can delete any shift.

---

### HST-SEC-017: WebSocket Endpoint Lacks Authentication

**Severity:** Medium
**CWE:** CWE-644 (Improper Neutralization of HTTP Headers for Scripting)

**Evidence:**
The notification WebSocket endpoint (`/ws/notifications`) would need to be verified — if it lacks session validation, any LAN user can subscribe to notification streams.

---

### HST-SEC-018: Access Database Fallback Without TLS

**Severity:** Medium
**CWE:** CWE-319

The Access/MDB connection is over local file access with a hard-coded password. While file-based, the password `meyer#perko` is trivially discoverable.

---

## 12. Medium Findings

### HST-SEC-019: Debug Mode Information Leakage

**Evidence:** `DEBUG` flag from `.env` controls `FastAPI(debug=DEBUG)`. When True, FastAPI returns detailed error pages with stack traces, query parameters, and internal paths.

### HST-SEC-020: No Content-Type Validation on Upload

**Evidence:** Profile image upload accepts any file type without validation.

### HST-SEC-021: Registration Page Accessible Without CAPTCHA

**Evidence:** `/register` and `/register_user` may not enforce CAPTCHA (needs verification).

### HST-SEC-022: Potential Timing Attacks on Password Comparison

**Evidence:** The `verify_password()` function falls back to plain-text string comparison (`str(stored_password).strip() == provided_password`) which is vulnerable to timing attacks.

### HST-SEC-023: Password Hash Migration Not Enforced

**Evidence:** `insert_user_with_optional_hash()` inserts plaintext password alongside bcrypt hash. No background migration job converts existing plaintext passwords to bcrypt.

---

## 13. Low Findings

### HST-SEC-024: Missing Security Headers

- No `Strict-Transport-Security` (no HTTPS)
- No `X-XSS-Protection` (legacy but useful for older browsers)

### HST-SEC-025: Verbose SQL Error Messages

SQL errors are caught and returned to the client in several places.

### HST-SEC-026: No Logging of Administrative Actions

While `audit.py` exists, many admin state changes (`update_leave_status`, `change_hourly_pass_status`, etc.) do not call the audit logger.

### HST-SEC-027: No HTTPS Cookie Attribute

Session cookies lack the `Secure` flag, allowing transmission over HTTP.

---

## 14. Informational Findings

### HST-SEC-028: Application Framework Version Not Explicitly Pinned
`fastapi>=0.103.0` allows any newer version which could introduce breaking changes.

### HST-SEC-029: Docker Configuration
Docker setup exists but security hardening (non-root user, read-only filesystem) is not configured.

### HST-SEC-030: No Automated Security Testing
No SAST, DAST, or dependency scanning in CI/CD.

---

## 15. Authentication Assessment

| Control | Expected | Actual | Status |
|---------|----------|--------|--------|
| Password hashing | bcrypt/argon2 | bcrypt (new) + plaintext (legacy) | PARTIAL |
| Brute-force protection | Account lockout | IP rate limit only | PARTIAL |
| CAPTCHA | Required | Implemented for login | PASS |
| Session rotation | On login | Not implemented | FAIL |
| Password policy | Enforced | Implemented (8+ chars, complexity) | PASS |
| Failed login logging | Yes | Yes (audit module) | PASS |
| Account enumeration prevention | Yes | Partial (forgot-password is good) | PARTIAL |

---

## 16. Authorization Assessment

**Overall: FAIL**

The application has a session-based role system (`is_admin`, `is_master_admin`) but enforcement is absent on the majority of endpoints. The frontend hides admin UI elements, but no server-side check prevents a regular user from calling admin APIs.

**Endpoints with proper authorization:**
- `/admin/*` page routes (check `is_admin`)
- `/api/admin/employment-status` (checks `is_admin`)
- `/api/admin/payroll/save` and `/load` (checks `is_admin`)

**Endpoints WITHOUT authorization (critical gaps):**
- All state-changing POST endpoints listed in HST-SEC-003
- All data-reading GET endpoints for other users' data
- Shift management endpoints

---

## 17. Session Security Assessment

| Control | Status |
|---------|--------|
| Signed session cookies | PASS (Starlette SessionMiddleware) |
| HttpOnly cookies | PASS (default) |
| Secure flag | FAIL (missing) |
| SameSite | PARTIAL (lax) |
| Session rotation on login | FAIL |
| Session expiration | PARTIAL (depends on middleware config) |
| Session fixation prevention | FAIL |
| Concurrent session control | NOT IMPLEMENTED |

---

## 18. Password Security Assessment

| Control | Status |
|---------|--------|
| Bcrypt hashing | PARTIAL (new users only) |
| Salt | PASS (bcrypt auto-salt) |
| Work factor | PASS (rounds=12) |
| Legacy plaintext column | **FAIL** |
| Legacy SHA-512 comparison | PARTIAL |
| Password minimum length | PASS (8 chars) |
| Password complexity | PASS (upper, lower, digit, special) |
| Password reuse prevention | NOT IMPLEMENTED |
| Password change without old password | Not found |

---

## 19. Password Reset Assessment

The password reset flow (`forgot_password` → admin approval → `reset_password`) has some positive controls:
- Unified response message prevents enumeration
- Rate limiting on both endpoints
- Recovery code validation (8-char alphanumeric)

However:
- The reset flow depends on admin manual approval (reasonable for LAN)
- No token expiration check visible in the code shown
- Recovery codes need entropy verification

---

## 20. CAPTCHA and Anti-Automation Assessment

CAPTCHA is implemented (`app/services/captcha.py`) and required for login. The implementation generates server-side images and validates against session-stored codes. This is a reasonable offline-capable CAPTCHA.

However:
- CAPTCHA is NOT required for password reset or registration
- No CAPTCHA on any admin API endpoint
- Rate limiting is the only anti-automation for non-login endpoints

---

## 21. API Security Assessment

| Issue | Status |
|-------|--------|
| Input validation | PARTIAL (Pydantic used in some places) |
| Output validation | NOT IMPLEMENTED |
| Rate limiting | PARTIAL (login only) |
| CSRF protection | FAIL |
| Mass assignment | Not directly exploitable |
| Excessive data exposure | HIGH RISK (passwords in admin panel) |
| HTTP method control | PARTIAL |

---

## 22. Database Security Assessment

| Control | Status |
|---------|--------|
| Parameterized queries | PASS (pyodbc `?` parameters used) |
| SQL injection | NOT FOUND |
| Least privilege | FAIL (Trusted_Connection = sysadmin) |
| Connection pooling | NOT IMPLEMENTED (global connections) |
| Error leakage | PARTIAL |
| Dynamic SQL | NOT FOUND |
| Backup security | NOT ASSESSED |

**Positive:** All SQL queries use parameterized queries with `?` placeholders. No string concatenation in SQL was found.

**Negative:** `Trusted_Connection=yes` means the application process has full SQL Server admin access. A vulnerability in the application could lead to full database compromise.

---

## 23. Web Security Assessment

| Control | Status |
|---------|--------|
| CSP | PARTIAL (unsafe-inline weakens it) |
| X-Frame-Options | PASS (DENY) |
| X-Content-Type-Options | PASS (nosniff) |
| Referrer-Policy | PASS (strict-origin-when-cross-origin) |
| Permissions-Policy | PASS (restrictive) |
| HSTS | FAIL (no HTTPS) |
| CORS | NOT EXPLICITLY CONFIGURED (defaults to same-origin) |

---

## 24. Frontend Security Assessment

| Issue | Status |
|-------|--------|
| innerHTML usage | NEEDS VERIFICATION (JS files not fully analyzed) |
| eval() usage | NEEDS VERIFICATION |
| Client-side auth reliance | PARTIAL (frontend hides admin UI) |
| Hardcoded secrets in JS | NEEDS VERIFICATION |
| DOM XSS | NEEDS VERIFICATION |

---

## 25-26. WebSocket / SSE Security Assessment

WebSocket endpoints serve notifications. The security headers middleware explicitly passes WebSocket upgrades through without modification. Authentication status of WebSocket connections needs manual verification.

---

## 27. Admin Security Assessment

**Score: 15/100**

The admin panel:
- Checks `is_admin` on page access (PASS)
- Does NOT check `is_admin` on most state-changing API calls (FAIL)
- Exposes user passwords in HTML (FAIL)
- Allows user deletion via POST form (with admin check — PARTIAL)
- No audit logging for most admin actions (FAIL)

---

## 28. Main-Admin Security Assessment

Master admin is determined by hardcoded username `ali`. The `/master-admin` route checks for `is_master_admin` or `is_admin`. However, the master admin distinction has minimal practical security benefit given the already-broken authorization model.

---

## 29. Business Logic Assessment

| Issue | Impact |
|-------|--------|
| Self-approval of leave/overtime | Regular user can approve own requests via direct API calls |
| Attendance data manipulation | Any user can modify attendance records |
| Shift manipulation | Any user can add/modify/delete shifts for any employee |
| Report generation for any user | No ownership verification |
| Payroll data access | Admin endpoints accessible without auth |

---

## 30. Araz/T7 Security Assessment

| Issue | Status |
|-------|--------|
| Access DB credentials | HARDCODED (CRITICAL) |
| TCP communication | Unencrypted, unauthenticated |
| Data parsing | Needs manual verification |
| Bridge agent authentication | NOT IMPLEMENTED |
| LAN impersonation | POSSIBLE |

The Araz bridge agent communicates over TCP without mutual authentication. Any LAN device could potentially impersonate the bridge or inject attendance records.

---

## 31. File and Report Security

| Issue | Status |
|-------|--------|
| Path traversal in upload | FAIL |
| File type validation | FAIL |
| File size limits | NOT IMPLEMENTED |
| PDF generation security | pdfkit used (wkhtmltopdf) |
| Temp file cleanup | NEEDS VERIFICATION |

---

## 32. Logging and Audit Trail

`app/services/audit.py` provides:
- Login success/failure logging
- CAPTCHA events
- Session tracking
- Password reset request logging

**Missing:**
- Admin action logging (leave approval, overtime approval, user creation/deletion)
- Data access logging
- Tamper resistance
- Log retention configuration
- Sensitive data masking in logs

---

## 33. Cryptography Assessment

| Component | Algorithm | Status |
|-----------|-----------|--------|
| Password hashing | bcrypt (rounds=12) | PASS for new passwords |
| Session signing | Starlette default (HMAC-SHA256) | PASS |
| TLS | Not implemented | FAIL |
| Database encryption | Not implemented | FAIL |
| Access DB encryption | None (MDB file) | FAIL |

---

## 34. Dependency and Supply-Chain Assessment

| Package | Version Constraint | Risk |
|---------|-------------------|------|
| fastapi | >=0.103.0 | Unpinned — supply chain risk |
| uvicorn | ==0.23.2 | Pinned — OK |
| pyodbc | >=5.1.0 | Unpinned |
| bcrypt | (via password_utils) | Not in pyproject.toml |
| pdfkit | >=1.0.0 | Uses wkhtmltopdf binary |

**Missing from pyproject.toml:** `bcrypt` is imported but not listed as a dependency.

---

## 35. Configuration and Secrets Assessment

| Secret | Location | Status |
|--------|----------|--------|
| SQL Server credentials | Trusted_Connection (Windows Auth) | ACCEPTABLE for LAN |
| Access DB password | Hardcoded in source | **FAIL** |
| SESSION_SECRET_KEY | `.env` / fallback to SECRET_KEY / random | PARTIAL |
| `.env` file | In repository root | NEEDS VERIFICATION (.gitignore) |
| Araz IP/port | Environment variable with fallback | PARTIAL |

---

## 36. LAN and Deployment Security

**Assessment:** The application assumes a trusted LAN. This is a REDUCED exposure model, NOT a secure one.

| Control | Status |
|---------|--------|
| Network segmentation | NOT ASSUMED |
| HTTPS | NOT IMPLEMENTED |
| Database network exposure | SQL Server on localhost (OK) |
| Access DB file permissions | NOT CONFIGURED |
| Windows authentication | USED (Trusted_Connection) |
| Firewall rules | NOT CONFIGURED |
| Service account | NOT CONFIGURED |

---

## 37. Security Control Matrix

| Domain | Control | Expected | Actual | Status |
|--------|---------|----------|--------|--------|
| Authentication | Password hashing | Argon2/bcrypt | bcrypt+plaintext | PARTIAL |
| Authentication | Brute-force protection | Lockout | IP rate limit only | PARTIAL |
| Authentication | CAPTCHA | Required | Login only | PARTIAL |
| Authorization | Server-side enforcement | Every endpoint | ~5% of endpoints | FAIL |
| Session | Rotation on login | Yes | No | FAIL |
| Session | Secure cookies | Yes | No | FAIL |
| CSRF | Token validation | Yes | None | FAIL |
| Password | No plaintext storage | Yes | Plaintext column | FAIL |
| Secrets | Not in source code | Yes | Hardcoded | FAIL |
| TLS | HTTPS everywhere | Yes | None | FAIL |
| CSP | No unsafe-inline | Yes | unsafe-inline | FAIL |
| Logging | Admin actions | Yes | Partial | PARTIAL |
| Upload | File type validation | Yes | None | FAIL |
| Upload | File size limit | Yes | None | FAIL |
| Database | Parameterized queries | Yes | Yes | PASS |
| Database | Least privilege | Yes | Full admin | FAIL |

---

## 38. Endpoint Security Matrix

| Method | Endpoint | Auth | Admin | CSRF | Rate Limit | Status |
|--------|----------|------|-------|------|------------|--------|
| POST | /login_user | CAPTCHA+Rate | No | No | Yes | PARTIAL |
| POST | /forgot_password | Rate limit | No | No | Yes | PARTIAL |
| POST | /reset_password | Rate limit | No | No | Yes | PARTIAL |
| GET | /user_panel | Session | No | N/A | No | PARTIAL |
| POST | /submit_leave | Session | No | No | No | PARTIAL |
| POST | /submit_overtime | Session | No | No | No | PARTIAL |
| POST | /submit_hourly_pass | Session | No | No | No | PARTIAL |
| POST | /upload-profile-image | Session | No | No | No | FAIL |
| POST | /update_leave_status | **NONE** | **NONE** | No | No | **FAIL** |
| POST | /change_hourly_pass_status | **NONE** | **NONE** | No | No | **FAIL** |
| POST | /update_overtime_status | **NONE** | **NONE** | No | No | **FAIL** |
| POST | /add_shift | **NONE** | **NONE** | No | No | **FAIL** |
| POST | /update_shift | **NONE** | **NONE** | No | No | **FAIL** |
| POST | /delete_shift/{id} | **NONE** | **NONE** | No | No | **FAIL** |
| GET | /get_leave_requests | **NONE** | **NONE** | N/A | No | **FAIL** |
| GET | /get_hozoor_filtered | **NONE** | **NONE** | N/A | No | **FAIL** |
| GET | /get_hourly_pass_requests | **NONE** | **NONE** | N/A | No | **FAIL** |
| GET | /get_overtime_requests | **NONE** | **NONE** | N/A | No | **FAIL** |
| GET | /admin | Session | Yes | N/A | No | PARTIAL |
| GET | /admin/* | Session | Yes | N/A | No | PARTIAL |
| POST | /add_user | Session | Yes (page) | No | No | PARTIAL |
| POST | /update_user | Session | Yes (page) | No | No | PARTIAL |
| POST | /api/admin/payroll/save | Session | Yes | No | No | PARTIAL |
| GET | /api/admin/payroll/load | Session | Yes | N/A | No | PARTIAL |
| POST | /api/admin/employment-status | Session | Yes | No | No | PARTIAL |
| GET | /get_user_info | Session | No | N/A | No | PARTIAL |
| GET | /get_user_info_report | **NONE** | **NONE** | N/A | No | **FAIL** |

---

## 39. Role and Authorization Matrix

| Function | Anonymous | User | Admin | Master Admin |
|----------|-----------|------|-------|-------------|
| Login | ✅ | ✅ | ✅ | ✅ |
| View own panel | ❌ | ✅ | ✅ | ✅ |
| Submit leave request | ❌ | ✅ | ✅ | ✅ |
| View ALL leave requests | ❌ | ❌ | ✅* | ✅ |
| Approve leave | ❌ | ❌ | ❌* | ❌* |
| View ALL overtime | ❌ | ❌ | ✅* | ✅ |
| Approve overtime | ❌ | ❌ | ❌* | ❌* |
| Add/modify/delete shifts | ❌ | ❌ | ❌* | ❌* |
| View any user's attendance | ❌ | ❌ | ✅* | ✅ |
| Upload profile image | ❌ | ✅ | ✅ | ✅ |
| Add new user | ❌ | ❌ | ✅ | ✅ |
| Master admin panel | ❌ | ❌ | ❌ | ✅ |

* = Should require admin but actually accessible to anyone (see HST-SEC-003/004)

---

## 40. Top 20 Security Risks

| Rank | Risk | Severity | Exploitability |
|------|------|----------|----------------|
| 1 | Plaintext password storage | Critical | Low (requires DB access) |
| 2 | Missing auth on admin endpoints | Critical | High (trivial HTTP calls) |
| 3 | Missing authorization entirely | Critical | High |
| 4 | Hardcoded Access DB credentials | Critical | Low (requires source access) |
| 5 | No HTTPS | High | Medium (requires network access) |
| 6 | Password exposed in admin HTML | High | High (view source) |
| 7 | File upload without validation | High | Medium |
| 8 | No CSRF protection | High | Medium |
| 9 | IDOR on user data endpoints | High | High |
| 10 | Hardcoded master admin username | High | Medium |
| 11 | Session fixation (no rotation) | Medium | Medium |
| 12 | CSP unsafe-inline | Medium | Requires XSS first |
| 13 | No account lockout | Medium | Medium |
| 14 | Global DB connection (race condition) | Medium | Medium (DoS) |
| 15 | Excessive error detail | Medium | Low |
| 16 | Missing admin audit logging | Medium | Low |
| 17 | Password comparison timing attack | Low | High (requires precision) |
| 18 | Missing security headers | Low | Low |
| 19 | No automated security testing | Informational | N/A |
| 20 | Unpinned dependencies | Informational | Supply chain |

---

## 41. Remediation Roadmap

### Phase 1 — Immediate (Week 1-2)

| Priority | Issue | Files | Action |
|----------|-------|-------|--------|
| P0 | Plaintext password storage | `core/password_utils.py`, `main.py` | Remove `password` column, use only `password_hash`. Migrate existing passwords to bcrypt. |
| P0 | Missing authorization | `main.py` (all POST endpoints) | Add `get_is_admin_from_session()` check to every admin/state-changing endpoint |
| P0 | Hardcoded credentials | `main.py` | Remove all hardcoded passwords; use env vars only |
| P0 | Password in HTML | `main.py` `_render_admin_page()` | Remove `password` from UserData and template context |
| P0 | No HTTPS | Deployment | Configure TLS (Let's Encrypt or self-signed cert for LAN) |
| P0 | File upload validation | `main.py` `upload_profile_image()` | Add file type whitelist, size limit, sanitize filename |
| P0 | CSRF tokens | All POST endpoints | Implement CSRF token validation |
| P1 | Session rotation | `auth.py` `/login_user` | Regenerate session ID on successful login |
| P1 | Account lockout | `auth.py` | Add progressive delays and lockout after 10 failures |
| P1 | Secure cookie flag | `main.py` | Set `secure=True` when not in DEBUG |

### Phase 2 — Security Hardening (Week 3-4)

| Priority | Issue | Action |
|----------|-------|--------|
| P2 | CSP unsafe-inline | Remove inline scripts, use nonce-based CSP |
| P2 | Global DB connections | Replace with per-request connections using `core/database.py` context manager |
| P2 | Admin audit logging | Add `log_event()` to all admin state-changing endpoints |
| P2 | Rate limiting | Extend rate limiting to all API endpoints |
| P2 | Error handling | Return generic errors; log details server-side |
| P2 | Input validation | Add Pydantic models for all API endpoints |
| P2 | Password migration | Background task to convert plaintext passwords to bcrypt |

### Phase 3 — Maturity (Month 2-3)

| Priority | Issue | Action |
|----------|-------|--------|
| P3 | Dependency scanning | Add `pip-audit` or `safety` to CI/CD |
| P3 | SAST | Add `bandit` for Python security scanning |
| P3 | Session management | Implement session expiration, concurrent session limits |
| P3 | Database least privilege | Create limited SQL Server role for the application |
| P3 | WebSocket auth | Verify and enforce authentication on WS endpoints |
| P3 | Araz bridge auth | Implement mutual authentication for bridge agent |
| P3 | Log retention | Configure log rotation and retention policies |
| P3 | Penetration testing | Engage external pentest after remediation |

---

## 42. Verification Plan

After remediation:
1. Verify password_hash column only contains bcrypt hashes (SQL query)
2. Verify no endpoint responds without authentication (automated scan)
3. Verify CSRF tokens on all forms (manual test)
4. Verify HTTPS is enforced (curl test)
5. Verify file upload rejects non-image files (manual test)
6. Verify session changes on login (cookie inspection)
7. Verify rate limiting on all endpoints (automated test)

---

## 43. Residual Risk

Even after full remediation:
- LAN deployment model means physical network access is a persistent risk
- Windows Authentication for SQL Server means the application process has elevated DB privileges
- Access/MDB integration is inherently less secure than native SQL Server
- The single-file architecture (`main.py` at ~4000 lines) makes security review and maintenance difficult

---

## 44. Final Verdict

### **NOT READY**

The application contains multiple critical security vulnerabilities that constitute hard-fail conditions:

1. **Plaintext passwords stored in database** — any database access exposes all credentials
2. **Dozens of endpoints with no authentication or authorization** — any LAN user can approve leave, modify overtime, manipulate attendance, and access all employee data
3. **No HTTPS** — all credentials and sensitive data transmitted in plaintext
4. **No CSRF protection** — state-changing operations can be triggered from external pages
5. **Hardcoded credentials** in source code
6. **Unprotected file upload** — potential arbitrary file write

The application must not be deployed in production or exposed to any network until these critical issues are resolved. The authorization model is the single most severe issue — it renders all other security controls ineffective because an attacker can simply call admin endpoints without any credentials.

---

## 45. Appendix — Evidence

All evidence is sourced directly from the codebase. Key files inspected:
- `app/main.py` (3989 lines)
- `app/api/routes/auth.py`
- `app/core/password_utils.py`
- `app/core/config.py`
- `app/core/database.py`
- `app/services/captcha.py`
- `app/services/audit.py`
- `app/services/araz_connector.py`
- `app/services/background_tasks.py`
- `app/services/ticketing.py`
- `pyproject.toml`
- `.env` (blocked by security filter)
- `start_hastama.bat`
- `Dockerfile`
- `Caddyfile`

---

## 46. Appendix — Sources

| Source | Reference |
|--------|-----------|
| OWASP ASVS 4.0 | https://owasp.org/www-project-application-security-verification-standard/ |
| OWASP Top 10 2021 | https://owasp.org/Top10/ |
| CWE | https://cwe.mitre.org/ |
| CVSS 3.1 | https://www.first.org/cvss/ |
| NIST SP 800-63B | Digital Identity Guidelines |

---

*This audit was conducted as an engineering assessment and does not constitute an official AFTA certification. Official compliance verification requires assessment by a recognized Iranian security evaluation body.*
