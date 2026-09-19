# Hastama — Security Assessment, Hardening and Final Verification

**System:** Hastama (سامانه هستما) — employee attendance, leave, overtime, hourly-pass and payroll-adjacent records; FastAPI + Jinja2 + vanilla JS + SQL Server; Araz/T7 device integration; offline LAN deployment behind Caddy with an internal TLS certificate.

**Repository:** `draminiiii/hastama_lab` — branch `arena/01a0b5ec-hastama-lab`, based on `master` @ `38ca85f`.
**Assessment window:** single continuous engagement, 2026-09-18 → 2026-09-19.
**Report date:** 2026-09-19.
**Assessor:** independent review performed inside the repository and a sandboxed runtime; every claim below is tied to a file, a command or a test result.

> **Independence statement.** Nothing in this report is taken from the previous
> audit documents in the repository (`HASTAMA_AFTA_SECURITY_AUDIT*.md`). Their
> claims (score progression 32 → 62 → 78, "CONDITIONALLY READY") were re-derived
> from source. Where this assessment disagrees, the measured result is stated.

**Verdict (see §41): `CONDITIONALLY READY`.**

---

## 1. Executive Summary

Hastama is an internal HR system that had already received one round of security
work before this engagement. That round fixed real problems, but it left three
things behind: controls that were **claimed** but not verified, a **regression**
in the attendance state machine, and **new breakage** introduced by the CSRF
rollout. This assessment re-derived every control from the code, found and fixed
the gaps, and measured the result.

**What was found (all confirmed by execution, not by reading reports):**

* a committed database export containing **plaintext user passwords** plus the
  Araz Access payroll/attendance databases — **Critical/High data exposure**
  (F-01, F-02);
* two employee report endpoints that answered **anonymous LAN callers** and could
  return **every employee's** overtime and hourly-pass records (F-03, High);
* **stored XSS** in report/admin renderers that interpolated database values into
  `innerHTML` without escaping (F-04, Medium–High);
* a **broken attendance state machine** for night shifts (check-in succeeded
  twice, check-out always failed) — a data-integrity defect that the existing
  tests already flagged and that was never fixed (F-05);
* **username enumeration** through the recovery-code rejection messages (F-06);
* an **internal-error helper that raised `NameError`**, converting ordinary
  failures into 500s (F-07);
* an **unmaintained PDF dependency** with a High advisory and no upstream fix
  (F-08), whose call site was reachable with attacker-influenced HTML;
* a **CSRF rollout that broke legitimate machine and pre-authentication callers**
  (the Araz bridge agent and the captcha refresh) — a self-inflicted
  availability/integration defect (F-10);
* **no backup or restore capability anywhere** in the system (F-14, Critical for
  an attendance/payroll system).

**What was done:** every one of the runtime findings above was remediated in
code, with regression tests, without changing the architecture, the UI or the
business workflows. The repository-level findings were contained (the export was
quarantined outside the repository, ignore rules added) and are reported as
**formally outstanding** items because they also live in git history.

**Where the system stands:** the runtime attack surface on the LAN is now
materially better than at the start of the engagement — 137 security tests pass,
the anonymous probe of all 190 routes shows no data endpoint answering without a
session, and the hardening is documented control-by-control. The system is
**not** ready for a formal external assessment yet, because four
High/Critical-class items are outside the code's reach: historic data in git,
absent backups, the absence of MFA for administrators, and the unpatched PDF
dependency.

---

## 2. Scope, Method and Limitations

**In scope:** the whole repository at the stated commit — application code
(`app/`, 11,395 lines), templates (15 files), front-end JavaScript
(`app/static/js`, 25 files), configuration (`Caddyfile`, `docker-compose.yml`,
`Dockerfile`, `.env.example`), tooling (`tools/`), tests, and the deployment
artifacts for the offline LAN.

**Domains covered (A–N):** authentication, authorization, session management,
password recovery, CSRF, XSS/CSP, API/WebSocket/SSE, database access, file
handling, business logic, Araz/T7 integration, logging/audit/monitoring,
infrastructure/deployment, dependency and supply chain.

**Method:**

1. independent baseline re-derivation from source (no trust in prior reports);
2. dynamic verification with a real ASGI application under `TestClient`
   (anonymous route probe, CSRF flow, login flow, recovery flow);
3. static analysis: `bandit` (0 High, 33 Medium), `ruff --select S`, plus a
   purpose-written AST scan for every dynamic SQL call (52 sites, all read);
4. dependency analysis with `pip-audit`;
5. targeted remediation with a test per control, then full regression;
6. re-verification of each fix by executing it (never "fixed by inspection").

**Limitations — stated plainly:**

* No live SQL Server, no Araz device, no Caddy and no browser were available in
  the sandbox; the ODBC layer is faked (`tests/conftest.py`). Everything that
  depends on the live environment is listed in §24 and in
  `docs/security/MANUAL_VERIFICATION_CHECKLIST.md`.
* `wkhtmltopdf` is not installed, so PDF generation was verified with a stubbed
  `pdfkit` (the hardening is about the *arguments* passed, which is deterministic).
* Bandit/Ruff findings are *candidates*; each Medium was triaged manually.
* No penetration test against a running instance was performed — this report
  does not claim one.
* Iranian regulatory primary sources were not reachable; §35/§36 mark those
  items `NOT VERIFIED`.

---

## 3. Architecture Overview

```
                 LAN / workstation browser (offline)
                              │  HTTPS, internal CA (hastama.local)
                              ▼
                        ┌───────────┐
                        │  Caddy    │  TLS termination, 12 MB body cap,
                        │ (proxy)   │  header stripping, JSON access log,
                        └─────┬─────┘  300 s timeouts
                              │  127.0.0.1:8000 (loopback only)
                              ▼
   ┌───────────────────────────────────────────────────────────────┐
   │ FastAPI application (`app/main.py`, 1 monolithic module +     │
   │ 9 route modules)                                              │
   │                                                               │
   │  middleware chain, outermost → innermost:                     │
   │   SecurityHeaders  ⊃  SessionRegistry  ⊃  CSRF  ⊃             │
   │   SessionMiddleware  ⊃  RequestConnection                     │
   │                                                               │
   │  routers: auth · notifications · ticketing · health ·         │
   │           call_system (+ WebSocket) · araz_api ·              │
   │           master_admin · registration · predictor (ML)        │
   └───────┬───────────────┬──────────────┬────────────────────────┘
           │               │              │
           ▼               ▼              ▼
   SQL Server        Access MDB       Araz T7 device
   (localhost\SQLEXPRESS,  (Arazdb.mdb)  (TCP protocol,
    userDB: attendance,    + bridge agent  tools/bridge_agent.py
    leave, payroll,        on the device    POST /api/araz/bridge-sync
    tickets, audit)        PC)
```

Front-end: 15 Jinja2 templates, 25 JS files, no build step, no external CDN
(offline requirement). Data model is query-driven (`pyodbc` + parameterised SQL),
no ORM.

---

## 4. Threat Model

The full asset list, trust boundaries and the 20-entry threat table live in
`docs/security/THREAT_MODEL.md`. Summary of the most relevant threats for this
deployment:

| # | Threat | Primary control now in place |
|---|---|---|
| T1 | Credential stuffing on `/login_user` | throttling (15/10 min per IP), captcha, generic errors |
| T2 | Username enumeration | identical answers + decoy request id; recovery reasons gated behind code possession |
| T3 | Session hijack / fixation | signed cookie + server-side session registry, session cleared at login, revocation on reset |
| T4 | CSRF | session-bound double-submit token; Origin checks on the exempt (kiosk / pre-auth) paths |
| T5 | Stored XSS | escaping in every renderer + markup rejection on write |
| T6 | SQL injection | parameterised queries everywhere; identifiers from literals/allow-list |
| T7 | IDOR | ownership in SQL predicates; report endpoints admin-only |
| T8 | Privilege escalation | roles read only from the signed session |
| T13 | Malicious document → child process abuse | PDF generation re-written around `from_file` with JS/local-file access disabled |
| T16 | DoS | upload/batch caps, login throttling, proxy body cap |
| T18 | Data loss | **not covered — no backup exists (RR-01)** |

---

## 5. Asset and Data Classification

| Class | Examples | Where | Handling rule applied |
|---|---|---|---|
| C1 Credentials | `user_table.password_hash`, recovery codes | SQL Server, `password_reset_requests` | never exported, never logged, bcrypt + HMAC digest only |
| C2 Payroll | payroll tables, `TPrsPeyment` | SQL Server, Access MDB | admin/master-admin only |
| C3 Attendance & HR records | `hozoor`, `mrkhc_table`, `ezafe_table`, `totalpass_table`, ticket content | SQL Server | session + role checks; reports admin-only |
| C4 Audit & security events | `audit_logs`, `security_events`, `admin_actions`, `system_errors`, `user_sessions` | SQL Server | master-admin only; integrity matters more than secrecy |
| C5 Configuration secrets | session key, HMAC key, bridge secret, Access password | `.env`, environment | fail-closed when absent; documented in `.env.example` |
| C6 Operational metadata | queue numbers, call status, training content | SQL Server, static files | public on the LAN by design (kiosk) |
| C7 Repository artifacts | MDBs, vendor installers, historic export | Git history | **finding F-01/F-02 — still exposed** |

---

## 6. Authentication

*Credential verification* is performed by `app/core/password_utils.py`:

1. bcrypt (`$2a$/$2b$/$2y$`, cost 12) in `password_hash` → `bcrypt.checkpw`;
2. bcrypt stored in the legacy `password` column (pre-migration installs);
3. legacy SHA-512 digest (raw or hex) → `hmac.compare_digest`;
4. legacy plaintext → `hmac.compare_digest`.

New writes always store bcrypt and clear the legacy column (`insert_user_with_optional_hash`,
`/update_user`, `/reset_password`, registration approval). `tools/migrate_passwords.py`
(idempotent, `--dry-run`) migrates remaining rows — **it must be run on the live
database (§39, D-3)**.

*Policy* (`validate_password_policy`): ≥ 8 characters, at least one upper, lower,
digit and symbol, ≤ 128 characters.

*Abuse resistance*: captcha on login/reset; sliding-window limiter (15 failures
per 10 minutes per IP, plus a per-account counter) implemented in
`app/core/rate_limit.py`; the client IP is taken from the **last valid**
`X-Forwarded-For` entry *and only from a trusted proxy peer* (`app/core/net.py`),
so a spoofed header cannot bypass the limit (test:
`test_spoofed_forwarded_header_cannot_bypass_the_ip_limit`).

*Account state*: `is_active` is honoured — a disabled account receives the same
generic failure as a wrong password.

Tests: `TestLoginFlow` (7 tests), `TestPasswordStorage` (6), `TestPasswordRecovery` (9).

**Residual:** no MFA for administrators (RR-03); legacy rows (RR-14).

---

## 7. Authorization Matrix

The complete per-route table is `docs/security/ENDPOINT_AUTHORIZATION_MATRIX.md`
(190 routes, generated from the AST with full function bodies, cross-checked by
an anonymous HTTP probe of every parameterless route).

| Family | Count | Notes |
|---|---|---|
| master-admin | 30 | `_master_admin` + username allow-list |
| admin | 60 | `_require_admin` / `get_is_admin_from_session` |
| admin (indirect) | 2 | guard inside the shared admin renderer |
| authenticated | 49 | session required (`_require_auth`, `_actor`, `_ticket_actor`, `_attendance_actor`) |
| authenticated (indirect) | 5 | notification/ticket helpers enforce ownership in SQL |
| bridge-secret | 1 | `/api/araz/bridge-sync` — shared secret, constant-time compare |
| public-by-design | 35 | login, captcha, registration, training, docs-shells |
| public-by-design (kiosk) | 2 | call display / waiting queue: LAN + Origin check |
| static shell | 5 | HTML only, no data |

Two endpoints changed class in this engagement: `/get_hourly_pass_report` and
`/get_overtime_report` were anonymous and accepted the magic value
`username = "all_users"` (F-03); they now require an administrator.

---

## 8. Session Management

* Signed cookie (`SESSION_SECRET_KEY` / `SECRET_KEY`), `HttpOnly`, `SameSite=Lax`,
  `Secure` only when the request genuinely arrived over HTTPS (real scheme or
  `X-Forwarded-Proto` from a **trusted** proxy).
* Every login mints `sid` (`secrets.token_hex(32)`) and a row in `user_sessions`;
  `_SessionRegistryMiddleware` rejects a validly signed cookie whose `sid` is not
  registered (fail-closed). This is what makes logout and password reset real
  revocation rather than client-side hints.
* Session data is cleared before the new identity is written (fixation defence).
* Lifetime: `SESSION_MAX_AGE_SECONDS` (default 28800 = 8 h).
* Rejections are audited (`SECURITY / session_rejected`).

Baseline defect found and fixed in Phase 2: the cookie parser used a hard-coded
salt that no longer matched the installed Starlette signer, which made **both**
session middlewares fail open (F-18). `app/core/session_cookie.py` now tries the
installed signer first and falls back to the legacy salt, and
`test_matches_installed_session_middleware` guards the behaviour against future
library upgrades.

---

## 9. Password and Recovery Flow

Full data-flow diagram with line-level references: `docs/security/PASSWORD_FLOW_DATAFLOW.md`.
Key properties, all verified by test:

1. no plaintext is written by any current path;
2. recovery codes exist only as HMAC-SHA256 digests, expire, and are limited by
   attempt count;
3. recovery fails closed when `HASTAMA_HMAC_SECRET` is unset;
4. `/forgot_password` answers identically for known and unknown accounts and
   returns a **decoy** request id for unknown ones;
5. `/reset_password` reveals *nothing* about the request id until the submitted
   code matches — the "expired" / "already processed" explanations were moved
   behind the code check (F-06);
6. a completed reset revokes every session of that user;
7. all comparisons are constant-time; all failures are audited.

---

## 10. CSRF

`_CSRFMiddleware` implements a session-bound double-submit token: the readable
`csrf_token` cookie, the `X-CSRF-Token` header and the token inside the signed
session must all match (constant-time comparison). A missing/never-minted token
is a hard failure; the middleware also mints the cookie for anonymous visitors so
the login POST can bootstrap.

