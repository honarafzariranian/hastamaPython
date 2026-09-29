# OFFLINE_AUDIT_REPORT.md

**Project:** Hestama (سامانه هستما) — FastAPI / Jinja2 / SQL Server
**Date:** 2026-08-23
**Objective:** Convert the Internet-dependent app into a fully self-contained offline app.

---

## Summary of Stack

The task prompt assumed a Laravel/PHP project, but Hestama is actually a **Python FastAPI**
application using **Jinja2 templates**, **local SQL Server (pyodbc)**, and a **local MS Access**
attendance source. All audit/action steps were adapted to the real stack. No Laravel, Vite,
composer.json, or package-lock.json exists — those code paths did not apply.

---

## 1. External Dependencies Found

| # | Location | Type | Description |
|---|---|---|---|
| 1 | `app/templates/admin.html` | CDN script | persian-date 1.1.0 from `cdn.jsdelivr.net` |
| 2 | `app/templates/final_report_page.html` | CDN scripts | moment 2.29.4 + moment-jalaali 0.9.2 from `cdn.jsdelivr.net` |

**False positives ruled out:**
- `xmlns="http://www.w3.org/2000/svg"` in inline SVGs — XML namespace, **no network request**.
- Every `fetch()`/`XMLHttpRequest` in JS is a **relative** internal API route — local, offline.
- `background-image`/`url()` in CSS all point to local `/static/...`. No Google Fonts.

---

## 2. External Dependencies Removed

| Library | Reason | Action |
|---|---|---|
| moment 2.29.4 | Unused (no JS references it) | Removed `<script>` from `final_report_page.html` |
| moment-jalaali 0.9.2 | Unused (no JS references it) | Removed `<script>` from `final_report_page.html` |
| persian-date 1.1.0 (CDN) | Used by admin shift report | Vendored locally (below), CDN tag replaced |

---

## 3. Local Dependencies Added

| Library | Version | Local file |
|---|---|---|
| persian-date | 1.1.0 (unchanged, CDN version) | `app/static/vendor/persian-date/persian-date.min.js` |

Reference updated to:
```html
<script src="{{ url_for('static', path='vendor/persian-date/persian-date.min.js') }}"></script>
```
Verified via Node (vm sandbox): UMD exposes `window.persianDate` and `new persianDate([1403,1,5]).format('dddd')` → `یکشنبه` (correct).

---

## 4. Network Requests Found (source of truth)

Classified all frontend network usage:

| Request | Type | Target | Required? |
|---|---|---|---|
| `fetch('/login_user', ...)` | API | local | Core |
| `fetch('/get_hozoor/...')`, `/get_hozoor_today`, `/sabt_hozoor_checkin/out` | API | local | Core |
| `fetch('/get_leave_requests')`, `/submit_leave`, `/update_leave_status` | API | local | Core |
| `fetch('/get_overtime_requests')`, `/submit_overtime`, `/update_overtime_status` | API | local | Core |
| `fetch('/get_hourly_pass_requests')`, `/submit_hourly_pass` | API | local | Core |
| `fetch('/api/tickets', ...)`, `/api/tickets/{id}...`, `/update_ticket_status` | API | local | Core |
| `fetch('/logout')`, `/get_receivers`, `/get_user_info`, `/api/date`, `/get_today_date` | API | local | Core |
| Notification `fetch(url, config)` — all relative internal routes | API | local | Core |

**Backend outbound:** none. No `requests.*`, `urllib`, `httpx`, `aiohttp`, `smtplib`, `socket`,
webhook, or external SDK usage in `app/`, `core/`, `ml/`.

**Result:** All network requests are **internal/local**. `External Network Requests = 0`.

---

## 5. Network Requests Removed

- CDN `<script src="https://cdn.jsdelivr.net/npm/persian-date/...">` → local.
- CDN `<script>` tags for moment + moment-jalaali → removed (unused).

---

## 6. Optional External Services

**None.** There is no SMS, email, push, payment, map, CAPTCHA, analytics, telemetry, or third-party
API in the codebase. Nothing to isolate behind an opt-in flag; the core is already fully local.

---

## 7. Remaining External URLs

| URL | Location | Status |
|---|---|---|
| `http://www.w3.org/2000/svg` | Inline SVG `xmlns` attrs | **Namespace only, no request — safe.** |

These are XML namespace identifiers, not fetched resources, and are required by the SVG spec.

---

## 8. Test Results

- **persian-date UMD load:** PASS (Node vm → `window.persianDate` function; correct weekday output).
- **JS syntax check (`node --check`) on all `app/static/js/*.js` + vendored persian-date:** PASS (12/12).
- **Full pytest suite:** *Not run* — `.venv` is locked (permission error on `Scripts`) and the
  app's module-level SQL Server connection requires a live `localhost\SQLEXPRESS`. The changes
  made (template script-tag references + one added static asset) touch no Python logic, so the
  Python unit tests are unaffected. Frontend DOM suites need `tests/js` npm deps not installed.

---

## 9. Known Limitations / Notes

1. Full browser test with the network adapter disabled could not be executed in this environment
   (no running SQL Server / locked venv). Based on static audit, **no external domains remain**
   and the app should run fully offline once installed on a machine with SQL Server + MS Access.
2. The MS Access attendance source path is hard-coded (`E:\Hastama\database\Arazdb.mdb`) — local,
   offline, but environment-specific.
3. `requests` is a declared dependency but unused for outbound calls; harmless offline.
4. `aws` optional extra (mangum) exists for Lambda deployment — unrelated to offline runtime.
5. If the vendored persian-date library is ever upgraded, replace `app/static/vendor/persian-date/persian-date.min.js`
   and keep the template reference to the local path.

---

## 10. Recommended Future Improvements

- Centralize the SQL Server connection string (module-level `conn`) into a connection helper to
  improve consistency and startup behavior.
- Parameterize the MS Access path via config/.env instead of a hard-coded absolute path.
- Add an integration test that asserts no `http(s)://` appears in `src`/`href`/`url()` of the
  rendered HTML.
- If offline PDF rendering via `pdfkit` requires a system `wkhtmltopdf` binary, document it in
  the deployment checklist (system dependency, not network).

---

## 11. Files Changed

| File | Change |
|---|---|
| `app/templates/admin.html` | persian-date CDN → local `/static/vendor/persian-date/persian-date.min.js` |
| `app/templates/final_report_page.html` | Removed unused moment + moment-jalaali CDN scripts |
| `app/static/vendor/persian-date/persian-date.min.js` | **Added** — local persian-date 1.1.0 |
| `OFFLINE_DEPENDENCIES.md` | **Added** — dependency catalogue |
| `OFFLINE_AUDIT_REPORT.md` | **Added** — this report |

---

## 12. Final Offline Result

> **OFFLINE WITH OPTIONAL EXTERNAL INTEGRATIONS**

All core runtime dependencies are local and no external domain is referenced anywhere. The
classification above (rather than `FULLY OFFLINE`) is chosen because, per the task's own
verification rule, a true full offline verdict requires actually running the app with the network
disabled against a live SQL Server — which could not be performed in this environment. Static
audit strongly indicates zero external requests for all core features (auth, dashboard, users,
attendance, leave, overtime, tickets, notifications, admin, reports, charts, fonts, icons,
images, CSS, JS all local).
