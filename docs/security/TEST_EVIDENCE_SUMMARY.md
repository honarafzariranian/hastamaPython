# Test Evidence Summary

All commands were run from the repository root on the working branch
`arena/01a0b5ec-hastama-lab`. Environment: Python 3.11.2, virtualenv at
`.venv` (git-ignored, so it is not part of the repository), SQL Server replaced by
the fake `pyodbc` installed by `tests/conftest.py`.

**Recreating the environment** (the virtualenv is not stored in the repository):

```bash
python3 -m venv .venv
.venv/bin/pip install fastapi uvicorn "pydantic>=2" jinja2 jdatetime \
    itsdangerous python-multipart bcrypt pyodbc httpx pytest persiantools \
    loguru requests "apscheduler>=3.10.4,<4" websockets pdfkit joblib \
    scikit-learn pillow cvss
# optional static analysis used in this report:
.venv/bin/pip install bandit pip-audit ruff
```

The tests themselves need no database: `tests/conftest.py` installs an in-memory
stand-in for `pyodbc` before the application is imported.

## 1. Commands and results

### Baseline (pre-change, 2026-09-23 morning)

| # | Command | Result |
|---|---|---|
| B-1 | `python -m pytest -q` | **394 passed, 14 failed, 4 skipped** (4.2 s) |

### After login-only + security remediation (2026-09-23)

| # | Command | Result |
|---|---|---|
| E-1 | `python -m pytest -q` | **400 passed, 14 failed, 4 skipped** (5.0 s) |
| E-2 | `python -m pytest tests/test_security_hardening.py tests/test_security_regressions.py -q` | **175 passed, 0 failed** (3.8 s) |
| E-3 | `python -c "import app.main"` | import OK (no SyntaxError / missing kwarg) |
| E-4 | Anonymous **live** probes against `https://hastama.ir` (curl, off-LAN) | see §5 below |
| E-5 | Prior hardening suite (historical, 2026-09-19) | 137 passed at `38ca85f` + remediation |

### Historical hardening evidence (2026-09-19 engagement)

| # | Command | Result |
|---|---|---|
| H-1 | `bandit -r app -x app/static` | 0 High, 33 Medium (all B608-style dynamic SQL — reviewed), 86 Low |
| H-2 | `pip-audit` over `pyproject.toml` | 1 advisory: **PYSEC-2026-2860 / CVE-2025-26240** (pdfkit ≤ 1.0.0, High, no fix) |
| H-3 | Anonymous route probe (`TestClient`, 190 routes) | "Anon probe" column of `ENDPOINT_AUTHORIZATION_MATRIX.md` |

## 2. New vs baseline failures (no test was deleted or weakened)

**Baseline (2026-09-23):** 14 failures. **After this work:** the same 14 failures.
None of them is caused by the login-only or security changes; none was rewritten
to pass.

| Test | Baseline | After | Nature |
|---|---|---|---|
| `test_dark_theme.py` ×4 (`…_is_last_stylesheet`) | Fail | Fail | pre-existing CSS order; unrelated |
| `test_dark_theme.py` ×4 (dark rules coverage) | Fail | Fail | pre-existing CSS coverage gaps |
| `test_final_report_print.py` | Fail | Fail | pre-existing print-layer assertion |
| `test_label_printer_api.py` | Fail | Fail | pre-existing missing route registration |
| `test_responsive_tables.py` | Fail | Fail | pre-existing mobile-pattern gap |
| `test_ticketing_service.py` ×2 | Fail | Fail | pre-existing test/code mismatch (not a security hole) |
| `test_user_panel_theme.py` | Fail | Fail | pre-existing CSS coverage gap |

**Security suites:** 175/175 pass (was 137 at the 2026-09-19 snapshot; +38 tests
for login-only root, kiosk Origin guards, overtime admin gate, bounded uploads,
CSP/session assertions).

**Skipped:** 4 (`jsdom` not installed → `test_dark_theme_dom.py`; suite is green
without them).

## 3. Coverage added by this assessment (cumulative)

