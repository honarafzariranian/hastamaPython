# JavaScript inventory — vanilla JS → Vue 3

All 28 modules under `app/static/js` (the vendored `html2pdf.bundle.min.js` is excluded).
Every module is loaded with a plain `<script src>` tag — **there is no bundler and no
`package.json` anywhere in the project**, so Vite + Vitest are genuinely new infrastructure.

Critical rule from the brief: this logic becomes components, composables, Pinia stores,
API services and utilities. **It is not copied verbatim into Vue.**

| Module | Lines | `fetch()` | DOM ops | WS | SSE | Purpose | Target | Complexity |
|---|---:|---:|---:|:-:|:-:|---|---|---|
| `admin.js` | 6,086 | 38 | 778 | — | — | Admin panel: every section, forms, tables, pagination, reports. | AdminPanel components + Pinia stores | High |
| `user-panel-script.js` | 2,590 | 22 | 345 | — | — | User panel: presence ring, requests, history, profile, tickets. | UserPanel components + stores | High |
| `master-admin.js` | 2,225 | 1 | 245 | — | — | Control centre: builds all 15+ sections from `/master-admin/api/*`. | ControlCentre pages + services | High |
| `responsive-tables.js` | 1,303 | 0 | 92 | — | — | Turns every wide table into a mobile pattern. | Table component / CSS only | Medium |
| `call-system-standalone.js` | 1,259 | 25 | 159 | yes | — | Reception call desk: queues, calls, slides, WebSocket. | CallManagement + WebSocket composable | High |
| `final-report-print.js` | 662 | 0 | 36 | — | — | Print layout for the final report. | Print stylesheet + component | Medium |
| `admin-mobile.js` | 599 | 0 | 70 | — | — | Mobile admin drawer, profile menu, tab bar. | Layout components | Medium |
| `call-display.js` | 557 | 3 | 52 | yes | — | TV display: queue rendering + WebSocket + audio. | CallDisplay.vue + composable | High |
| `label-system.js` | 551 | 2 | 13 | — | — | Label queue and print submission. | Printing service + LabelQueue | Medium |
| `ticketing.js` | 491 | 3 | 43 | — | — | Ticket list/detail/reply/attachment UI. | Ticketing pages + service | Medium |
| `script.js` | 476 | 3 | 33 | — | — | Landing/login page behaviour and form submit. | LoginPage.vue | Medium |
| `final-report-script.js` | 475 | 2 | 50 | — | — | Final report data fetch and rendering. | FinalReport.vue | Medium |
| `notification-system.js` | 441 | 1 | 132 | — | yes | Notification bell, list, SSE stream. | useNotifications composable + store | High |
| `hastama-ux.js` | 360 | 1 | 28 | — | — | Shared UX polish: ripples, transitions, scroll. | Shared components / composables | Medium |
| `internal-automation-admin.js` | 242 | 8 | 48 | — | — | Automation administration. | AutomationAdmin page | Medium |
| `internal-automation.js` | 185 | 2 | 19 | — | — | Internal automation conversation UI (user side). | Automation page + store | Medium |
| `theme.js` | 182 | 0 | 11 | — | — | Dark/light theme toggle and persistence. | useTheme composable | Medium |
| `csrf-bootstrap.js` | 171 | 2 | 2 | — | — | Fetches `/api/csrf-token` and patches `fetch` to send `X-CSRF-Token`. | Laravel CSRF cookie + axios interceptor | High |
| `training.js` | 158 | 1 | 22 | — | — | Training hub search and navigation. | Training pages | Low |
| `toast.js` | 129 | 0 | 14 | — | — | Toast notifications. | useToast composable | Low |
| `offline-guard.js` | 113 | 0 | 12 | — | — | Detects offline state and shows the offline notice. | useOffline composable | Low |
| `overtime-report-script.js` | 98 | 1 | 19 | — | — | Overtime report fetch and render. | OvertimeReport.vue | Low |
| `leave-report-script.js` | 86 | 1 | 24 | — | — | Leave report fetch and render. | LeaveReport.vue | Low |
| `rules.js` | 85 | 0 | 16 | — | — | Rules page interactions. | RulesPage.vue | Low |
| `hourlypass-report-script.js` | 83 | 1 | 19 | — | — | Hourly-pass report fetch and render. | HourlyPassReport.vue | Low |
| `payroll-report-preview.js` | 76 | 1 | 13 | — | — | Payroll calculation preview. | PayrollReport.vue | Low |
| `dom-escape.js` | 40 | 0 | 1 | — | — | `escapeHtml` — the only XSS escape used by client-built DOM. | `utils/escape.js` | High |
| `number-format.js` | 12 | 0 | 0 | — | — | Persian numeral conversion (`to_persian_numbers`). | `utils/numbers.js` | High |

## Real-time inventory

* **WebSocket:** `call-system-standalone.js` and `call-display.js` connect to
  `/api/ws/call-display`. Client protocol: an optional first frame
  `{"tag":"display"|"preview"}`, then `"ping"`/`{"type":"pong"}` keepalive; inbound
  server frames include `queue_ticket_taken`, call events, display reset/refresh and slide
  changes. The client may send `{"type":"audio_activated"}`, which is the **only** inbound
  frame the server re-broadcasts.
* **SSE:** `notification-system.js` opens `EventSource` on `/api/notifications/stream`
  (and the admin stream). Polling fallbacks exist server-side
  (`/api/notifications/poll`, `/unread-count`).
* **Printing:** `final-report-print.js` and `label-system.js` drive the print pipeline; the
  actual rendering/printing happens server-side on Windows (see MIGRATION_AUDIT.md §20).
* **CSRF:** `csrf-bootstrap.js` is replaced by Laravel's `XSRF-TOKEN` cookie plus an axios
  interceptor — the same idea, one fewer moving part.

## DOM-operation census

Call counts are the number of **call sites** in each module (a `querySelector` inside a loop is
one call site). 'Modules using it' shows how widespread the pattern is.

| Operation | Call sites | Modules using it |
|---|---:|---:|
| `getElementById` | 782 | 21 |
| `querySelector` | 451 | 19 |
| `addEventListener` | 360 | 26 |
| `classList` | 338 | 22 |
| `createElement` | 183 | 22 |
| `innerHTML` | 173 | 22 |
| `querySelectorAll` | 157 | 18 |
| `insertAdjacentHTML` | 3 | 2 |

**Total DOM call sites across all modules: 2,296**,
plus everything inline inside the 20 templates (never extracted separately). That total is the
real size of the template/JS → Vue rewrite in lines-of-behaviour terms.