Exemptions exist only where a token is structurally impossible or the caller is
not a browser, and each is commented in the code:

| Prefix | Why | Compensating control |
|---|---|---|
| `/api/calls`, `/api/call-display`, `/api/display-queue`, `/api/waiting-queue`, `/api/slides`, `/api/ws/` | kiosk/TV has no session | Origin must be same-site; `null`/cross-site rejected |
| `/login_user`, `/forgot_password`, `/reset_password`, `/verify_recovery_code`, `/registration/`, `/captcha/` | pre-authentication | Origin check, captcha, rate limits |
| `/public/` | public support form | Origin check |
| `/api/araz/` | machine-to-machine bridge agent | shared secret, constant-time compare, fail-closed |

**F-10 (self-inflicted regression, fixed):** the first rollout exempted only the
kiosk paths, which silently blocked the Araz bridge agent and the captcha
refresh button. Both are now exempt *and* tested
(`TestCsrfExemptionIntegration`, 10 tests), and the exemption list is asserted to
stay narrow.

---

## 11. XSS and CSP

*Content Security Policy* (in `_SecurityHeadersMiddleware`, mirrored in Caddy):
`default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'
'unsafe-inline'; img-src 'self' data:; connect-src 'self' ws: wss:; object-src
'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'`.

`'unsafe-inline'` is **not** removed blindly: the templates contain ~100 inline
`onclick=` handlers and several inline `<script>` blocks, so dropping it today
would break every page. The realistic migration is (a) move handlers into the
already-existing page scripts, (b) keep a nonce for the few inline blocks that
must stay, then (c) remove `'unsafe-inline'`. This is recorded as RR-04 with a
time-box, not as "done".

*Stored XSS (F-04, fixed):* report renderers (`final-report-script.js`,
`leave-report-script.js`, `hourlypass-report-script.js`,
`overtime-report-script.js`) built table rows from database values with
`innerHTML` and template literals. Values now pass through `esc()`
(`app/static/js/dom-escape.js`, loaded by the four report templates, verified in
the browser-free JS harness: `<img src=x onerror=…>` →
`&lt;img src&#61;x onerror&#61;…&gt;`). The admin and master-admin renderers were
already escaped in the previous round; both were re-verified by the static
assertions in `TestTemplateOutputEscaping`.

*Defence in depth (new):* `/submit_leave` (substitute) and `/submit_overtime`
(description and times) reject `<`, `>` and NUL at write time
(`app/core/validation.py::reject_markup`), so a future screen that forgets to
escape still cannot be attacked with those fields.

No template uses the Jinja `|safe` filter (asserted by test).

---

## 12. API, WebSocket and SSE

* 190 routes; anonymous probing of every parameterless route is reproduced in
  the authorization matrix (column "Anon probe").
* Interactive docs (`/docs`, `/redoc`, `/openapi.json`) are now **disabled by
  default** (F-09) and only appear with `HASTAMA_ENABLE_DOCS=true` or `DEBUG=true`.
* API errors are generic (`_safe_error_message`); no stack traces, SQL text or
  paths are returned.
* `/api/araz/bridge-sync` validates and caps its batch, requires the shared
  secret and never echoes it.
* WebSocket `/api/ws/call-display` (TV) enforces same-site Origin (cross-site and
  `null` are closed with code 1008), a message-size limit and a connection cap;
  it carries no personal data beyond the reception queue.
* No SSE endpoints exist (the call-display uses the WebSocket plus polling).

---

## 13. Database

See `docs/security/SQL_INJECTION_REVIEW.md` for the full evidence. Result:

* **No SQL injection confirmed in any application path.** 52 dynamic statements
  were read individually; every value is a bound parameter, and the only
  interpolated identifiers come from fixed literals or the 3-entry allow-list
  `_NOTIFY_STATUS_TABLES` (F-11, added in this engagement).
* Credential columns are never selected for API responses (`SELECT` lists are
  explicit; asserted by `test_user_list_never_selects_credential_columns`).
* Per-request connection/cursor isolation is enforced through ContextVars
  (`app/core/db_context.py`) — the previous module-level connection sharing is
  gone (`TestDatabaseIsolation`, 3 tests).
* `app/tempexport.py` (unimported migration script) still hard-codes a DSN and
  interpolates a catalogue table name into SQL and into `SELECT *` — F-12,
  informational, **must not be shipped**.
* Least privilege for the SQL login is a **manual DBA task** (§39, D-1); today
  the app expects rights to run its own `ALTER TABLE` column migrations.

---

## 14. File Handling

| Surface | Control |
|---|---|
| Profile image (`/upload-profile-image`) | server-generated name from the sanitised username (`[A-Za-z0-9_.-]`, 64-char cap), `os.path.abspath` containment check, image MIME/extension check, size cap |
| Ticket attachments | random storage name, extension allow-list, size cap, private root (`TICKETING_PRIVATE_DIR`) never served statically; download goes through an authorization check |
| Call slides | admin-only upload, image-only, size cap, stored under the static root with generated names |
| PDF report (`/download_pdf`) | renders a repository template into a private temp file, converts with `pdfkit.from_file`, disables JavaScript and local file access, deletes the temp file in `finally` |

Traversal regression tests exist for both the attachment helper and the profile
path. Upload **content** is not scanned (no AV product in the offline
environment) — RR-05.

---

## 15. Araz / T7 Integration

* Device protocol implementation: `app/services/araz_connector.py` (reverse
  engineered; read/write of attendance records), bridge agent
  `tools/bridge_agent.py` (Windows service on the device PC).
* The bridge authenticates with `ARAZ_BRIDGE_SECRET` (header), compared with
  `hmac.compare_digest`; if the secret is unset the endpoint answers
  **503 and does nothing** (`araz_api.py:37,430,444`) — verified by
  `TestBridgeSync` (4 tests).
* Batch size is capped; records are validated before they reach SQL.
* The Access-database fallback reads `Arazdb.mdb` with a DSN built from
  configuration; the password defaults to empty (no DB password in the shipped
  file) and is never logged.
* `tools/bridge_config.json` ships with an **empty** secret (F-13): safe
  fail-closed behaviour, but the deployment must set it (§38, 1.4).
* Committed vendor binaries and Access databases: see F-02/F-17 — the biggest
  remaining data-exposure item, and a decision the repository owner must make.

---

## 16. Business Logic

* **Attendance state machine (F-05, fixed):** check-in now looks for *any* open
  check-in for the user (night shift may have started the previous day); a
  second check-in while one is open is refused with 409, and check-out closes
  the most recent open record instead of insisting on today's row. This fixed
  four failing tests and a real double-counting risk in payroll-relevant data.
* **Ticket transitions:** status changes are validated against
  `ALLOWED_TRANSITIONS`; a closed ticket can only be reopened, and terminal
  states are recorded with timestamps. (One *test* asserts a stricter matrix than
  the code has ever had — reported as a pre-existing test expectation mismatch,
  §37, not silently changed.)
