# Test Evidence Summary

All commands were run from the repository root on the working branch
`arena/01a0b5ec-hastama-lab`. Environment: Python 3.11.2, virtualenv at
`.venv` (git-ignored), SQL Server replaced by the fake `pyodbc` installed by
`tests/conftest.py`.

## 1. Commands and results

| # | Command | Result |
|---|---|---|
| E-1 | `SESSION_SECRET_KEY=x HASTAMA_HMAC_SECRET=y .venv/bin/python -m pytest tests -q` | **336 passed, 12 failed, 3 skipped** (3.8 s) |
| E-2 | `… -m pytest tests/test_security_hardening.py tests/test_security_regressions.py -q` | **137 passed, 0 failed** (3.5 s) |
| E-3 | `git archive HEAD(38ca85f) | tar -x -C /tmp/baseline` then the same full-suite command in `/tmp/baseline` | **217 passed, 17 failed, 3 skipped** (baseline, unmodified code) |
| E-4 | `.venv/bin/bandit -r app -x app/static -f json` | 0 High, 33 Medium (all B608-style dynamic SQL — reviewed, see SQL review), 86 Low |
| E-5 | `.venv/bin/ruff check app --select S --statistics` | S110 ×53, S608 ×32, S311 ×23, S105 ×3 |
| E-6 | `.venv/bin/pip-audit` over `pyproject.toml` | 1 advisory: **PYSEC-2026-2860 / CVE-2025-26240** (pdfkit ≤ 1.0.0, High, no fix) |
| E-7 | Anonymous route probe (`TestClient`, all 190 routes) | output = the "Anon probe" column of `ENDPOINT_AUTHORIZATION_MATRIX.md` |
| E-8 | `node --check` on the four modified report scripts + `dom-escape.js` | syntax OK |
| E-9 | Node harness for `escapeHtml` | `<img …>` → `&lt;img src&#61;x onerror&#61;alert(1)&gt;`; `null`/`undefined` → `""` |
| E-10 | End-to-end `/download_pdf` with a stubbed `pdfkit` | temp file created → `from_file` called with `disable-javascript` + `disable-local-file-access` → temp file removed → 200 `application/pdf` |

## 2. New vs baseline failures (no test was deleted or weakened)

Baseline (unmodified `38ca85f`): 17 failures. After the hardening work: 12
failures. Five baseline failures were fixed by the remediation work; eight
pre-existing failures remain **and are reported as-is** — none of them is a
security control failure, and none was rewritten to pass.

| Test | Baseline | Now | Nature |
|---|---|---|---|
| `test_attendance.py::test_checkout_succeeds` | Fail | **Pass** | real regression in night-shift checkout, fixed |
| `::test_overnight_checkout_targets_active_record` | Fail | **Pass** | same fix |
| `::test_overnight_duplicate_checkin_rejected` | Fail | **Pass** | same fix |
| `::test_full_flow_reflects_in_status` | Fail | **Pass** | same fix |
| `test_security_regressions.py::TestWebSocketSecurity::test_websocket_requires_session` | Fail | **Pass** | WebSocket origin/session guard added |
| `test_dark_theme.py` ×8 | Fail | Fail | pre-existing UI/CSS-asset issues, untouched by the security work |
| `test_responsive_tables.py::test_every_table_has_a_mobile_pattern` | Fail | Fail | pre-existing UI issue |
| `test_user_panel_theme.py::test_user_panel_dark_mode_rules_cover_core_surfaces` | Fail | Fail | pre-existing UI issue |
| `test_ticketing_service.py::test_closed_tickets_can_only_be_reopened` | Fail | Fail | **test expects a transition matrix that the code has never had** (`resolved → closed` is allowed); triage pending, not a security control |
| `test_ticketing_service.py::test_actor_is_read_from_signed_session` | Fail | Fail | **test expects a 2-tuple**, code returns `(actor, is_admin, is_master_admin)`; the mismatch is the test's, not a security hole (the actor *is* read from the signed session) |

One incidental test-maintenance note: `test_security_regressions.py::TestAuthorization::test_download_pdf_requires_auth`
asserts that `_require_auth` appears within a 500-character window after
`def download_pdf(`. The hardening change first added a long docstring that
pushed the guard out of that window (the control was intact and measured by the
anonymous probe as 401). Rather than editing the test, the docstring was
converted into comments *after* the authorization guard, so the original,
unmodified assertion passes again.

## 3. Coverage added by this assessment

`tests/test_security_hardening.py` grew from 112 to 137 behavioural tests. The
new groups:

| Test class | What it proves |
|---|---|
| `TestEmployeeReportAuthorization` | `/get_hourly_pass_report` and `/get_overtime_report` refuse anonymous and non-admin callers |
| `TestCsrfExemptionIntegration` | the exemption list covers the bridge agent, captcha and the public form, is narrow, and authenticated mutations still need a token |
| `TestInternalErrorHandling` | the generic error helper no longer raises `NameError`; the request-owner table lookup is allow-listed |
| `TestPasswordRecovery` (extended) | a pending request answers exactly like a decoy id; the "expired" explanation requires the matching code |
| `TestStoredXssRendering` | the four report renderers escape DB values, the helper is loaded, and the two free-text write paths reject markup |
| `TestPdfGenerationHardening` | `pdfkit.from_string` is gone, `from_file` is used with JavaScript and local file access disabled, the temp file is removed, a missing template yields 503 |

## 4. What these tests do **not** prove

* No test exercises a live SQL Server, the Araz device, Caddy, or a real browser;
  those paths are covered by the manual checklists.
* Static-analysis output (Bandit/Ruff) is reported as *potential* findings; its
  High/Medium items were individually triaged in `SQL_INJECTION_REVIEW.md`.
* The `pip-audit` result covers the packages this assessment could resolve
  offline; the deployment host must repeat it after the final dependency freeze.
