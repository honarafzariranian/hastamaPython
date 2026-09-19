# Compliance Mapping — Hastama

Two separate sections, as required:

* **Part A — Iranian regulatory / AFTA-oriented readiness.**
* **Part B — International frameworks** (ISO, NIST, OWASP, CIS, CWE, SOC 2, GDPR
  principles).

Rules applied throughout: only controls **verified in this assessment** are
marked as present; anything that could not be evidenced is marked
`NOT VERIFIED`; nothing is claimed as certified or fully compliant.

---

# Part A — Iranian readiness

## A.0 What could and could not be verified

| Item | Status |
|---|---|
| AFTA (امنیت فضای تولید و تبادل اطلاعات) is the Iranian critical-infrastructure security framework, overseen by مرکز مدیریت راهبردی افتا; certificates are issued in cooperation with سازمان فناوری اطلاعات ایران, and certification prerequisites include ISO 9001 / 27001 / 20000 | Reported consistently by Iranian news/consultancy sources reachable during this assessment — **secondary sources only** |
| The exact AFTA requirement documents for enterprise software used by / offered to critical infrastructure | **NOT VERIFIED** — primary documents were not retrievable; they must be obtained from the authority by an authorized Iranian assessor |
| Whether Hastama's operating organisation is classified as critical infrastructure (and therefore in scope) | **NOT VERIFIED** — an organisational/legal determination |
| «قانون مدیریت دادهها و اطلاعات ملی» (National Data & Information Management Law, 1401/2022, 12 articles) — obliges agencies to protect personal data | Identified as the only Iranian personal-data law in force during this assessment |
| «لایحه حفاظت از دادههای شخصی» (Personal Data Protection Bill) | Approved by the cabinet (reported mid-2024) and awaiting/referred to parliament; **not enacted per these reports** → treated as a bill, `NOT VERIFIED` as law |

No claim in this document should be read as `AFTA CERTIFIED`, `OFFICIALLY
COMPLIANT`, `100% SECURE` or `FULLY SECURE`.

## A.1 Control families an Iranian assessor would look for, and the evidence here

| Area | Expectation (engineering framing) | Evidence in Hastama | Gap |
|---|---|---|---|
| Access control to the system | Only identified personnel reach attendance/payroll data | Server-side session + role checks, admin-gated report endpoints, master-admin control plane (`ENDPOINT_AUTHORIZATION_MATRIX.md`) | No MFA (RR-03) |
| Identification & authentication | Strong passwords, no shared accounts, throttling | bcrypt cost 12, policy ≥8 with mixed classes, captcha, throttling, generic errors, no plaintext written | Legacy rows may persist (RR-14) |
| Password reset accountability | Two-person control for privileged resets | Requester + master-admin approval + one-time expiring HMAC-digested code, fully audited | — |
| Personal-data protection (National Data & Information Management Law obligation) | Protect personal data processed by the system | Data minimisation in APIs (explicit columns), no credential export endpoints, TLS on the LAN, access control, audit trail | Historic exports/MDBs in Git (RR-02); no encryption at rest verified (D-2) |
| Data classification | Knowing which data is sensitive | `THREAT_MODEL.md` §1 classifies credentials, attendance, payroll, tickets, audit | Formal classification register is organisational |
| Logging & accountability | Who saw/changed what | `audit_logs`, `security_events`, `admin_actions`, `system_errors`, `user_sessions` written by the application | DB-only storage (RR-09) |
| Incident readiness | Detect and report breaches | Security events + session revocation + error tables | No breach-notification process (R-5) |
| Business continuity | Backups and restore | — | **Absent** (RR-01) — an assessor will treat this as a disqualifying gap |
| Supplier/software assurance | Known vulnerabilities handled | Offline operation, pinned dependencies, `pip-audit` run, one unfixed High acknowledged and mitigated at the call site | No formal patch/SBOM process (RR-16) |
| Network protection | Internal-only exposure, TLS | Caddy with internal TLS, loopback binding, firewall guidance in the deployment checklist | Proxy rate limiting absent (RR-08) |
| Personnel/organisational measures | Confidentiality agreements, awareness | Out of scope of this assessment | Not verified |

## A.2 Actions needed before an Iranian assessment

1. Obtain the current AFTA requirement documents from مرکز مدیریت راهبردی افتا
   and map them item-by-item (only then can a compliance claim be made).
2. Decide whether the operating organisation falls under the law's obligations
   and register/report accordingly.
3. Implement backup/restore and test it (RR-01) — a mandatory evidence item.
4. Remove historic personal data from the repository (RR-02/RR-17) or document a
   formal retention decision.
5. Invite an authorized Iranian assessor for the legal/regulatory confirmation;
   this report deliberately does not substitute for that.

---

# Part B — International frameworks

## B.1 ISO/IEC 27001:2022 Annex A (selected)