* **Managerial privilege:** all approval flows (`/change_*_status`, leave,
  overtime, hourly pass, payroll) require an administrator; approvals re-read the
  request row and act on the database, never on client-supplied identity.
* **Registration approvals** read back the created user with explicit columns
  (no credential leakage into the response).
* Report endpoints that accept `username = "all_users"` are admin-only.

---

## 17. Logging, Audit and Monitoring

Audit tables written by the application: `audit_logs` (generic events),
`security_events` (CSRF, session rejection, throttling), `admin_actions`
(master-admin operations), `system_errors`, `user_sessions` (session lifecycle),
`password_reset_requests` (recovery lifecycle).

Events written today include: login success/failure (with severity escalation
when the IP threshold is crossed), logout, session rejection, CSRF failure,
password-reset request/approval/completion, and master-admin actions.

Never logged: submitted passwords, recovery codes, session cookie values, the
bridge secret, DB connection strings.

**Gaps:** logs live only in the database (RR-09), there is no alerting on a
single offline host, and no log-shipping (the offline constraint allows a local
append-only file as the next step, §40).

---

## 18. Infrastructure and HTTPS

* `Caddyfile`: `hastama.local` with `tls internal`, TLS 1.2+ defaults, 12 MB
  request-body cap, `-Server` / `-X-Powered-By` removed at the edge, HSTS,
  `X-Content-Type-Options`, JSON access log with rotation
  (`/var/log/caddy/hastama-access.log`, 20 MiB × 10), reverse proxy to
  `127.0.0.1:8000` with 300 s timeouts.
* Application binds loopback only in production; the deployment notes in
  `docker-compose.yml` document `--proxy-headers`, `--forwarded-allow-ips` and
  `TRUSTED_PROXY_IPS` so that the trust model matches the actual topology.
* `.env.example` documents every required secret with a generation command;
  `.env` is git-ignored.
* Reverse-proxy rate limiting is **commented out**: stock Caddy has no
  `rate_limit` directive (it requires the `caddy-ratelimit` plugin, which cannot
  be fetched in an offline environment). Application-level limits are primary
  (RR-08); do not document proxy rate limiting as active.

---

## 19. Dependencies and Supply Chain

* Dependencies are pinned in `pyproject.toml` / `uv.lock`; installation is
  offline-friendly (no CDN assets, no remote fonts, no outbound calls at runtime).
* `pip-audit` (run in this engagement) reports **one** advisory:
  **PYSEC-2026-2860 / CVE-2025-26240 / GHSA-9g3x-6x24-vf9f — pdfkit ≤ 1.0.0**,
  CVSS v3.1 8.4 High: `from_string` parses `<meta name="pdfkit-…">` tags and
  passes them to wkhtmltopdf (`--post-file` → local file disclosure, `--script`
  → JavaScript/SSRF; option-override bypass). **No patched release exists.**
  Mitigation applied at the call site (F-08): `from_file` + disabled JavaScript
  and local file access + inlined stylesheet + temp file. Regression tests
  forbid `from_string` from reappearing.
* `bandit -r app`: 0 High, 33 Medium (all B608 dynamic-SQL candidates — each
  reviewed and explained in the SQL review), 86 Low (53 of them `try/except/pass`
  in cleanup code, 23 `random` in captcha *visual noise* — the captcha code
  itself uses `secrets.choice`).
* `ruff --select S`: S110 ×53, S608 ×32, S311 ×23, S105 ×3 (all triaged above).
* No SBOM, no signature verification, no CI gate (RR-16).

---

## 20. Backup, Recovery and Continuity

**There is no backup or restore capability in the repository or the deployment
artifacts.** For a system that is the record of attendance and pay, this is the
single most consequential gap in the whole assessment (F-14, RR-01). Nothing in
this report compensates for it; it is an operational task with exact verification
steps in §39 (D-3, D-8) and §38 (3.3, 3.4). Until it is done, no security
verdict above `CONDITIONALLY READY` is defensible.

---

## 21. Confirmed Findings

CVSS v4.0 vectors are given first (v3.1 fallback in the same cell), both
computed with the `cvss` library rather than estimated.

### F-01 — Plaintext password export committed to the repository — **CRITICAL** — `PARTIALLY REMEDIATED`
* **CWE-256 / CWE-540** · **CVSS v4.0 9.3 Critical** (`AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:N`); v3.1 9.1 (`AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N`)
* **Where:** `exported_data.sql` (repo root, tracked in `git`), content generated by `app/tempexport.py`.
* **Evidence:** the file contained `CREATE TABLE` statements for 11 tables and `INSERT` rows for `user_table` **including the `password` column in plaintext**; SHA-256 `a3ea361270d06b2520e47107edda8d6af715e7762d380e3cbb6f5e0153f3ab3c`, recorded in `/home/user/evidence/EXPORTED_DATA_SQL_HASH.txt` together with the line count and the HEAD commit.
* **Exploitability:** anyone with repository access (or a clone, or a backup of the repo) reads live credentials. No exploit code needed.
* **Remediation performed:** file moved out of the working tree to `/home/user/evidence/exported_data.sql.quarantined`; `.gitignore` extended with `exported_data.sql`, `*.dump`, `*.sql.gz`.
* **Still outstanding:** the blob remains in **git history** (all previous commits). Purging requires a history rewrite or, minimally, an explicit decision to keep the repository internal.
* **Required follow-up (non-negotiable):** every account whose password appears in that export must be treated as compromised → force a password reset for those users (the app-side tooling already revokes sessions on reset) and rotate any credential that was reused elsewhere.
* **Verification method:** `sha256sum` + `git log --all -- exported_data.sql`; after remediation: `git ls-files | grep exported_data` → empty.

### F-02 — Araz Access databases and vendor binaries committed — **HIGH** — `CONFIRMED / OPEN`
* **CWE-200 / CWE-540** · **CVSS v4.0 8.7 High** (`AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:N/VA:N`); v3.1 7.5
* **Where:** `database/Arazdb.mdb` (47 MB), `arazin/Arazdb.mdb` (46 MB), `arazin/UPDATEBACKUP/*.mdb` (≈120 MB, dated snapshots), `arazin/*.exe` (Araz, Setuparaz, patch tools), plus a root-level screenshot.
* **Evidence:** `git ls-files` lists them; UTF-16 string extraction from `database/Arazdb.mdb` yields the attendance/payroll schema — `TPrsInOut` (16 occurrences), `TPrsSalaryAdditions`, `TPrsPeyment`, `TPrsHistory`, `MSysObjects`.
* **Impact:** attendance and payroll data (and dated history) is copied into every clone of the repository, outside any access control the application enforces.
* **Why it is not simply deleted:** `Araz.exe` expects its own database file; field installations may depend on these paths.
* **Recommendation:** keep the vendor installers in an internal file share, remove the `.mdb` copies from the repository, and document the retention decision. Content of the MDBs (whether populated with personal data) is **NOT VERIFIABLE** here (§24).

