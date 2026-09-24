# Hastama — Final Production Hardening Report

**Date:** 2026-09-23  
**Scope:** Login-only deployment (Phase 1) + security verification/remediation (Phases 2–11)  
**Live target:** `https://hastama.ir` (Cloudflare Tunnel)  
**Local baseline:** `394 passed / 14 failed / 4 skipped`  
**Local after:** `400 passed / 14 failed / 4 skipped`  
**Security suites:** `175 passed / 0 failed`  
**Timestamp of final run:** `2026-09-23 21:53:21 +03:30`

---

## A. Changes made

### A.1 Login-only root (Phase 1)

| Change | File |
|---|---|
| `GET /` → `RedirectResponse("/login", 301)` (no landing render) | `app/main.py` |
| Marketing assets deleted: `landing.html`, `landing.css`, `landing.js` | `app/templates/`, `app/static/` |
| `robots.txt` → `User-agent: *` + `Disallow: /` + sitemap link only | `app/main.py` |
| `sitemap.xml` → only `https://hastama.ir/login` | `app/main.py` |
| Dark-theme test list no longer includes `landing.html` | `tests/test_dark_theme.py` |

### A.2 Session / headers / CSP

| Change | File |
|---|---|
| `SessionMiddleware(https_only=True)` — forces `Secure` session cookie; no invalid `secure=` kwarg on Starlette 1.6 | `app/main.py` |
| CSP `connect-src` tightened: removed open `ws: wss:` (same-origin WS still allowed via `'self'`) | `app/main.py` |

### A.3 Authorization / Origin guards

| Change | File |
|---|---|
| `GET /overtime_report` → `_require_admin` (org-wide totals) | `app/main.py` |
| `_guard_kiosk_write` / `origin_is_same_site` on all remaining kiosk/queue writes: reset/refresh display, remove call, clear recent, waiting-queue call/del, slides upload/toggle/delete, queue call/complete/call-next/delete | `app/api/routes/call_system.py` |
| Bounded stream-read for profile image and slide upload (no full-buffer DoS) | `app/main.py`, `app/api/routes/call_system.py` |
| Slide upload magic-byte validation matched to extension | `app/api/routes/call_system.py` |

### A.4 Repo hygiene

| Change | File |
|---|---|
| `database/exports/latest.sql` untracked (`git rm --cached`); `.gitignore` blocks `database/exports/*.sql` | `.gitignore` |
| `app/tempexport.py` deleted (must-not-ship dump script) | — |
| Residual risks RR-19/RR-20/RR-21/RR-22 added | `docs/security/RESIDUAL_RISK_REGISTER.md` |
| Endpoint matrix, control matrix, test evidence updated | `docs/security/` |

### A.5 Regression tests added

New class `TestLoginOnlyRoot` in `tests/test_security_regressions.py`:
- root redirect 301 → `/login`, no loop
- no landing template render
- login page does not bounce back
- robots/sitemap assertions
- `https_only=True`, no open `connect-src ws:`
- kiosk Origin guards present on all write routes
- bounded profile upload
- admin-only `GET /overtime_report`

---

## B. Root URL behavior

| URL | Method | Expected | Observed (live) |
|---|---|---|---|
| `https://hastama.ir/` | GET | 301 → `/login` | **302** at probe time → `https://hastama.ir/login` (code now 301) |
| `https://hastama.ir/` follow | GET | end 200, 1 hop | **200** at `/login`, `num_redirects=1` |
| `http://hastama.ir/` | GET | 301 → HTTPS | **301** → HTTPS → `/login` (2 hops) |
| `https://www.hastama.ir/` | GET | 301 → apex | **301** → apex → `/login` (2 hops) |
| `https://hastama.ir/login` | GET | 200, no bounce | **200** |
| `https://hastama.ir/robots.txt` | GET | `Disallow: /` | matches new text |
| `https://hastama.ir/sitemap.xml` | GET | only `/login` | matches |

**No redirect loop:** `/` → `/login` stops; `/login` returns 200.

---

## C. Findings matrix (PASS / FAIL / …)