| Control | Status | Evidence / gap |
|---|---|---|
| A.5.15 Access control | Partly | Role checks server-side; MFA absent (RR-03) |
| A.5.16 Identity management | Partly | User lifecycle exists (`user_table`, `user_sessions`); no formal joiner/leaver process |
| A.5.17 Authentication information | Yes | bcrypt cost 12, policy, throttling, HMAC recovery codes, no plaintext writes |
| A.5.24 Incident management | No | No documented incident process (organisational) |
| A.6.3 Awareness | No | Not evidenced |
| A.8.8 Technical vulnerabilities | Partly | Pinned dependencies, `pip-audit`; no CI gate (RR-16); one unfixed High (RR-06) |
| A.8.9 Configuration management | Partly | `.env.example` + checklist; no config baseline tool |
| A.8.11 Data masking | Partly | APIs return only needed fields; reports show full personal data to admins by design |
| A.8.12 Data leakage prevention | Partly | Quarantined export; historic repo data remains (RR-02) |
| A.8.13 Backup | **No** | RR-01 |
| A.8.15 Logging | Yes | Audit/security/admin/error tables, CSRF and session events |
| A.8.16 Monitoring | Partly | Tables exist; no alerting/SIEM in an offline LAN |
| A.8.20 Network security | Partly | Internal TLS, loopback app binding; proxy rate limit absent |
| A.8.24 Cryptography | Partly | bcrypt, HMAC-SHA256, signed cookies, TLS internal CA; no at-rest encryption verified |
| A.8.28 Secure coding | Partly | This assessment's remediation + regression tests; no CI static analysis |

ISO/IEC 27002:2022 guidance controls were used for the design of the remediation
items above (parameterised queries, output encoding, least privilege, logging of
security events, fail-closed secrets).

## B.2 ISO/IEC 27701 (privacy)

| Requirement area | Status | Evidence |
|---|---|---|
| Lawful basis / purpose limitation | Not verified | Organisational |
| Data minimisation | Partly | Explicit column lists; report APIs admin-only |
| Accuracy | Partly | Attendance corrections flow through admin workflows |
| Retention & deletion | **No** | No retention/erasure function for attendance, payroll or audit data |
| Individual rights (access/rectification) | Partly | Users see their own data (`/get_user_info`, `/get_leave_info`); no export/erasure workflow |
| Breach notification | No | RR-09/R-5 |

## B.3 NIST CSF 2.0 (functions)

| Function | Status | Notes |
|---|---|---|
| GOVERN | Partly | Security work is documented; no formal policy set in the repo |
| IDENTIFY | Partly | This assessment produced the asset/threat model |
| PROTECT | Largely | Authentication, session, CSRF, output encoding, upload controls, least-privilege guidance |
| DETECT | Partly | Audit tables and security events; no monitoring/alerting on a single offline host |
| RESPOND | No | No incident-response runbook (organisational) |
| RECOVER | **No** | No backup/restore (RR-01) |

## B.4 NIST SSDF (SP 800-218)

| Practice | Status | Evidence |
|---|---|---|
| PO.1 define security requirements | Partly | This report and the control matrix |
| PS.1 protect software | No | No signed artifacts / SBOM in the repo |
| PS.2 verify third-party components | Partly | Pinned deps + `pip-audit`; manually run |
| PW.4 reuse well-secured software | Partly | Standard frameworks; pdfkit is unmaintained (RR-06) |
| PW.5 secure coding practices | Yes | Parameterised SQL, escaping, allow-lists, fail-closed secrets |
| PW.7 review code | Yes | Independent review with 137 behavioural tests |
| PW.8 test executable code | Partly | Unit/integration tests; no browser/DAST tooling offline |
| RV.1 identify vulnerabilities | Partly | Bandit, Ruff, pip-audit run manually |
| RV.2 assess & remediate | Yes | Findings register with status and CVSS |
| RV.3 root-cause analysis | Partly | Documented per finding |

## B.5 OWASP ASVS 4.0.3 / 5.0 (targeted)

| ASVS chapter | Level reached (evidence-based) | Notes |
|---|---|---|
| V1 Architecture | L1 | Threat model + data-flow documents |
| V2 Authentication | L1–L2 | bcrypt, policy, throttling, generic errors, captcha; MFA/breach-password check absent |
| V3 Session | L1–L2 | Signed cookie + registry, HttpOnly/SameSite/Secure, revocation; no re-auth for sensitive ops |
| V4 Access control | L1 | Server-side role checks, ownership checks, admin-gated reports; admin = all-or-nothing (no granular RBAC) |
| V5 Validation / encoding | L1 | Markup rejection + escaping; no ORM |
| V6 Cryptography | L1 | bcrypt/HMAC/TLS; at-rest unknown |
| V7 Error handling/logging | L1 | Generic errors, audit trail; no log shipping |
| V8 Data protection | Partly | LAN TLS; no retention/erasure; historic files (RR-02) |
| V9 Communications | L1 | TLS internal CA; HSTS only over HTTPS |
| V10 Malicious code | L1 | Pinned deps; no AV scanning |
| V11 Business logic | L1 | Transition validation in ticketing, attendance state machine |
| V12 Files | L1 | Random names, type caps, private dir, traversal containment |
| V13 API | L1 | Auth on data APIs, CSRF, WebSocket origin checks |
| V14 Configuration | L1 | Secrets required/fail-closed, docs closed, headers |