### F-03 — Employee report endpoints answered anonymous callers — **HIGH** — `REMEDIATED`
* **CWE-306 / CWE-284** · **CVSS v4.0 8.7 High**; v3.1 7.5
* **Where/route:** `POST /get_hourly_pass_report` and `POST /get_overtime_report` (`app/main.py`, previously lines 3192 and 3430).
* **Evidence:** anonymous probe — `POST /get_hourly_pass_report {"username":"all_users", …}` previously reached the database layer; after the fix the same request returns 401 (`TestEmployeeReportAuthorization`), and the endpoints call `_require_admin` before parsing the body.
* **Impact before the fix:** any host on the LAN could read every employee's hourly-pass and overtime records without an account.
* **Fix:** admin gate + tests for the anonymous and non-admin cases.

### F-04 — Stored XSS in report renderers — **MEDIUM** — `REMEDIATED`
* **CWE-79** · **CVSS v4.0 5.1 Medium**; v3.1 5.4
* **Where:** `final-report-script.js` (overtime description, substitute name, weekday), `leave-report-script.js`, `hourlypass-report-script.js`, `overtime-report-script.js` — values from the database interpolated into `innerHTML` template literals without escaping.
* **Exploit path:** employee submits `<img src=x onerror=…>` as the overtime description → the administrator opens the report page → script executes with the admin's session (the CSP still allows inline script).
* **Fix:** `esc()` helper (`app/static/js/dom-escape.js`) applied at every render site in the four scripts; write paths reject markup; tests assert both.
* **Verification:** static assertions (`TestStoredXssRendering`) + a Node harness proving the escaping output.

### F-05 — Attendance state machine broken for night shifts — **LOW/MEDIUM (integrity)** — `REMEDIATED`
* **CWE-863 / CWE-662** · **CVSS v4.0 2.3 Low** (v3.1 3.1) for the exploitable aspect; the business impact (wrong payroll input) is High.
* **Where:** `sabt_hozoor_checkin` / `sabt_hozoor_checkout` (`app/main.py`).
* **Evidence:** four tests failed in the *baseline* checkout: a check-in whose open record was created yesterday was accepted again today (double shift), and check-out always returned 409 because it only looked at today's row.
* **Fix:** look up the most recent open record across days (with `UPDLOCK, HOLDLOCK` retained), refuse a second check-in while one is open, close the open record on check-out. All 19 attendance tests pass.

### F-06 — Username enumeration through recovery responses — **MEDIUM** — `REMEDIATED`
* **CWE-204** · **CVSS v4.0 6.9 Medium**; v3.1 5.3
* **Where:** `app/services/audit.py::verify_recovery_code`.
* **Evidence:** a decoy request id (issued for accounts that do not exist) produced "code invalid", while a real but not-yet-approved request produced "already processed" and an expired one produced "code expired" — three distinguishable answers for one unauthenticated request.
* **Fix:** the reason-specific answers now require a **matching code** first; every rejection before that point returns one identical message (and the attempt counter still increments).
* **Verification:** `test_pending_request_is_indistinguishable_without_the_code`, `test_expired_answer_requires_possession_of_the_code`.

### F-07 — Internal-error helper raised `NameError` — **MEDIUM** — `REMEDIATED`
* **CWE-755 / CWE-209** · **CVSS v4.0 6.9 Medium**; v3.1 6.5
* **Where:** `app/main.py` (`_safe_error_message`, used by many handlers).
* **Evidence:** the function called `logger.error(...)` while no `logger` name existed in the module — a direct call raised `NameError`; every "handled" failure therefore surfaced as a 500.
* **Fix:** the module logger is defined; the helper returns the generic Persian message and logs the exception type only. Test: `TestInternalErrorHandling`.

### F-08 — Unmaintained PDF dependency with a High advisory — **HIGH** — `MITIGATED, NOT PATCHABLE`
* **CWE-1104 / CWE-88** · **CVSS v4.0 8.6 High** (v3.1 8.4, matches the advisory)
* **Where:** `app/main.py::download_pdf` used `pdfkit.from_string(html, False, css=…)`.
* **Impact:** HTML meta tags (`pdfkit-post-file`, `pdfkit-script`, …) are interpreted as wkhtmltopdf arguments → local file disclosure and JavaScript/SSRF on the server hosting the report.
* **Fix:** render to a private temp file and call `pdfkit.from_file`, inline the stylesheet, pass `disable-local-file-access`, `disable-javascript`; delete the temp file in `finally`; return 503 when the report template is absent (it is absent in this repository — the endpoint previously raised).
* **Verification:** `TestPdfGenerationHardening` (4 tests) + an end-to-end run with a stubbed `pdfkit` observing the exact arguments.
* **Residual:** the library itself is unpatched upstream; a future edit could reintroduce `from_string` (guarded by test, listed as RR-06).

### F-09 — Interactive API documentation exposed — **MEDIUM** — `REMEDIATED`
* **CWE-200** · **CVSS v4.0 6.9 Medium**; v3.1 5.3
* **Evidence:** anonymous `GET /docs` → 200 before the change; now 404 unless `HASTAMA_ENABLE_DOCS=true`.
* **Impact:** a complete route/schema map for an attacker on the LAN.

### F-10 — CSRF rollout broke legitimate callers — **MEDIUM** — `REMEDIATED`
* **CWE-352 (control overreach)** · **CVSS v4.0 6.9 Medium** (availability); v3.1 5.3
* **Evidence:** with the first exemption list, `POST /api/araz/bridge-sync` (no Origin, no token) and `POST /captcha/refresh` (the login page button) were answered with 403 "CSRF token mismatch" — the device bridge would have stopped syncing after deployment.
* **Fix:** exemptions for `/api/araz/`, `/captcha/`, `/public/` with Origin checking on all of them; tests assert that the bridge is not blocked, that cross-site calls still are, and that the exemption list stays narrow.

### F-11 — Table name interpolated into SQL — **LOW** — `REMEDIATED`
* **CWE-89 (latent)** · **CVSS v4.0 2.1 Low**; v3.1 3.3
* **Where:** `app/main.py::_notify_requester_status(table, …)`.
* **Evidence:** all five call sites passed literals, but the helper accepted any string and interpolated it into the query text. Now restricted to `{mrkhc_table, totalpass_table, ezafe_table}`; an unexpected value is logged and refused (test included).

### F-12 — Migration script with hard-coded DSN, f-string SQL and `SELECT *` — **LOW** — `CONFIRMED (informational)`
* **CWE-798 / CWE-89** · **CVSS v4.0 4.6 Medium** (local/authenticated context); v3.1 3.4
* **Where:** `app/tempexport.py` (not imported by the application).
* **Evidence:** `pyodbc.connect(... SERVER=localhost\SQLEXPRESS; DATABASE=userDB; Trusted_Connection=yes)` hard-coded; `f"SELECT * FROM {table_name}"`. **This is the script that produced F-01.**
* **Recommendation:** delete it from the deployment; never run it against production again.

### F-13 — Bridge configuration ships with an empty secret — **HIGH (deployment)** — `CONFIRMED / OPEN`
* **CWE-1188** · **CVSS v4.0 9.1 Critical if deployed unchanged**; the runtime fails closed instead (503), so the practical risk is a device-integration outage, not a breach. Recorded as High to force an explicit deployment check.
* **Evidence:** `tools/bridge_config.json` → `"hastama_secret": ""`; `araz_api.py` refuses to operate without the environment secret.
* **Fix:** set the secret on both ends (§38, 1.4).