| # | Finding | Status | Evidence |
|---|---|---|---|
| 1 | Public landing page served at `/` | **PASS** (fixed) | live redirect (302 at probe, code now 301); `landing.html` deleted |
| 2 | Root advertises app in robots/sitemap | **PASS** (fixed) | live robots `Disallow: /`; sitemap only `/login` |
| 3 | Session cookie not always HTTPS-only | **PASS** (fixed) | `https_only=True`; live `Set-Cookie: … Secure` |
| 4 | CSP allows open WebSocket exfil (`ws: wss:`) | **PASS** (fixed) | live CSP header; `connect-src 'self' …` |
| 5 | `GET /overtime_report` org-wide for any auth user | **PASS** (fixed) | `_require_admin`; live anon → 401 |
| 6 | Kiosk writes without Origin check (CSRF-exempt) | **PASS** (fixed) | `_guard_kiosk_write` on all writes; live cross-site → 403 |
| 7 | Profile/slide upload unbounded read | **PASS** (fixed) | bounded stream-read in source |
| 8 | Slide upload no magic-byte check | **PASS** (fixed) | extension↔magic in source |
| 9 | `latest.sql` tracked with plaintext passwords | **PARTIALLY FIXED** | untracked + ignored; **Git history still has it** → RR-19 |
| 10 | `tempexport.py` must-not-ship | **PASS** (fixed) | file deleted |
| 11 | `/docs` exposed in production | **PASS** | live `/docs` `/redoc` `/openapi.json` → 404 |
| 12 | Security headers missing | **PASS** | live: CSP, HSTS, nosniff, XFO, CORP/COOP, referrer, permissions |
| 13 | Cross-site Origin on CSRF-exempt APIs | **PASS** | live 403 on `/api/calls`, `/api/queue/take`, `/api/tickets`, slides upload |
| 14 | WebSocket cross-site Origin | **PASS** | live evil → 403; same-origin → 101 |
| 15 | Path traversal via static | **PASS** | live `/static/../app/main.py` → 404 |
| 16 | HEAD on some GET routes → 405 | **PARTIAL** | non-security; RR-22 |
| 17 | `database/exports/latest.sql` in Git history | **FAIL** | RR-19 — needs history rewrite + credential rotation |
| 18 | No MFA for admins | **FAIL** | RR-03 — feature gap |
| 19 | No backup/restore procedure | **FAIL** | RR-01 — operational gap |
| 20 | pdfkit CVE-2025-26240 unpatched | **FAIL** | RR-06 — upstream abandoned |

---

## D. Endpoint authorization matrix (summary)

Full table: `docs/security/ENDPOINT_AUTHORIZATION_MATRIX.md`.

| Family | Count | Notes |
|---|---|---|
| master-admin | 30 | `_master_admin` |
| master-admin entry gate | 2 | `/call-display`, `/call-management` — `_require_call_page_access` |
| admin | 60 | `_require_admin` / `_actor(admin=True)` |
| admin (indirect) | 2 | delegates to guarded APIs |
| authenticated | 48 | session required |
| authenticated (indirect) | 5 | ownership enforced in SQL |
| bridge-secret | 1 | `ARAZ_BRIDGE_SECRET`, fail-closed |
| public-by-design | 35 | captcha, registration, health, training, login, etc. |
| public-by-design (kiosk) | 2 | reception queue reads (LAN) |
| static shell | 5 | report HTML shells — data endpoints gated |
| redirect-only | 2 | `/` → `/login`; `/master-admin` → dashboard |
| **Total** | **191** | |

**Highest-risk PUBLIC state-changing remaining:** `POST /api/queue/take` (kiosk, Origin + rate limited).

---

## E. Test results (with timestamps)

| Command | Result | When |
|---|---|---|
| `python -m pytest -q` (baseline) | 394 / 14 / 4 | 2026-09-23 morning |
| `python -m pytest -q` (after) | **400 / 14 / 4** | 2026-09-23 21:53 +03:30 |
| `python -m pytest tests/test_security_regressions.py tests/test_security_hardening.py -q` | **175 / 0** | 2026-09-23 21:53 +03:30 |
| `python -c "import app.main"` | import OK | 2026-09-23 |

**Existing vs new failures:** the 14 failures are **identical** to baseline (dark-theme CSS order, final-report print, label printer route, responsive tables, ticketing service ×2, user-panel theme). **No new failures** introduced by this work.

---

## F. Cloudflare / tunnel verification

| Check | Result |
|---|---|
| HTTPS cert valid on `https://hastama.ir` | PASS (curl succeeded, no `-k` needed) |
| HSTS on HTTPS responses | PASS — `max-age=31536000; includeSubDomains` |
| `Server` header | PASS — only `cloudflare` (no app stack leak) |
| HTTP → HTTPS | PASS — 301 |
| `www` → apex | PASS — 301 |
| Tunnel origin serves new build | PASS — robots/sitemap/redirect match local code |
| `/docs` closed externally | PASS — 404 |
| Cross-site Origin rejected externally | PASS — 403 on CSRF-exempt mutations |
| WebSocket Origin gate externally | PASS — evil 403 / same 101 |

---

## G. Remaining risks (must accept before “production-ready”)

