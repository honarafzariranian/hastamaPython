# Visual difference report — Python reference vs Laravel/Vue

**Audit date:** 2026-10-03  
**Reference CSS/assets:** `app/static/` + `app/templates/`  
**Target CSS/assets:** `laravel/resources/css/legacy/`, `laravel/public/`, Vue SFCs

## Evidence and limitations

The source-level checks were run in this checkout with `tools/design_parity.py --summary` and `tools/css_coverage.py`. They compare class vocabulary and CSS selector availability; they do **not** establish pixel parity, element nesting, computed styles, browser font loading, interaction states or responsive breakpoints. The current container has no PHP runtime, no installed Python web/test dependencies, and no browser binary, so this session could not capture paired screenshots or computed styles. Historical measurements are labeled as historical and were not treated as fresh verification.

### Current structural class scan

The parity scanner excludes the now-unrendered control-centre `HealthPage.vue` and no longer pairs the Python generated print document with the Vue label form. Results after those mapping corrections:

| Python template | Missing legacy classes | Other scan results | Page-level visual status |
|---|---:|---|---|
| `login.html` | 0 | 39 Vue-only names | AUDITED |
| `register.html` | 0 | 14 Vue-only names | AUDITED |
| `rules.html` | 0 | 4 composed, 10 Vue-only names | DIFFERENCES_FOUND — stylesheet scope differs |
| `training.html` | 0 | 4 composed, 2 adjudicated, 19 Vue-only names | AUDITED |
| `training-lesson.html` | 0 | 1 adjudicated, 4 Vue-only names | AUDITED |
| `offline.html` | 0 | 3 adjudicated, 1 Vue-only name | AUDITED — scan targets a superseded Vue duplicate, not the served Laravel document |
| `vpn-warning.html` | 0 | 1 Vue-only name | AUDITED — Laravel's primary route serves a standalone document |
| `user-panel.html` | **83** | 61 Vue-only names | DIFFERENCES_FOUND |
| `admin.html` | **330** | 144 Vue-only names | DIFFERENCES_FOUND |
| `master-admin.html` | **69** | 87 Vue-only names | DIFFERENCES_FOUND |
| `call-management.html` | 0 | 66 Vue-only names | DIFFERENCES_FOUND — full-screen wrapper was corrected; browser/cascade still unverified |
| `call-display.html` | 0 | 1 composed, 10 Vue-only names | CORRECTED — dark-theme leak detached per page, DOM compared against the template in Vitest |
| `ticket-kiosk.html` | 0 | 1 adjudicated, 48 Vue-only names | AUDITED — inline CSS coverage is complete; browser comparison not rerun |

**Total reported missing class names: 482.** Counts are unique class-name vocabulary results; they do not mean that many pixels/elements differ. Conversely, zero missing names does not prove visual equality.

The 69 control-centre missing-name count includes 15 Jinja artifacts identified by the existing report as scanner false positives; the actual profile/detail/label surfaces still have gaps. The current tool does not model Laravel's standalone document responses, so its `offline` and `vpn-warning` Vue duplicates must not be treated as rendered-page evidence. `label_print_document.html` is now excluded from the class-pair scan: Laravel has no generated-document route/renderer in this checkout, and `TicketPrintPage.vue` is an editor form, not that output.

## Difference log