### F-14 — No backup or restore capability — **CRITICAL (operational)** — `CONFIRMED / OPEN`
* **CWE-693** · **CVSS v4.0 8.8 High**; v3.1 9.1
* **Evidence:** no backup job, script, documented procedure or verified restore anywhere in the repository; the SQL Server and the Access database live on one host.
* **Impact:** loss of attendance/payroll records; no recovery path; also removes the ability to recover from ransomware.
* **Required:** implement and **test** a restore (§38, 3.3/3.4, §39 D-8). This is a condition of the verdict.

### F-15 — Report endpoint referenced a template that does not exist — **LOW** — `PARTIALLY REMEDIATED`
* **CWE-1059** · **CVSS v4.0 5.3 Medium**; v3.1 4.3
* **Evidence:** `templates.get_template('finalReportUser.html')` and `static/finalReportUserPrint.css` are referenced only by `app/main.py`; neither exists anywhere in the repository (nor in history). The endpoint therefore raised on every call.
* **Fix:** the failure is now explicit (503 + log message) instead of an unhandled exception; if the template exists only in the deployed installation the feature continues to work there, with the F-08 hardening applied.

### F-16 — Legacy credential formats may still be present — **MEDIUM** — `PARTIALLY CONFIRMED`
* **CWE-256** · **CVSS v4.0 5.9 Medium**; v3.1 5.1
* **Evidence:** `verify_password` explicitly supports SHA-512 and plaintext rows, and `password_utils` documents the migration debt; the *live* counts cannot be read from here (§24).
* **Action:** run `tools/migrate_passwords.py --dry-run` (D-3) and then the real migration in a maintenance window.

### F-17 — Client-supplied roles / mass assignment — **NOT CONFIRMED** — `FALSE POSITIVE (verified)`
Roles are read only from the signed session (`get_is_admin_from_session`,
`_master_admin`, `_actor`), and tests assert that a body flag cannot elevate
(`test_role_elevation_via_client_supplied_flag_is_impossible`).

### F-18 — Session validation failed open (salt mismatch) — **MEDIUM** — `REMEDIATED (Phase 2)`
* **CWE-287 / CWE-345** · **CVSS v4.0 7.6 High**; v3.1 6.8
* **Evidence:** the cookie parser used a hard-coded salt that no longer matched the installed Starlette signer, so both session middlewares silently accepted cookies they could not verify. `app/core/session_cookie.py` now tries the installed signer first and the regression test pins it to the *installed* library version.

### F-19 — WebSocket had no session/origin check — **MEDIUM** — `REMEDIATED (Phase 2)`
* **CWE-306 / CWE-1385** · **CVSS v4.0 6.9 Medium**; v3.1 6.5
* **Evidence:** the baseline test `TestWebSocketSecurity::test_websocket_requires_session` failed because the endpoint accepted any connection. It now enforces same-site Origin, size limits and a connection cap; the test passes.

---

## 22. Partially Confirmed / Partially Remediated

| ID | Item | What is confirmed | What remains |
|---|---|---|---|
| F-01 | Plaintext export | file contents and hash | **git history** still contains it; credential rotation not performed (outside the repo) |
| F-08 | pdfkit CVE | dependency version and call site | no upstream fix; mitigation is call-site only |
| F-15 | Missing PDF template | template absent from repo | whether field installs have it is unknown |
| F-16 | Legacy credentials | code paths accept them | live row counts need DBA access |
| F-13 | Empty bridge secret | shipped config | production value unknown |
| — | Session/CSRF rollouts | behaviour verified in the sandbox | not yet exercised through Caddy on the real host |

---

## 23. False Positives (analysed and dismissed)

| Candidate | Why it is a false positive |
|---|---|
| Bandit B105/B106/B107 "hardcoded password" on `SESSION_TOKEN_KEY = "sid"`, `CSRF_TOKEN_SESSION_KEY = "csrf_token"`, `def __init__(…, secret: str = "")`, `password_plain = ""` | These are session **key names**, an intentionally empty default (the secret is injected by the middleware), and an explicitly empty variable in the registration code whose comment states that the plain password is not available. No credential value is embedded. |
| Bandit B311 "non-cryptographic random" ×23 in `app/services/captcha.py` | All 23 hits are *visual noise* (dot/line positions, colours, jitter). The captcha **code** is generated with `secrets.choice` (line 82) and is not affected. |
| Bandit B608 ×33 (SQL built by string concatenation) | Every instance builds only fixed fragments or allow-listed identifiers; values are bound parameters. Individually reviewed in `SQL_INJECTION_REVIEW.md`. |
| Secret-pattern scan (22 hits) | All were variable assignments, DOM reads (`script.js`, `admin.js`) or `os.getenv`/`Read-Host` calls — no live credential in tracked text. |
| `tools/bridge_config.json` "secret present?" | The file ships **empty**, which is fail-closed; the risk is a missing value in deployment (F-13), not a leaked one. |
| Report "32 → 62 → 78 score" progression in the previous audits | Not reproducible as a measurement; this assessment uses findings and test results instead of a composite score (see §1 independence statement). |

---

## 24. Not Verifiable From This Environment

| # | Item | Why | How to verify (owner) |
|---|---|---|---|
| N-1 | Live SQL Server contents: legacy credential counts, bcrypt migration status | no DB access | D-3/D-4 (DBA) |
| N-2 | DB login privileges / whether the app can run DDL | no DB access | D-1 (DBA) |
| N-3 | Encryption at rest for DB/backups, TDE/BitLocker | host-level | D-2 (DBA/Infra) |
| N-4 | Whether the committed `.mdb` files contain populated personal data | no MDB reader offline | open in Access on a quarantined copy; then decide on removal (RR-02) |
| N-5 | Real Caddy/TLS chain, headers through the proxy, client trust-store state | no proxy in sandbox | §38 2.1/2.2, §39 I-2/I-4 |
| N-6 | Real `wkhtmltopdf` output for the final report | binary not installed | §39 B-1 |
| N-7 | Araz T7 device protocol behaviour against real hardware | device not reachable | vendor/field test |
| N-8 | Browser rendering of the escaped report tables | no browser | §39 B-1/B-3 |
| N-9 | AFTA requirement documents and applicability | primary sources unreachable | §36, authorized Iranian assessor |
| N-10 | Whether the personal-data-protection bill has become law | not verifiable from here | check the Official Gazette / legal counsel |
| N-11 | Backup/restore reality | nothing exists in the repo | §38 3.3/3.4 |
| N-12 | Alerting/monitoring practice on the host | operational | §39 I-* |

---

## 25. Residual Risk Register

`docs/security/RESIDUAL_RISK_REGISTER.md` — 18 entries with severity, owner and
whether a formal acceptance decision is required. The six that block a stronger
verdict:

| ID | Risk | Severity | Acceptance |
|---|---|---|---|
| RR-01 | No backup/restore (F-14) | High | required |
| RR-02 | Historic personal data in git (F-01/F-02/RR-17) | High | required |
| RR-03 | No MFA for administrative accounts | High | required |
| RR-04 | CSP `'unsafe-inline'` retained | Medium | required (time-boxed migration) |
| RR-14 | Legacy credential formats may persist (F-16) | Medium | required |
| RR-18 | Iranian regulatory status unresolved | Medium | required |