## B.6 OWASP Top 10:2021 and API Security Top 10:2023

| OWASP item | Status | Evidence |
|---|---|---|
| A01 Broken access control | Addressed | Report endpoints admin-gated; ownership in SQL; kiosk origin checks |
| A02 Cryptographic failures | Partly | bcrypt/HMAC/TLS; at-rest encryption unverified |
| A03 Injection | Addressed | AST SQL review; identifier allow-list; PDF child-process hardening |
| A04 Insecure design | Partly | Threat model; no formal design review process |
| A05 Security misconfiguration | Addressed | Secrets fail-closed, docs closed, headers, proxy hardening |
| A06 Vulnerable components | Partly | One unfixed High (pdfkit) with call-site mitigation |
| A07 Identification/authentication failures | Partly | Throttling, captcha, bcrypt; no MFA |
| A08 Integrity failures | Partly | No CI/CD pipeline to protect; offline installs |
| A09 Logging/monitoring failures | Partly | Rich audit tables; no alerting, DB-only retention |
| A10 SSRF | Addressed for the known sink | PDF generation no longer honours `--script`/local file access |
| API1 BOLA | Addressed | Ownership enforced in queries |
| API2 Broken authentication | Partly | Same as A07 |
| API3 BOPLA | Partly | Responses are field-limited by explicit columns |
| API4 Unrestricted resource consumption | Partly | Upload/batch/pagination caps; no proxy rate limit |
| API5 BFLA | Addressed | Master-admin plane separated |
| API8 Misconfiguration | Addressed | As A05 |
| API10 Unsafe consumption of APIs | Partly | Bridge input validated and capped |

## B.7 CIS Controls v8 (selected)

| Control | Status | Evidence |
|---|---|---|
| 3 Data protection | Partly | Classification document; no at-rest encryption verified |
| 4 Secure configuration | Partly | Deployment checklist; no hardened baseline file |
| 5 Account management | Partly | Roles exist; no periodic access review |
| 6 Access control | Partly | Server-side checks; MFA absent |
| 8 Audit log management | Partly | Tables + events; retention/centralisation missing |
| 10 Malware defences | **No** | No AV/EDR in scope (offline LAN) |
| 11 Data recovery | **No** | RR-01 |
| 16 Application software security | Partly | This assessment + tests; no CI gates |

## B.8 CWE coverage of the findings

| CWE | Title | Where |
|---|---|---|
| CWE-79 | Improper neutralisation of input during web page generation | Stored-XSS findings in report renderers and admin tables |
| CWE-89 | SQL injection | Reviewed (not confirmed in app code); `tempexport.py` informational |
| CWE-22 | Path traversal | Profile image / attachment handling |
| CWE-200 | Exposure of sensitive information | Committed export and Access databases, device endpoints |
| CWE-287/384 | Improper authentication / session fixation | Login and session handling |
| CWE-352 | Cross-site request forgery | CSRF middleware rollout |
| CWE-434 | Unrestricted upload of dangerous file types | Profile/attachment/slide upload controls |
| CWE-613 | Insufficient session expiration | Session lifetime + revocation |
| CWE-778 | Insufficient logging | Audit coverage |
| CWE-1104 | Use of unmaintained third-party components | pdfkit |

## B.9 SOC 2 (Trust Services Criteria) — readiness view

| Criterion | Status | Note |
|---|---|---|
| CC6 Logical access | Partly | Strong authentication/session/authorisation controls; MFA and access reviews absent |
| CC7 Operations | Partly | Logging present; monitoring/alerting and incident process absent |
| CC8 Change management | No | No CI/CD or change-control evidence in the repo |
| CC9 Risk mitigation | Partly | This assessment and register; vendor risk process absent |
| A1 Availability | **No** | No backup/restore (RR-01) |
| PI1 Privacy | No | No retention/erasure program |

## B.10 GDPR principles (if EU personal data were processed)

Hastama processes Iranian employee data on an internal LAN; the GDPR is listed
here only for principle-level alignment, not as an applicable-law claim.

| Principle | Alignment | Note |
|---|---|---|
| Lawfulness/fairness/transparency | Not assessed | Depends on the employer's notice to staff |
| Purpose limitation | Partly | Attendance/payroll purposes are implied by the schema |
| Data minimisation | Partly | Field-limited APIs; reports intentionally broad for HR |
| Accuracy | Partly | Correction via admin workflows; no audit of corrections beyond `admin_actions` |
| Storage limitation | **No** | No retention/erasure mechanism |
| Integrity & confidentiality | Partly | Authentication, authorisation, TLS, parameterised SQL, escaping |
| Accountability | Partly | Audit tables; no DPIA/records of processing |

**PCI DSS:** not applicable — no payment-card data is processed anywhere in the
codebase or schema.