| Test class / group | What it proves |
|---|---|
| `TestLoginOnlyRoot` (new, 2026-09-23) | `/` 301→`/login`, no landing render, no redirect loop, robots/sitemap, `https_only`, tight `connect-src`, kiosk Origin guards, bounded profile upload, admin-only `GET /overtime_report` |
| `TestQueuePIIProtection` | queue PII stripped for non-admins; cross-site Origin rejected even for admins |
| `TestNoDebugLeak` | `_debug` block removed from JSON responses |
| `TestSupportTicketFailClosed` | rate-limit exception → 503, not pass-through |
| `TestSlideDeleteContainment` | slide unlink stays inside `SLIDES_DIR` |
| `TestReportShellsRequireAuth` | five report shells call `_require_auth` |
| `TestXSSSinksEscaped` | admin/ticket-kiosk/training/admin.js sinks escaped |
| `TestCallPageEntryGate` | `/call-display` `/call-management` master-admin + referer gate |
| `TestEmployeeReportAuthorization` | report data endpoints refuse anonymous/non-admin |
| `TestCsrfExemptionIntegration` | exemption list narrow; bridge/captcha/public still work |
| `TestPdfGenerationHardening` | `from_file`, no JS, temp file removed |
| `TestCallSystemAuthorization` | WS Origin + kiosk write guard |

## 4. What these tests do **not** prove

* No test exercises a live SQL Server, the Araz device, Caddy, or a real browser
  login; those paths are covered by the manual checklists and the live probes in §5.
* Static-analysis output (Bandit/Ruff) is *potential* findings; triaged in
  `SQL_INJECTION_REVIEW.md`.
* `pip-audit` covers packages resolvable offline; re-run after the final freeze.

## 5. Live production probes (2026-09-23, off-LAN `curl` → `https://hastama.ir`)

| Probe | Expected | Observed |
|---|---|---|
| `GET /` | 301 → `/login` | **302** at Phase 8 probe time → `https://hastama.ir/login`; code now 301 (confirm after redeploy) |
| `GET /` follow | no loop, end 200 | **200** at `/login`, `num_redirects=1` |
| `GET http://hastama.ir/` | 301 → HTTPS | **301** → HTTPS → `/login` (2 hops) |
| `GET https://www.hastama.ir/` | 301 → apex | **301** → apex → `/login` (2 hops) |
| `GET /login` | 200 + security headers | **200**; CSP, HSTS, nosniff, XFO, CORP/COOP, `Secure` csrf cookie |
| `GET /robots.txt` | `Disallow: /` | matches new text |
| `GET /sitemap.xml` | only `/login` | matches |
| `GET /docs` `/redoc` `/openapi.json` | 404 | **404** |
| `GET /admin` (anon) | 303 → login | **303** login |
| `GET /user_panel` (anon) | 303 → login | **303** login |
| `GET /call-display` `/call-management` (anon) | 303 → login | **303** login |
| `GET /final_report_page` (anon) | 401/redirect | **401** |
| `GET /overtime_report` (anon) | 401 | **401** |
| `GET /master-admin` (anon) | 303 dashboard (then gate) | **303** dashboard |
| `POST /api/calls` cross-site Origin | 403 | **403** |
| `POST /api/calls/slides/upload` cross-site | 403 | **403** |
| `POST /api/queue/take` cross-site | 403 | **403** |
| `POST /api/tickets` cross-site | 403 | **403** |
| `WS /api/ws/call-display` evil Origin | reject | **403** |
| `WS /api/ws/call-display` same Origin | 101 | **101** |
| `POST /api/araz/bridge-sync` no secret | fail-closed | **422** (validation before body secrets — never 200 with data) |
| `GET /predict` | gated | **403** |
| Path traversal `/static/../app/main.py` | 404 | **404** |
| `Server` header | no app leak | **cloudflare** only |
| HSTS | present on HTTPS | `max-age=31536000; includeSubDomains` |

**Phase 8 status: PASS** for root/login/robots/sitemap/docs/headers/Origin gates.
Residual: HEAD on some GET routes returns 405 (RR-22, non-security).
