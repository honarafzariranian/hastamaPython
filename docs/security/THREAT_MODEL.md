# Threat Model — Hastama

**Method:** asset-driven, attack-path oriented (OWASP ASVS 1.x / threat-modeling
practice), based on the actual code, configuration and deployment topology read
during this assessment. Each threat lists the control that exists today and the
residual gap. **No threat is listed as mitigated without naming the code that
implements the control.**

**Deployment assumed:** single Windows host on an internal LAN, FastAPI behind
Caddy with an internal TLS certificate (`hastama.local`), SQL Server on the same
host (`localhost\SQLEXPRESS`), Araz T7 attendance device + Access database,
offline (no Internet egress).

---

## 1. Assets

| ID | Asset | Where | Sensitivity |
|---|---|---|---|
| A1 | Employee credentials (`user_table.password_hash`, legacy `password`) | SQL Server | Critical |
| A2 | Attendance records (`hozoor`, Araz `TPrsInOut`) | SQL Server + MDB | High (personal data) |
| A3 | Leave / overtime / hourly-pass records (`mrkhc_table`, `ezafe_table`, `totalpass_table`, `avalpss_table`…) | SQL Server | High (personal data) |
| A4 | Payroll data | `payroll*` tables, `TPrsPeyment` in the committed MDB | Critical |
| A5 | Support tickets and attachments | `tickets`, `ticket_messages`, private attachment dir | Medium–High |
| A6 | Audit / security logs (`audit_logs`, `security_events`, `admin_actions`, `system_errors`, `user_sessions`) | SQL Server | High (integrity) |
| A7 | Session cookies / CSRF tokens / recovery codes | Browser + `password_reset_requests` | Critical |
| A8 | Configuration secrets (`SESSION_SECRET_KEY`, `HASTAMA_HMAC_SECRET`, `ARAZ_BRIDGE_SECRET`, SSMS/Access credentials) | `.env` / environment | Critical |
| A9 | Araz bridge agent + shared secret | `tools/bridge_agent.py`, `/api/araz/*` | High |
| A10 | Host integrity (Windows service, Caddy, SQL Server, wkhtmltopdf) | Server | Critical |

## 2. Actors

| Actor | Capability assumed |
|---|---|
| Anonymous LAN host | Can reach `https://hastama.local`, cannot read files on the server |
| Authenticated employee | Valid session, non-admin |
| Administrator | Admin session (`is_admin`) |
| Master administrator | `is_master_admin` — full control plane |
| Malicious insider with DB access | Can read all tables directly |
| Device/bridge | Holds `ARAZ_BRIDGE_SECRET`, talks machine-to-machine |
| Visitor on the LAN (guest Wi-Fi, patched-in laptop) | Same as anonymous LAN host |

## 3. Trust boundaries

```
[Browser] --TLS(internal CA)--> [Caddy] --HTTP@127.0.0.1--> [uvicorn/FastAPI]
                                                                 |-- SQL Server (localhost)
                                                                 |-- Access MDB (local file)
                                                                 |-- Araz T7 device (TCP, LAN)
                                                                 |-- wkhtmltopdf (child process)
```

Boundaries crossed: browser→Caddy (TLS, host header), Caddy→app (proxy headers,
trusted-proxy list), app→DB (parameterised SQL), app→device (shared secret),
app→child process (report generation).

## 4. Threat table

