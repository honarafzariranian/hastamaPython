# Parity test report

**Audit date:** 2026-10-03  
**Scope:** Python FastAPI/Jinja reference versus Laravel/Vue target  
**Overall result:** Build and targeted router checks pass; full application parity is **not verified**.

## Test environment and blocking limitations

| Capability | Result | Impact |
|---|---|---|
| Node / Vite | Available; production build completes. | Vue production compilation can be checked. |
| PHP / Laravel runtime | `php` executable is not installed in this environment. `laravel/vendor/autoload.php` is not available. | Cannot boot Laravel, run PHPUnit/Artisan, inspect live middleware behavior, issue requests to PHP routes, or connect the Laravel database. |
| Python application runtime | App/test dependencies are missing in this environment. | Cannot start FastAPI/Jinja, run the Python suite, seed matching reference data, or compare live responses. |
| Browser automation | No browser executable or Playwright module is available. | No screenshot, viewport, DOM/computed-style, click, keyboard, console, network, popup, print-dialog or physical printer comparison. |
| Data/services | No matched database/session seed, WebSocket session, Araz endpoint or printer is available. | Database side effects, realtime events, synchronization, role matrix and real printing remain untested. |

The missing tools are genuine blockers, not passing tests. Static source inspection and class-name scans cannot replace a browser/backend run. No page is marked `VERIFIED`.

## Commands run and results

| Command / check | Result | What it proves (and does not prove) |
|---|---|---|
| `cd laravel && npx vitest run resources/js/router/index.test.js` | **PASS — 6 tests** | Covers the Vue `/` redirect, confirms `/status` resolves to Vue not-found, checks master-admin root/section paths and slug, anonymous/admin redirect behavior, and standalone-shell route metadata. Does not start Laravel or test Laravel HTTP responses or render a page. |
| `cd laravel && npm run build` | **PASS** | Vue/Vite bundles compile. Does not validate server routing, API behavior or rendered appearance. |
| `git diff --check` | **PASS** | No whitespace errors in the code diff at validation time. |
| `python -m py_compile tools/design_parity.py tools/css_coverage.py` | **PASS** | Audit scripts are syntactically valid. |
| `python tools/design_parity.py --summary` | Completed: **482 missing class names** in aggregate. `user-panel.html`: 83; `admin.html`: 330; `master-admin.html`: 69. | Static class-name vocabulary comparison only. It is not a screenshot or proof of any rendered difference/equality. The unused control-centre health component and the invalid `label_print_document.html` → `TicketPrintPage.vue` pair (no target renderer route) are excluded. |
| `python tools/css_coverage.py` | Completed: five reported gaps. Four offline selectors (`lan`, `lan__off`, `lan__title`, `lan__url`) are absent from the superseded Vue duplicate; the fifth is Python's malformed `rel="stylesheet"` reference to `Vazir.woff2`. Ticket kiosk inline CSS: **166/166 covered**. | Static selector/link audit only. The offline result is a mapping false positive because Laravel serves its standalone document; the font reference is not a CSS stylesheet. |
| `python tools/api_paths.py` | **BLOCKED** — `FileNotFoundError: [Errno 2] No such file or directory: 'php'`. | Script requires PHP/Laravel runtime; no fresh live endpoint sweep was possible. |
| Python unit/integration test suite | **NOT RUN** — Python app/test dependencies are missing. | No Python request/response, DB or business-rule evidence. |
| Laravel test suite / PHPUnit | **NOT RUN** — PHP runtime unavailable. | No Laravel feature, middleware, database or controller response evidence. |
| Paired browser comparison | **NOT RUN** — browser/Playwright unavailable; neither application could be served in this environment. | No visual or interactive parity evidence. |

No acceptance is inferred from the static class counts. The active standalone Laravel documents for `/offline` and `/iran-only` are not represented by the scanner's Vue component mapping. `label_print_document.html` is excluded because no Laravel print-document route/renderer exists; `/ticket-print` is a form, not an equivalent document. The print API absence is established from route/controller source only; no PHP request was made. No Python application code under `app/` was changed; `tools/design_parity.py` changes only correct its comparison mapping.

## Per-page test coverage

The page identifiers correspond to the 25 rows in `PAGE_PARITY_MATRIX.md`. “Source audit” means the route/template/component mapping was inspected; it does not mean runtime behavior passed. All viewport and functional browser tests are outstanding unless noted.