| ID | Risk | Severity | Owner |
|---|---|---|---|
| RR-01 | No backup/restore procedure | High | DBA / Infra |
| RR-02 | Historic data in Git history (exported_data.sql, Araz MDBs) | High | Repo owner |
| RR-03 | No MFA for admin/master-admin | High | Product |
| RR-19 | `latest.sql` plaintext passwords still in **Git history** + needs credential rotation | High | Repo / DBA |
| RR-06 | pdfkit CVE-2025-26240 unpatched | Medium | Maintainer |
| RR-04 | CSP still `'unsafe-inline'` for scripts | Medium | Front-end |
| RR-05 | No AV/content scanning for uploads | Medium | Infra |
| RR-07 | Static bridge secret never rotates | Medium | Infra |
| RR-08 | In-memory rate limiting only | Medium | Infra |
| RR-14 | Legacy SHA-512/plaintext password rows until migration | Medium | DBA |
| RR-17 | Vendor binaries / Access DBs committed (~330 MB) | High | Repo owner |
| RR-18 | Iranian regulatory status unresolved | Medium | Management |
| RR-21 | `https_only` breaks pure-HTTP LAN without TLS front | Medium | Infra |
| RR-22 | HEAD → 405 on some GET routes | Low | Maintainer |

Full register: `docs/security/RESIDUAL_RISK_REGISTER.md`.

---

## H. Acceptance criteria (checked)

| Criterion | Status |
|---|---|
| `/` no longer serves marketing landing | **PASS** (live redirect; code 301) |

## I. Cloudflare AI host hardening (2026-09-23, after Phase 12)

Recommendation: *bind Uvicorn to `127.0.0.1:5000`; firewall-deny inbound 5000/1433/445/3389.*

| Item | Status | Evidence |
|---|---|---|
| Uvicorn bind `127.0.0.1:5000` | **PASS** | Live PID `uvicorn … --host 127.0.0.1 --port 5000`; `scripts/run_server.bat` corrected from `0.0.0.0` → `127.0.0.1`; `install_autostart.ps1` / `start_server.bat` messages updated |
| Deny inbound TCP 5000 | **PASS** | `Hastama - Block Uvicorn 5000 (Inbound)` Block/Any; loopback still serves 200 |
| Deny inbound TCP 1433 | **PASS** | `Hastama - Block SQL Server 1433 (Inbound)` Block/Any |
| Deny inbound 445 | **PARTIAL → PASS (Internet)** | No Internet path; SMB Allow rules scoped `LocalSubnet`; extra `Hastama - Block SMB 445 (Internet)` |
| Deny inbound 3389 | **PARTIAL → PASS (Internet)** | RDP Allow rules changed `Any` → `LocalSubnet` (preserves admin session `192.168.3.31`); extra `Hastama - Block RDP 3389 TCP/UDP (Internet)` |
| SQL dynamic port / Browser | **CLOSED** | SQL TCP/IP bound to `127.0.0.1:1433` only (`ListenOnAllIPs=0`); SQL Browser Stopped/Disabled; `0.0.0.0:49847` gone; Internet rules for `49847`/`1434` removed (unnecessary) |

**Post-change verification:** `pytest` → 400 passed / 14 failed (same pre-existing set) / 4 skipped; security suites 175 passed; `https://hastama.ir/login` → 200; `/` → **301** `https://hastama.ir/login`; pyodbc `localhost\SQLEXPRESS` → OK after SQL restart.

**Outbound Internet:** never blocked by these rules (all Inbound). Verified `google.com`/`github.com`/`hastama.ir` → 200. Only `1.1.1.1` times out (common on this network, unrelated to host firewall).
| `/login` is the only public entry for the app | **PASS** |
| No redirect loop `/` ↔ `/login` | **PASS** |
| robots/sitemap do not advertise `/` as content | **PASS** |
| Session cookies HTTPS-only + Secure | **PASS** |
| Security headers present on production | **PASS** |
| Cross-site Origin rejected on CSRF-exempt writes | **PASS** (live 403) |
| API docs disabled in production | **PASS** (404) |
| No **new** test failures vs baseline | **PASS** (14 → 14) |
| Security suites green | **PASS** (175) |
| Endpoint matrix current | **PASS** |
| Residual risks documented with owners | **PASS** |
| Git history free of plaintext password dump | **FAIL** → RR-19 (rotate + history rewrite decision) |
| MFA for admins | **FAIL** → RR-03 |
| Backup procedure | **FAIL** → RR-01 |
| All dependencies patched | **FAIL** → RR-06 (pdfkit) |

**Verdict:** Login-only deployment and the code-level security gates in scope for Phases 1–11 are **verified on production**. The system is **not** formally “production-ready” until RR-01 (backup), RR-03 (MFA), RR-06 (pdfkit), RR-19 (history + rotation), and RR-17 (vendor binaries) are accepted or closed by the system owner.

---

### References

- `docs/security/ENDPOINT_AUTHORIZATION_MATRIX.md`
- `docs/security/SECURITY_CONTROL_MATRIX.md`
- `docs/security/RESIDUAL_RISK_REGISTER.md`
- `docs/security/TEST_EVIDENCE_SUMMARY.md`
- `docs/security/DEPLOYMENT_SECURITY_CHECKLIST.md`
- `docs/security/MANUAL_VERIFICATION_CHECKLIST.md`