| ID | Threat | STRIDE | Path | Existing control (evidence) | Residual |
|---|---|---|---|---|---|
| T1 | Credential stuffing / brute force on `/login_user` | Spoofing | Public endpoint | Sliding-window limiter: 15 failures / 10 min per IP, per-account failure counting, captcha required, generic failure message (`auth.py:109-110,346,428-440`) | No MFA; lockout is per-process memory, resets on restart |
| T2 | Username enumeration via login/reset/registration | Info disclosure | Public endpoints | Login returns one generic message; `/forgot_password` returns an identical body + decoy request id for unknown accounts; recovery reasons are only revealed after the code matches (`audit.py verify_recovery_code`) | `/check-username` and `/check-national-id` still answer "exists / free" — accepted product behaviour, logged |
| T3 | Session fixation / hijack | Spoofing | Cookie | Server-side session registry (`sid` must exist in `user_sessions`), signed cookie, `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS detected through a trusted proxy, idle expiry `SESSION_MAX_AGE_SECONDS=28800`, revocation on password reset (`app/core/sessions.py`, `_SessionRegistryMiddleware`) | No CSRF token rotation beyond the session; cookie theft within the idle window is not detectable |
| T4 | CSRF against legacy forms / kiosk endpoints | Tampering | Browser | Session-bound double-submit token on every mutating request; Origin check for exempt paths; kiosk writes rejected on cross-site/opaque Origin (`main.py _CSRFMiddleware`) | Exempt list is broad by necessity — every entry is documented in the code |
| T5 | Stored XSS through employee-written fields rendered in admin/report pages | Tampering | Leave substitute, overtime description → admin tables, reports | Render-layer escaping (`esc()`/`dom-escape.js`) in admin, master-admin and the four report scripts; write paths reject `<`, `>`, NUL (`app/core/validation.py reject_markup`); CSP has no `object-src`, `base-uri 'none'` | CSP still allows `'unsafe-inline'` (needed by the legacy inline scripts) |
| T6 | SQL injection | Tampering | All queries | Full AST review of 52 dynamic statements: identifiers come from fixed literals or a 3-entry allow-list; all values are bound (`docs/security/SQL_INJECTION_REVIEW.md`) | `app/tempexport.py` (unimported migration script) interpolates a catalogue table name — do not ship |
| T7 | IDOR on tickets, notifications, attendance, reports | Elevation | API | Ownership enforced in SQL (`_ticket_accessible_row`, `_owned_update`, `_attendance_actor`); report endpoints now admin-gated; `get_user_info_report` restricts non-admins to their own row | Older `/get_*` endpoints keep their historical semantics; only admin-reachable |
| T8 | Privilege escalation to admin/master-admin | Elevation | Session | `is_admin` / `is_master_admin` are read only from the signed session (`get_is_admin_from_session`, `_master_admin`); a client-supplied role in the body is ignored (`tests/test_security_hardening.py::TestMasterAdminAuthorization`) | Anyone with DB write access can set `role='admin'` — DBA control required |
| T9 | LAN attacker reads reports/attendance without an account | Info disclosure | HTTP | Report data endpoints require an admin session; kiosk endpoints return only queue state | Committed export/MDB files leak historic data outside the app (see F-01/F-02) |
| T10 | Access DB / Araz secrets leak through errors or repo | Info disclosure | Files | `Access password` read from configuration, task never logs it; bridge errors never echo the submitted secret (`araz_api.py`) | `tools/bridge_config.json` ships with an empty secret; committed MDBs and `.exe` binaries |
| T11 | Malicious file upload (profile image, ticket attachment, slide) | Tampering | HTTP upload | Server-generated storage names, extension allow-list, size caps, private directory for ticket attachments, filename sanitisation + containment check for profile images | Content is not scanned (no AV product offline); slides are images only |
| T12 | Path traversal via filenames/IDs | Tampering | Upload/download | `_sanitize` + `os.path.abspath` containment for the profile image; ticket attachments stored under a private root with random names | Any future file endpoint must reuse the same helpers |
| T13 | Malicious document → child process abuse (wkhtmltopdf) | Elevation | PDF generation | `pdfkit.from_string` removed (CVE-2025-26240): temp file + `from_file`, JavaScript and local file access disabled, stylesheet inlined | pdfkit itself remains unpatched upstream — keep the call-site mitigation |
| T14 | Device protocol abuse (Araz/T7) | Spoofing | `/api/araz/*` | Shared secret mandatory, fail-closed when unset, constant-time compare, batch size capped | Secret is a static shared key (no rotation/expiry) |
| T15 | Log tampering / repudiation | Repudiation | Audit tables | Security events written to SQL Server tables; writes never depend on the client; CSRF/session/logout/reset events recorded | DB-only storage: an attacker with DB access can edit it; no off-host log shipping |
| T16 | Denial of service | DoS | HTTP | Upload caps, batch caps, pagination limits, login throttling, request body limit in Caddy (12 MB) | No reverse-proxy rate limiting (Caddy plugin absent); a large login flood still consumes CPU |
| T17 | Supply chain | Tampering | Dependencies | Dependencies pinned in `pyproject.toml`/`uv.lock`; offline installation | One known-High advisory with no fix (pdfkit CVE-2025-26240); no SBOM/signature verification |
| T18 | Backup / ransomware | — | Host | — | No backup or restore procedure exists in the repository (see residual risk RR-01) |
| T19 | Sensitive data in the git history | Info disclosure | Repo | Export file quarantined, ignore rules added | Historic commits still contain the plaintext export and the Access databases |
| T20 | Malicious lower environment reuse | — | Dev | `DEBUG` gates `/docs` and the random-key fallback | Development keys/exports must never be promoted to production |

## 5. Assumptions and out-of-scope

* Physical access to the server and to the LAN switch is out of scope, as is a
  compromised Windows account with administrative rights on the host.
* SQL Server hardening (TDE, backup encryption, login model, surface area) is
  **manual DBA verification** — see the deployment checklist.
* Iranian regulatory obligations are treated in the compliance section; primary
  regulatory texts were not retrievable from this environment and are marked
  `NOT VERIFIED`.