| Page | Element | Python appearance | Laravel appearance | Difference | Root cause | Required correction | Status |
|---|---|---|---|---|---|---|---|
| `/user_panel` | Application chrome | The Python document owns its `.app-shell`, topbar and sidebar; no separate global application header or max-width wrapper. | `AppLayout.vue` previously added `.h-header` and `.h-main` around the legacy panel. | Extra header and constrained content area. | Route was not marked as owning its full document chrome. | Added `meta.ownChrome: true` to `/user_panel`, so `AppLayout` renders the page directly. Recheck screenshot at all required viewports. | CORRECTED |
| `/master-admin/{section}` | Application chrome | `master-admin.html` owns the topbar, two rails and full-width content. | The generic application shell previously added a second header and max-width column. | Added chrome and narrowed panel. | Route lacked `ownChrome`. | Added `meta.ownChrome: true`; verify actual computed layout and screenshots. | CORRECTED |
| `/call-management` | Application chrome | `call-management.html` is a standalone full-screen document. | The route previously used the generic shell because it was role-gated but not marked public/own-chrome. | Extra application header and constrained main area. | Route metadata did not state that this standalone page owns its chrome. | Added `meta.ownChrome: true`; browser screenshot remains outstanding. | CORRECTED |
| `/admin/*` | Admin section bodies | `admin.html` contains the actual legacy bodies and classes for dashboard, coworkers, leave, overtime, hourly pass, tickets, shifts, attendance and payroll. | Only a subset of section bodies uses legacy markup; tickets, attendance and internal automation have no Vue body, and the present sections use many Vue-only classes. | 330 legacy classes are absent from the current Vue page set. | Section bodies were rebuilt rather than ported; some sections remain absent. | Port each body from its Jinja structure and legacy classes; retain the existing business/API behavior. | DIFFERENCES_FOUND |
| `/user_panel` | Modal and popup surfaces | The Python page contains profile, security, support/ticket, automation, notification, attendance and request modals/panels. | The current Vue page set does not reproduce all legacy modal surfaces and states. | 83 legacy classes absent, primarily entire modal/overlay groups. | Panel sections were split into Vue views while some old modal workflows were omitted. | Restore each source modal and its open/close/focus/validation states; preserve legacy class names and CSS. | DIFFERENCES_FOUND |
| `/master-admin/*` | Profile dropdown and label studio | Python includes `pd-*` profile menu/panels and the full label studio with its exact control/preview classes. | Vue control centre lacks corresponding complete surfaces; its own class vocabulary is still present. | 69 legacy class names missing; the profile panel and label studio do not visually match. | Rebuilt section set omits surfaces instead of porting the source markup. | Port the existing legacy structures and stylesheet selectors; do not substitute a new design. | DIFFERENCES_FOUND |
| `/master-admin/*` | Sidebar entries | Python has audit logs and tickets in the right rail; subscriptions, call pages, label studio and kiosk in the left rail. | Vue's earlier nav misplaced/duplicated subscriptions and added a health menu entry not present in the Python template; audit logs/tickets/label studio are not implemented as Vue pages. | Extra menu item/duplicate plus missing legacy surfaces. | Navigation was based on the migrated component set, not on the source item order/rail grouping. | This pass removed the health entry and duplicate right-rail subscriptions item, moved subscriptions back to the separate left rail, and restored the `system-settings` path slug. Audit logs, tickets and label studio remain open. | DIFFERENCES_FOUND |
| `/rules` | Body background, text and dark surfaces | `rules.html` loads `vazir.css` and `rules.css`, not `dark-theme.css`; its own `body.dark-mode` variables control the rules palette. | `app.css` imports `dark-theme.css` globally and after `rules.css`; shared `body.dark-mode` background/color rules apply. | Global dark body colors/background can override the rules page's original page-specific dark surfaces. | All legacy stylesheets are bundled globally instead of being loaded per route. | Move the shared theme layer to the exact routes that include it in Python, or otherwise ensure the rules page uses only its source cascade. Compare light/dark screenshots. | DIFFERENCES_FOUND |
| `/call-display` | Full-screen background, body color and theme | `call-display.html` loads only `vazir.css` and `call-display.css`; its dark-blue TV background is fixed by `--cd-bg-deep`. | The SPA bundle also loads global `dark-theme.css`, even though Python does not. | In dark mode the global `body.dark-mode` background/color rules compete with the display's fixed body styles. | Global stylesheet import and global theme boot script affect every Vue route. | Made theme/CSS page-scoped: the Vue page removes `dark-mode`/`dark-theme` from `<html>`+`<body>` and pins `data-theme`/`color-scheme` to light while it is mounted (restored on unmount), re-applies its own `<style>` over the bundle (`html/body:has(.cd-slideshow)`), and continues the `html, body` flex column through `#app:has(.cd-slideshow)` so `.cd-content { flex: 1 }`, the centered hero and the bottom footer land where the Python document puts them. | CORRECTED |
| `/training`, `/training/lesson/*`, `/call-management`, `/ticket-kiosk` | Theme and page backgrounds | Python templates do not link `dark-theme.css`; some pages define their own dark behavior and others are fixed-color documents. | All SPA pages share the globally imported dark-theme stylesheet and theme initialization. | The target can apply shared dark backgrounds, input colors or overlays on pages that Python did not theme with that stylesheet. | `resources/css/app.css` bundles all page styles and the shared theme globally. | Build per-route stylesheet/theme loading from each template's actual `<head>`; compare both light and dark at each viewport. | DIFFERENCES_FOUND |
| `/call-management` | Local font request | Python links `Vazir.woff2` using `rel="stylesheet"` and also declares it in an inline `@font-face`; this is a malformed stylesheet link in the reference. | Target uses the local `vazir.css`/font assets and does not issue that malformed stylesheet request. | Network activity differs; visual font output has not been proven different. | The reference template labels a font binary as a stylesheet. | Keep the valid local font declaration; do not add a fake CSS request. Record the reference defect and verify actual computed font in a browser. | AUDITED |
| `/offline` | Conditional LAN block classes | The served Python document contains the `lan`, `lan__off`, `lan__title` and `lan__url` styling/markup when LAN access is enabled. | Laravel's actual route serves `OfflineController`'s standalone document; a legacy Vue `OfflinePage.vue` duplicate is superseded and lacks those classes. | The structural checker reports 4 missing selectors against the unused component, not against the active Laravel document. | Static pair map points at a dead Vue duplicate. | Update the audit mapping to compare the served document, then test enabled/disabled LAN state. | AUDITED |
| `/login` | Username input, login button, card and theme colors | Prior `DESIGN_PARITY.md` records the corrected 1200×800 values: username 374×48.39 px; button 374×49 px; card 555.34 px tall in dark mode. | The prior pass reported equal values after narrowing global selector leaks and adding the login cascade bridge. | Historical evidence says values matched, but this session did not recapture them or test other sizes. | CSS in the global bundle previously leaked admin/report selectors; a page-scoped bridge corrected the measured declarations. | Recapture Python and Laravel at the same data, theme and viewport; then compare all seven required viewport sizes, focus, error and loading states. | AUDITED |
| Report documents | Report content/table/print surfaces | Jinja report templates read values written to localStorage by the Python admin UI; standalone document HTML includes legacy report CSS/JS. | Laravel serves a source-derived standalone document, but the Vue admin producer does not populate every legacy key used by those report scripts. | An empty/default report can look structurally correct while real rows/totals are absent. | The document was ported separately from its producer workflow. | Port the producer-to-report data flow and compare populated, empty, error and print states. | DIFFERENCES_FOUND |
| `/ticket-print` | Label form vs print document | The Python route references a missing `ticket-print.html`; a separate API returns generated `label_print_document.html`. | Laravel renders a Vue form at `/ticket-print`, but its `/api/label/config`, `/api/label/print-document` and `/api/queue/print` callers have no registered backend route in this checkout. | There is no renderable Python page to compare with the Laravel form, and there is no target-generated print document to capture. | Missing reference template plus missing target print handlers; a Vue form is not the API document. | Add the equivalent Laravel endpoints/renderer and compare generated output separately from the page route; keep the missing Python-template limitation explicit. | DIFFERENCES_FOUND |