| # | Python page / route | Laravel route / page | Evidence in this run | Status |
|---:|---|---|---|---|
| 1 | `GET /` | `GET /` → `/login` | Vue router redirect assertion passed and `/status` resolves to Vue not-found; Laravel HTTP redirect/status and unknown-path response were not run. | DIFFERENCES_FOUND |
| 2 | `GET /login` | `/login` → `LoginPage.vue` | Source and prior static design notes reviewed; no current browser/auth test. | AUDITED |
| 3 | `GET /register` | `/register` → `RegisterPage.vue` | Source/class coverage inspected; upload, validation and registration not run. | AUDITED |
| 4 | `GET /rules` | `/rules` → `RulesPage.vue` | Source/CSS order inspected; global theme-scope difference remains; no screenshot or click test. | DIFFERENCES_FOUND |
| 5 | `GET /training` | `/training` → `TrainingPage.vue` | Route/template/component inspected; no role/search/loading test. | AUDITED |
| 6 | `GET /training/{category}` | `/training/:category` → `TrainingPage.vue` | Dynamic route inspected; category/role/empty-state tests not run. | AUDITED |
| 7 | `GET /training/lesson/{lesson_id}` | `/training/lesson/:lessonId` → `TrainingLessonPage.vue` | Dynamic route inspected; access and missing-lesson tests not run. | AUDITED |
| 8 | `GET /user_panel` | `/user_panel` → user page components | Own-chrome router metadata covered; 83 missing legacy class names; modal/function/browser tests not run. | DIFFERENCES_FOUND |
| 9 | `GET /admin` | `GET /admin` → dashboard route | Redirect/guard source inspected; live user/admin redirects not run. | AUDITED |
| 10 | `GET /admin/dashboard`, `GET /admin/{section}` | `/admin/:section` → `AdminLayout.vue` and section pages | Stale unbound user handlers removed; static scan reports 330 missing names and incomplete section bodies. No live CRUD/data/role test. | DIFFERENCES_FOUND |
| 11 | `GET /master-admin` | `GET /master-admin` → dashboard section | Vue root target and anonymous/signed-in-admin guard tests pass; Laravel HTTP redirects were not run. | CORRECTED |
| 12 | `GET /master-admin/{section}` | `/master-admin/:section` → `ControlCentreLayout.vue` | Six router tests cover section paths/slug/shell and role redirects; audit-log, ticket and label-studio pages remain absent, 69 missing legacy class names. | DIFFERENCES_FOUND |
| 13 | `GET /call-management` | Controller + `/call-management` → `CallManagementPage.vue` | Own-chrome metadata covered; source mapping inspected; no guards/API/WebSocket/action test. | DIFFERENCES_FOUND |
| 14 | `GET /call-display` | `/call-display` → `CallDisplayPage.vue` | 40 Vitest checks replay the template DOM, the websocket frame protocol, queue/remove/reset/refresh, the audio queue and slideshow timers, and the page-scoped document identity; the live PHP route table, a real websocket and audio playback were not exercised. | TESTED |
| 15 | `GET /ticket-kiosk` | `/ticket-kiosk` → `TicketKioskPage.vue` | 166/166 inline CSS selectors covered; `POST /api/queue/print` and fallback `POST /api/label/print-document` have no registered target routes/controllers. No browser/API/printer run. | DIFFERENCES_FOUND |
| 16 | `GET /ticket-print` | `/ticket-print` → `TicketPrintPage.vue` | Python references missing `ticket-print.html`; target form calls missing `/api/label/config` and `/api/label/print-document` endpoints. No render comparison. | DIFFERENCES_FOUND |
| 17 | `POST /api/label/print-document` and `partials/label_queue.html` | No Laravel route/controller | Python response/template inspected; target route is absent from source; request, payload, generated HTML and print behavior not executed. | DIFFERENCES_FOUND |
| 18 | `GET /leave_report_page` | Report controller / `LegacyReportPage` | Template/source mapping and known producer localStorage gap reviewed; no populated data/print test. | DIFFERENCES_FOUND |
| 19 | `GET /hourlypass_Report_page` | Report controller / `LegacyReportPage` | Template/source mapping and known producer localStorage gap reviewed; no data/print test. | DIFFERENCES_FOUND |
| 20 | `GET /overtime_report_page` | Report controller / `LegacyReportPage` | Template/source mapping and known producer localStorage gap reviewed; no data/print test. | DIFFERENCES_FOUND |
| 21 | `GET /overtime_report` | `OvertimeController::report` | Both missing view references found by source inspection; live 500 response not tested. | AUDITED |
| 22 | `GET /payroll_report_page` | Report controller / `LegacyReportPage` | Template/source mapping reviewed; no DB totals, populated rows or print comparison. | DIFFERENCES_FOUND |
| 23 | `GET /final_report_page` | Report controller / `LegacyReportPage` | Template/title and missing PDF-source template inspected; no date/data/PDF/print test. | DIFFERENCES_FOUND |
| 24 | `GET /offline` | `OfflineController` standalone document | Source mapping reviewed; four CSS-scan gaps apply to an unused Vue duplicate; no offline/LAN/retry test. | AUDITED |
| 25 | `GET /iran-only` | `IranOnlyController` standalone document | Source mapping reviewed; no IP classification, polling or return-state test. | AUDITED |

## Required viewport coverage

The agreed viewport set is **1920×1080, 1600×900, 1440×900, 1366×768, 1280×720, 1024×768, and 768×1024**. No browser captures were made. Each remains `NOT_STARTED` for both implementations:

| Viewport | Python | Laravel/Vue | Side-by-side pixel/DOM comparison |
|---|---|---|---|
| 1920 × 1080 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1600 × 900 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1440 × 900 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1366 × 768 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1280 × 720 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1024 × 768 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 768 × 1024 | NOT_STARTED | NOT_STARTED | NOT_STARTED |

## Interaction and integration coverage

None of these could be executed against both live applications in this environment:

- Authentication, role redirects, authorization failures, session expiry and recovery.
- Registration validation, upload failure, pending/approved/rejected user states.
- Admin/master-admin CRUD, filtering, pagination, confirmations, empty states and error messages.
- Forms and database side effects for attendance, leave, hourly passes, overtime, payroll and tickets.
- Notification/unread/realtime behavior and SSE/WebSocket reconnect/error states.
- Araz synchronization, card/date edge cases and resulting database updates.
- Queue numbering/current-next/repeat/remove/reset behavior.
- Date/time boundaries, Jalali values, Persian digits, totals and report data handoff.
- Popup blocking, print fallback, printer settings, label geometry and physical output.
- Browser console/network errors, broken assets, caching/service worker behavior and actual HTTP status codes.

## Exit criteria still unmet

To move any page to `VERIFIED`, run Python and Laravel with equivalent configuration/data; compare its real routes, auth roles, responses and side effects; exercise normal, empty, invalid, loading, error and permission states; capture both pages at all seven viewports; compare screenshots/computed layout and browser console/network behavior; then attach the page-specific evidence. A build or class-name scan alone is not sufficient.