---

## 26. CWE Mapping

| CWE | Title | Findings |
|---|---|---|
| CWE-79 | Improper neutralisation of input during web page generation | F-04 |
| CWE-89 | SQL injection | F-11 (latent), F-12 (script) — none confirmed in app code |
| CWE-200 | Exposure of sensitive information | F-02, F-09 |
| CWE-204 | Observable response discrepancy | F-06 |
| CWE-209 | Generation of error message containing sensitive information | F-07 |
| CWE-256 | Plaintext storage of a password | F-01, F-16 |
| CWE-287 / CWE-345 | Improper authentication / insufficient verification | F-18 |
| CWE-306 | Missing authentication for a critical function | F-03, F-19 |
| CWE-352 | Cross-site request forgery | CSRF control family; F-10 (overreach) |
| CWE-540 | Inclusion of sensitive information in source code | F-01, F-02 |
| CWE-662 / CWE-863 | Improper synchronisation / incorrect authorization | F-05 |
| CWE-693 | Protection mechanism failure | F-14 |
| CWE-798 | Use of hard-coded credentials | F-12 |
| CWE-1104 / CWE-1395 | Use of unmaintained / vulnerable components | F-08 |
| CWE-1188 | Insecure default | F-13 |
| CWE-1385 | Missing origin validation in WebSockets | F-19 |

## 27. CVSS Summary

All scores computed with the `cvss` library from the vectors below (v4.0 first,
v3.1 fallback), not estimated.