## CSS coverage scan

`python tools/css_coverage.py` reports five issues:

1. Four class selectors on `offline.html` (`lan`, `lan__off`, `lan__title`, `lan__url`) are absent from the superseded `OfflinePage.vue`. The active Laravel route is a standalone server-generated document, so this is a mapping false positive, not proof of a rendered-page omission.
2. `call-management.html` has a `rel="stylesheet"` link to `Vazir.woff2`. It is not a CSS file. The same template declares a correct inline `@font-face`, and Laravel loads the bundled local Vazir font. This is a network-request difference in a malformed reference tag, not evidence that the target should request a font as CSS.

The scan confirms 29/29 register inline classes and 166/166 kiosk inline classes are covered. It does not test computed CSS or pixel output.

## Required viewport screenshots — not captured

| Viewport | Python capture | Laravel capture | Status |
|---|---|---|---|
| 1920 × 1080 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1600 × 900 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1440 × 900 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1366 × 768 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1280 × 720 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 1024 × 768 | NOT_STARTED | NOT_STARTED | NOT_STARTED |
| 768 × 1024 | NOT_STARTED | NOT_STARTED | NOT_STARTED |

No pixel-diff score is available. No page is visually verified in this report.

## Page-by-page visual coverage

This table covers every page in `PAGE_PARITY_MATRIX.md`. A source/class audit is not a screenshot comparison. `NOT_STARTED` means the side-by-side rendered comparison is still required; known source/CSS differences are labeled `DIFFERENCES_FOUND`.

