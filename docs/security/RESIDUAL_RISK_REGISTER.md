# Residual Risk Register — Hastama

Each risk states the residual exposure **after** the hardening work, the reason
it remains, and the owner. Severities use the same scale as the findings.
"Acceptance" column: whether the risk needs a formal decision by the system
owner before the system is treated as ready.

| ID | Risk | Severity | Why it remains | Owner | Acceptance required |
|---|---|---|---|---|---|
| RR-01 | **No backup / restore procedure.** Nothing in the repository performs or verifies a database backup; a ransomware or disk event destroys all attendance and payroll data | High | Operational gap, outside the application code; needs DBA + Windows task setup | DBA / Infra | **Yes** |
| RR-02 | **Historic data still in Git history.** The plaintext password export (`exported_data.sql`) and Araz Access databases (attendance/payroll schema `TPrsInOut`, `TPrsPeyment`, `TPrsSalaryAdditions`) remain in past commits and in the working tree | High | Removal would rewrite published history and delete vendor installers; the working copy was quarantined and ignore rules added | Repository owner | **Yes** |
| RR-03 | **No MFA for administrators / master administrators.** A stolen admin password plus LAN access is full control | High | Feature work; offline environment still allows TOTP | Product owner | **Yes** |
| RR-04 | **CSP still allows `'unsafe-inline'` for scripts.** The legacy inline event handlers (`onclick=` in Jinja templates) cannot be removed without a front-end refactor | Medium | Documented migration path: extract handlers to JS files, then drop `'unsafe-inline'` and add nonces | Front-end owner | Yes (time-boxed) |
| RR-05 | **No content scanning for uploads.** Ticket attachments and slides are validated by extension/size only | Medium | Offline LAN; Antivirus products are not installed on the app server | Infra | Yes |
| RR-06 | **pdfkit CVE-2025-26240 has no upstream fix.** Call-site mitigation is in place, but a future developer could reintroduce `from_string` | Medium | Dependency abandoned upstream (last release 1.0.0) | Maintainer | Yes |
| RR-07 | **Static shared bridge secret.** `ARAZ_BRIDGE_SECRET` never rotates and is stored in a JSON file next to the agent | Medium | No key-management service on the LAN; rotation requires a coordinated restart | Infra | Yes |
| RR-08 | **In-memory rate limiting only.** Limits reset when the service restarts, and there is no proxy-level limit (Caddy plugin unavailable offline) | Medium | Single-process deployment makes this acceptable for the login flow | Infra | Yes |
| RR-09 | **Audit logs live only in the database.** An attacker with DB access can edit or delete them; no off-host shipping | Medium | Offline environment; a second host or append-only file would be needed | DBA | Yes |
| RR-10 | **Legacy session entries.** Sessions created before the registry existed are rejected (fail-closed) — users must log in again after deployment | Low | Intentional; documented in the deployment notes | Infra | No |
| RR-11 | **Registration endpoint exposes username/national-id availability** (`/check-username`, `/check-national-id`) | Low | Product requirement for the self-service form; rate-limited | Product owner | Yes |
| RR-12 | **Kiosk endpoints are unauthenticated by design.** They disclose the reception queue (numbers, departments) to anyone on the LAN | Low | A TV cannot hold a session; the compensating control is the LAN boundary + Origin checks | Infra | Yes |
| RR-13 | **`/predict` ML endpoint is public.** No data access, but it consumes CPU and was never designed for public exposure | Low | Shipped with the repo; must be reviewed before wider exposure | Product owner | Yes |
| RR-14 | **Legacy rows may still be SHA-512/plaintext** until `tools/migrate_passwords.py` runs | Medium | Requires a maintenance window with DB access | DBA | Yes |
| RR-15 | **`/download_pdf` depends on a template that is absent from this repository.** Installs lacking `finalReportUser.html` return 503 instead of a PDF | Low | Either the template exists only in the deployed installation, or the feature is dead | Maintainer | No |
| RR-16 | **No secret scanning / dependency gate in CI.** Secret and dependency checks were performed manually in this assessment | Low | No CI pipeline exists in the repository | Maintainer | No |
| RR-17 | **Vendor binaries and Access databases committed** (~330 MB, `arazin/`, `database/`), including dated payroll/attendance DB copies | High | Deleting them may break field installations (Araz needs its own DB) | Repository owner | **Yes** |
| RR-18 | **Iranian regulatory status unresolved.** AFTA applicability and the data-protection bill's status could not be verified from primary sources | Medium | Needs an authorized Iranian assessor / legal review | Management | **Yes** |
| RR-19 | **`database/exports/latest.sql` holds plaintext passwords** in the legacy `password` column (`123`, `admin`, …). Untracked from Git on 2026-09-23 and ignored going forward, but the working copy and **Git history** still contain it | High | Restoring from the dump is a documented workflow; history rewrite would break clones. Rotate every credential that appears in the dump and re-export with hashes only | Repository owner / DBA | **Yes** |
| RR-20 | ~~Production tunnel not yet redeployed~~ **CLOSED 2026-09-23.** External probes: `/` → 302 `/login` (code later switched to 301 after confirmation); `http` → 301 HTTPS; `www` → 301 apex; robots/sitemap updated; `/docs` 404; HSTS present | — | Verified with `curl` against `https://hastama.ir` from off-LAN | Infra | No |
| RR-21 | **Session cookies: `https_only=True` is always on** (Starlette). On pure-HTTP LAN (`http://127.0.0.1:8000`) the browser will refuse to store the session cookie unless the operator terminates TLS (Caddy/`start_hastama.bat`) | Medium | Deliberate fail-closed choice for the tunnel deploy; local plain-HTTP dev needs a TLS front or a documented exception | Infra | Yes |
| RR-22 | **HEAD on GET routes returns 405** from the production edge (observed 2026-09-23 on `/login` and `/`). Security impact: none (GET still works); some monitors use HEAD for uptime | Low | Likely FastAPI/Starlette HEAD handling under the current router setup; fix only if monitoring requires HEAD | Maintainer | No |
| RR-23 | ~~SQL Server Express still listens on `0.0.0.0:<dynamic>`~~ **CLOSED 2026-09-23.** TCP/IP bound to `127.0.0.1:1433` only (`ListenOnAllIPs=0`, IP4 enabled, IP1–3 disabled); SQL Browser **Stopped/Disabled**; Internet block rules for `49847`/`1434` removed as no longer required | — | `netstat`: only `127.0.0.1:1433`; pyodbc `localhost\SQLEXPRESS` OK; app `/login` 200 after restart | DBA / Infra | No |
| RR-24 | **Many third-party `Allow` rules use `LocalPort=Any` + `RemoteAddress=Any`** (Teams, VS Code, Chrome helpers, `python.exe`, etc.). They do not expose the app port (5000 is loopback-bound and explicitly Block-listed) but weaken host-wide inbound posture | Medium | Owned by desktop software installs; cleaning them is an ops task outside the app repo | Infra | Yes |

## Acceptance template

> I, the system owner, accept residual risk RR-xx (*title*) with the stated
> mitigation and review date. — name, role, date.