| ID | CVSS v4.0 | v4.0 vector | CVSS v3.1 | Severity | Status |
|---|---|---|---|---|---|
| F-01 | 9.3 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:N` | 9.1 | Critical | partially remediated |
| F-02 | 8.7 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:N/VA:N` | 7.5 | High | open |
| F-03 | 8.7 | same as F-02 | 7.5 | High | remediated |
| F-04 | 5.1 | `AV:N/AC:L/AT:N/PR:L/UI:P/VC:L/VI:L/VA:N` | 5.4 | Medium | remediated |
| F-05 | 2.3 | `AV:N/AC:H/AT:N/PR:L/UI:N/VC:N/VI:L/VA:N` | 3.1 | Low (integrity impact High) | remediated |
| F-06 | 6.9 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:L/VI:N/VA:N` | 5.3 | Medium | remediated |
| F-07 | 6.9 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:L/VI:N/VA:L` | 6.5 | Medium | remediated |
| F-08 | 8.6 | `AV:L/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H` | 8.4 | High | mitigated (no upstream fix) |
| F-09 | 6.9 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:L/VI:N/VA:N` | 5.3 | Medium | remediated |
| F-10 | 6.9 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:N/VI:N/VA:L` | 5.3 | Medium | remediated |
| F-11 | 2.1 | `AV:N/AC:H/AT:N/PR:H/UI:N/VC:L/VI:L/VA:N` | 3.3 | Low | remediated |
| F-12 | 4.6 | `AV:L/AC:L/AT:N/PR:H/UI:N/VC:L/VI:L/VA:N` | 3.4 | Low | informational |
| F-13 | 9.1 (if deployed) | `AV:N/AC:H/AT:N/PR:N/UI:N/VC:H/VI:H/VA:N` | 7.4 | High | open (deployment) |
| F-14 | 8.8 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:N/VI:H/VA:H` | 9.1 | Critical | open |
| F-15 | 5.3 | `AV:N/AC:L/AT:N/PR:L/UI:N/VC:N/VI:N/VA:L` | 4.3 | Low | partially |
| F-16 | 5.9 | `AV:L/AC:H/AT:N/PR:N/UI:N/VC:H/VI:N/VA:N` | 5.1 | Medium | partial |
| F-18 | 7.6 | `AV:N/AC:H/AT:N/PR:L/UI:N/VC:H/VI:H/VA:N` | 6.8 | Medium | remediated |
| F-19 | 6.9 | `AV:N/AC:L/AT:N/PR:N/UI:N/VC:L/VI:L/VA:N` | 6.5 | Medium | remediated |

## 28. OWASP ASVS Coverage

Chapter-by-chapter status is in `docs/security/COMPLIANCE_MAPPING.md` §B.5.
Evidence-based level reached: **L1 across V1–V14**, with L2 met for V2
(authentication), V3 (session) and V5 (validation), and L2 gaps for V2 (no MFA,
no breach-password corpus), V4 (no granular RBAC), V8 (retention/erasure) and
V14 (no config baseline tool).

## 29. OWASP Top 10 / API Top 10

See §B.6 of the compliance mapping. Addressed: A01, A03, A05 (and API5, API8,
API10 partially). Partially addressed: A02, A04, A06, A07, A08, A09, A10,
API2–API4. No item is claimed as fully compliant outside the evidence cited.

## 30. NIST CSF 2.0

GOVERN partial · IDENTIFY partial (this assessment) · PROTECT largely ·
DETECT partial (audit tables, no alerting) · **RESPOND not present** ·
**RECOVER not present** (F-14).

## 31. NIST SSDF (SP 800-218)

PW.5/PW.7/RV.2 are the strengths (secure coding, independent review, documented
remediation). Gaps: PS.1 (no SBOM/signing), PW.8 (no browser/DAST tooling
offline), RV.1/RV.3 partially (manual tool runs, no CI).

## 32. CIS Controls v8

Selected status in §B.7: strongest on 6 (access control) and 16 (application
security); **absent on 10 (malware defence) and 11 (data recovery)**; partial on
3, 4, 5, 8.

## 33. ISO/IEC 27001 & 27002

Annex A status in §B.1. Verified strengths: A.5.17 (authentication information),
A.8.15 (logging), A.8.24 (cryptography, partially). Verified gaps: **A.8.13
(backup)**, A.5.24 (incident management), A.6.3 (awareness), A.8.28 (no CI
gate).

## 34. ISO/IEC 27701 (Privacy)

Data minimisation and access control partially meet the standard; **retention,
erasure and breach-notification requirements are not implemented** (§B.2). A
formal privacy program is required before any 27701 claim.

## 35. Iranian Regulatory / AFTA Readiness

See `docs/security/COMPLIANCE_MAPPING.md` Part A. Summary:

* The technical control families an Iranian assessor would examine (access
  control, authentication, password reset accountability, logging, network
  protection, personal-data protection) are largely present and now evidenced.
* The two structural gaps an assessor will flag first are **business continuity
  (no backup)** and **personal data outside the system's control (git history,
  Access copies)**.
* **Nothing here constitutes AFTA certification, official compliance or a legal
  opinion.** Applicability and requirements must be confirmed by an authorized
  Iranian assessor against the primary documents.

## 36. Iranian Primary-Source Gaps (explicitly `NOT VERIFIED`)

| Item | Status |
|---|---|
| AFTA requirement documents (exact text, version, applicability to enterprise software used by critical infrastructure) | **NOT VERIFIED** — secondary descriptions only |
| Whether this organisation is within AFTA's scope | **NOT VERIFIED** — legal/organisational determination |
| National Data & Information Management Law (1401) obligations mapped clause-by-clause | Law identified as in force (12 articles) via secondary sources; **clause-level obligations NOT VERIFIED** |
| Personal Data Protection Bill | Reported as cabinet-approved (2024) and awaiting parliament; **NOT VERIFIED as enacted law** |
| Sector-specific retention/breach-reporting duties | **NOT VERIFIED** |

## 37. Test Results

Exact commands, results and the new-vs-baseline failure breakdown:
`docs/security/TEST_EVIDENCE_SUMMARY.md`.

| Command | Result |
|---|---|
| `SESSION_SECRET_KEY=x HASTAMA_HMAC_SECRET=y .venv/bin/python -m pytest tests -q` | **336 passed, 12 failed, 3 skipped** (3.7 s) |
| `… -m pytest tests/test_security_hardening.py tests/test_security_regressions.py -q` | **137 passed, 0 failed** (3.5 s) |
| baseline (`git archive 38ca85f`), same full-suite command | 217 passed, **17 failed**, 3 skipped |

Of the 12 remaining failures, **10 are pre-existing UI/CSS-table issues, 2 are
pre-existing test-expectation mismatches in the ticketing service** (the tests
assert a transition matrix and a 2-tuple return that the code has never had).
They are reported as-is: no test was deleted, skipped or weakened to make the
suite green. Five baseline failures were fixed by real remediation
(attendance ×4, WebSocket ×1).

## 38. Deployment Security Checklist

`docs/security/DEPLOYMENT_SECURITY_CHECKLIST.md` — 5 sections: secrets
(10 items), TLS/proxy/infra (7), database (6), host/runtime (8), post-deployment
smoke test (10). Every item states the verification step and the expected result.

## 39. Manual DBA / Infrastructure Verification

`docs/security/MANUAL_VERIFICATION_CHECKLIST.md` — 8 DBA queries, 7
infrastructure checks, 8 browser checks and 5 regulatory questions, each with
the exact command or click-path.

## 40. Future Improvements (prioritised)

1. **Backups and a tested restore** (F-14) — before anything else on this list.
2. **Rotate the credentials exposed in F-01** and decide on the git history
   (rewrite or documented acceptance) plus removal of the MDB/EXE artifacts.
3. **MFA for administrators** (TOTP is offline-friendly) and periodic access
   reviews.
4. **Retention & erasure policy** for attendance/payroll/audit data (privacy and
   storage-limitation obligations).
5. **CSP `'unsafe-inline'` removal** in two steps (extract inline handlers, then
   nonce the remaining blocks).
6. **CI gates**: run `pytest`, `bandit`, `ruff --select S` and a secret scanner on
   every change; add an SBOM and a dependency-freeze process.
7. **Alerting** on `security_events` (repeated CSRF failures, session rejections,
   lockout threshold) — a scheduled task on the host is enough offline.
8. **Proxy-level rate limiting** once the `caddy-ratelimit` plugin can be
   vendored into the offline environment.
9. **Content scanning** for uploads (e.g. a local ClamAV instance).
10. **Key rotation story** for the bridge secret (dual-secret acceptance window).

## 41. Final Verdict

**`CONDITIONALLY READY`**

*Reasoning, against the mandate that no confirmed critical/high finding may
remain without documented, formally accepted risk treatment:*

**Ready in substance:** all runtime vulnerabilities confirmed in this engagement
(F-03, F-04, F-05, F-06, F-07, F-09, F-10, F-11, F-18, F-19) are fixed and
covered by 137 passing security tests; the PDF abuse path (F-08) is mitigated at
the call site; the anonymous probe of all 190 routes shows no data endpoint
answering without a session; the SQL review found no injectable path; and
credentials are stored with bcrypt while recovery codes exist only as HMAC
digests. The system can be used on the internal LAN today without exposing the
vulnerabilities that were open at the start of this engagement.

**Why not higher:** four High/Critical-class items are **not** resolved by code
and require decisions or operational work by people who own the system, not the
repository:

1. **F-14 / RR-01 — no backup or restore exists.** For an attendance and payroll
   record system this is the single most consequential gap.
2. **F-01 / F-02 / RR-02 — historic personal data (plaintext passwords, Access
   payroll/attendance databases) remains in git history**, and the credentials in
   that export must be considered compromised until they are rotated.
3. **RR-03 — no MFA for administrators**, who can read all HR data and reset any
   password.
4. **F-08 / RR-06 and F-13 — an unmaintained dependency with an unfixed High
   advisory** (mitigated at the call site) and an empty bridge secret that must
   be set during deployment.

**Why not `BLOCKED`:** none of the remaining items is an exploitable runtime
vulnerability left unaddressed; each has either a compensating control in place
(the PDF call site, fail-closed bridge secret) or is an operational/decision item
that the deployment checklist now makes impossible to overlook.

**Conditions to move to `READY FOR INTERNAL LAN` (informal label — this report
uses only the four mandated verdicts):**

1. implement and **verify a restore** of the database and the Access file;
2. rotate every password that appeared in the quarantined export, and record the
   decision about git history and the committed MDBs;
3. formally accept, with owner and date, the residual risk register entries that
   are marked "acceptance required";
4. enable MFA for master administrators, or record an accepted risk with the
   compensating controls (LAN boundary, throttling, audit review);
5. run the deployment checklist and the manual DBA checklist, and keep the
   evidence with this report.

**Statement of limitation.** This verdict rests on source review, static
analysis, dependency analysis and execution of the test suite in a sandboxed
environment that has no SQL Server, no Araz device, no Caddy and no browser. It
is **not** a penetration test, **not** an AFTA certification, and **not** a
statement that the system is "fully secure". Items in §24 that require the live
environment have not been verified and are marked as such.

---

### Supporting documents

| Document | Content |
|---|---|
| `docs/security/ENDPOINT_AUTHORIZATION_MATRIX.md` | 190 routes with guard family, code location and anonymous-probe result |
| `docs/security/SQL_INJECTION_REVIEW.md` | AST-based review of all 52 dynamic SQL statements |
| `docs/security/PASSWORD_FLOW_DATAFLOW.md` | Password/recovery data flow with line-level evidence |
| `docs/security/THREAT_MODEL.md` | Assets, actors, trust boundaries, 20 threats |
| `docs/security/SECURITY_CONTROL_MATRIX.md` | 48 controls: status, implementation, verification |
| `docs/security/DEPLOYMENT_SECURITY_CHECKLIST.md` | Deployment steps with verification |
| `docs/security/MANUAL_VERIFICATION_CHECKLIST.md` | DBA / infra / browser / regulatory checks |
| `docs/security/TEST_EVIDENCE_SUMMARY.md` | Exact commands, results, new-vs-baseline failures |
| `docs/security/RESIDUAL_RISK_REGISTER.md` | 18 residual risks with owners and acceptance flags |
| `docs/security/COMPLIANCE_MAPPING.md` | Iranian (Part A) and international (Part B) mappings |
| `HASTAMA_SECURITY_HARDENING_CHANGELOG.md` | Change log of this engagement (entry for this phase) |