| # | Python page/route | Laravel target | Visual evidence / known item | Status |
|---:|---|---|---|---|
| 1 | `/` redirect | Vue `/` redirect | No document rendered; browser/network redirect chain not captured. | NOT_STARTED |
| 2 | `/login` | `LoginPage.vue` | Historical 1200×800 geometry is recorded in prior design notes; no current captures, remaining six sizes or state screenshots. | AUDITED |
| 3 | `/register` | `RegisterPage.vue` | 29/29 inline classes covered; no current rendered comparison. | AUDITED |
| 4 | `/rules` | `RulesPage.vue` | Global `dark-theme.css` is active in Vue but absent from Python template. | DIFFERENCES_FOUND |
| 5 | `/training` | `TrainingPage.vue` | Class vocabulary covered; shared-theme cascade and all rendered states unverified. | AUDITED |
| 6 | `/training/{category}` | `TrainingPage.vue` category state | Class vocabulary covered; category-specific/empty/denied layouts not captured. | AUDITED |
| 7 | `/training/lesson/{lesson_id}` | `TrainingLessonPage.vue` | Class vocabulary covered; shared-theme cascade and missing/denied states not captured. | AUDITED |
| 8 | `/user_panel` | User panel layout/pages | 83 missing source class names; own-chrome wrapper correction made, but panel/modal geometry remains different. | DIFFERENCES_FOUND |
| 9 | `/admin` redirect | Admin redirect | No page rendered; no browser redirect capture. | NOT_STARTED |
| 10 | `/admin/dashboard`, `/admin/{section}` | `AdminLayout.vue` sections | 330 missing source class names; multiple bodies absent/incomplete. | DIFFERENCES_FOUND |
| 11 | `/master-admin` redirect | Master-admin root redirect | Route/shell change tested only in Vue Router; page-level browser render not captured. | NOT_STARTED |
| 12 | `/master-admin/{section}` | `ControlCentreLayout.vue` | 69 missing source class names; own-chrome/navigation corrections made; missing profile/label/audit/ticket surfaces remain. | DIFFERENCES_FOUND |
| 13 | `/call-management` | `CallManagementPage.vue` | Own-chrome correction made; font request noted; no rendered comparison. | DIFFERENCES_FOUND |
| 14 | `/call-display` | `CallDisplayPage.vue` | Dark-theme leak detached per page, `#app` flex chain restored, Python title/favicon/body class applied; 40 Vitest parity checks compare the mounted DOM with `call-display.html`. No paired browser screenshot (no PHP/browser in this container). | CORRECTED |
| 15 | `/ticket-kiosk` | `TicketKioskPage.vue` | 166/166 inline selectors covered; prior CSS fix noted; no current screenshot or responsive comparison. | AUDITED |
| 16 | `/ticket-print` | `TicketPrintPage.vue` | Python template is missing; target form has no rendered Python page equivalent. | DIFFERENCES_FOUND |
| 17 | `/api/label/print-document` | No Laravel response route/controller | Python response exists; target print endpoint is missing in source, so no generated output can be visually compared. | DIFFERENCES_FOUND |
| 18 | `/leave_report_page` | Leave report document | Prior source-text comparison exists; data-filled table, page breaks and print geometry not captured. | DIFFERENCES_FOUND |
| 19 | `/hourlypass_Report_page` | Hourly-pass report document | Prior source-text comparison exists; data-filled table, page breaks and print geometry not captured. | DIFFERENCES_FOUND |
| 20 | `/overtime_report_page` | Overtime report document | Prior source-text comparison exists; data-filled table, page breaks and print geometry not captured. | DIFFERENCES_FOUND |
| 21 | `/overtime_report` | Broken Python/Laravel view endpoint | Python template is absent, so no visual page can be rendered from this checkout. | AUDITED |
| 22 | `/payroll_report_page` | Payroll report document | Source mapping reviewed; populated table and print geometry not captured. | DIFFERENCES_FOUND |
| 23 | `/final_report_page` | Final report document | Prior source-text/title notes exist; dynamic date, populated report and PDF/print visuals not captured. | DIFFERENCES_FOUND |
| 24 | `/offline` | Laravel standalone offline document | Scanner's 4 missing selectors refer to an unused Vue duplicate, not the active document; no current outage-state render. | AUDITED |
| 25 | `/iran-only` | Laravel standalone VPN warning document | Prior text comparison noted; no screenshot at any required viewport or denied/polling state. | AUDITED |

The viewport table above applies to every row here. `NOT_STARTED` does not mean a page failed a visual test; it means no browser comparison was possible. `DIFFERENCES_FOUND` rows contain an identified source/CSS discrepancy or missing visual surface that remains unresolved.
