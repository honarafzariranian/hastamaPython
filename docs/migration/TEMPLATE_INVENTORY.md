# Template inventory — Jinja2 → Vue 3

All 20 templates under `app/templates`. There is **no base template and no `{% extends %}`**:
every page carries its own document head, which is why CSS is duplicated per page.

| Template | Lines | Purpose | Auth | Target Vue | Forms | Ids | Scripts | CSS | API refs |
|---|---:|---|---|---|---:|---:|---:|---:|---:|
| `app/templates/ticket-kiosk.html` | 3,499 | Numbered-ticket kiosk with patient PII capture. | Public page (kiosk) | TicketKiosk.vue | 0 | 52 | 2 | 1 | 2 |
| `app/templates/admin.html` | 3,091 | Admin panel shell and all its sections. | Admin | AdminPanel layout + pages | 9 | 294 | 1 | 0 | 2 |
| `app/templates/user-panel.html` | 1,336 | User panel shell and all its sections. | Authenticated user | UserPanel layout + pages | 8 | 167 | 1 | 0 | 1 |
| `app/templates/register.html` | 749 | Self-registration form (username / national id availability checks) with an attached document. | Public | RegisterPage.vue | 1 | 25 | 1 | 0 | 2 |
| `app/templates/master-admin.html` | 686 | Master-admin control-centre shell; every section is built client-side. | Master admin | ControlCentre layout + pages | 0 | 69 | 1 | 0 | 14 |
| `app/templates/login.html` | 645 | Public login page: credentials + optional CAPTCHA, loader, offline notice. | Public | LoginPage.vue | 4 | 45 | 1 | 0 | 1 |
| `app/templates/rules.html` | 502 | Laboratory rules content page. | Public | RulesPage.vue | 0 | 14 | 1 | 0 | 1 |
| `app/templates/call-management.html` | 500 | Reception call-management desk. | Master-admin page guard + guarded APIs | `CallPageController` streams the Python document; Vue route redirects | 0 | 57 | 5 | 3 | 0 |
| `app/templates/final_report_page.html` | 267 | Final attendance/report page with print support. | Admin | FinalReport.vue | 0 | 22 | 1 | 0 | 0 |
| `app/templates/offline.html` | 202 | Offline fallback served by `sw.js` when the workstation has no network. | Public | OfflineView.vue | 0 | 6 | 0 | 0 | 0 |
| `app/templates/training.html` | 195 | Training hub: categories and lessons index. | Public | TrainingHub.vue | 0 | 3 | 1 | 0 | 1 |
| `app/templates/vpn-warning.html` | 180 | The Iran-only access-policy page shown to a blocked address. | Public (gate) | IranOnlyView.vue | 0 | 5 | 0 | 0 | 0 |
| `app/templates/training-lesson.html` | 168 | A single training lesson. | Public | TrainingLesson.vue | 0 | 1 | 1 | 0 | 1 |
| `app/templates/call-display.html` | 154 | TV display for the queue; full-screen, minimal controls. | Public page (LAN TV) | CallDisplay.vue | 0 | 27 | 2 | 2 | 0 |
| `app/templates/partials/label_queue.html` | 110 | Label queue partial included by the kiosk. | Included | LabelQueue.vue | 0 | 1 | 0 | 0 | 0 |
| `app/templates/hourlypass_Report_page.html` | 98 | Hourly-pass report page. | Admin | HourlyPassReport.vue | 0 | 7 | 1 | 0 | 0 |
| `app/templates/overtime_report_page.html` | 98 | Overtime report page. | Admin | OvertimeReport.vue | 0 | 7 | 1 | 0 | 0 |
| `app/templates/leave_report_page.html` | 96 | Leave report page. | Admin | LeaveReport.vue | 0 | 7 | 1 | 0 | 0 |
| `app/templates/label_print_document.html` | 47 | Printable label/receipt document rendered for the print pipeline. | Server-rendered for print | PrintLabel.vue / print document | 0 | 0 | 0 | 0 | 0 |
| `app/templates/payroll_report_page.html` | 33 | Payroll calculation report preview page. | Admin | PayrollReport.vue | 0 | 4 | 1 | 0 | 0 |

## Per-template dependencies

### `app/templates/ticket-kiosk.html` (3,499 lines)

* **Scripts:** `/static/js/label-system.js`, `{{ url_for(`
* **Stylesheets:** `/static/css/call-tokens.css`
* **API paths referenced in markup:** `/api/queue/take`, `/api/queue/ticket/`
* **Jinja expressions:** `url_for`

### `app/templates/admin.html` (3,091 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/api/session/destroy`, `/registration/admin/requests/`
* **Jinja expressions:** `average_overtime_per_user`, `average_pass_per_user`, `loop.index`, `message`, `no_overtime_users`, `overtime_percent`, `overtime_user_count`, `pass_percent`, `pass_report.row_number`, `pass_report.total_pass_time`, `pass_report.username`, `report.row_number`, `report.total_ezafe_time`, `report.total_remaining`
* **Jinja blocks:** `for message`, `for pass_report`, `for report`, `for row`, `for user`, `if messages`, `if overtime_chart_data`, `if pass_chart_data`, `if user.employment_status`, `if user.is_active`

### `app/templates/user-panel.html` (1,336 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/api/session/destroy`
* **Jinja expressions:** `approved_count`, `check_in_time or '`, `check_out_time or '`, `entry_time or ''`, `leave.days`, `leave.end_date`, `leave.start_date`, `leave.status`, `loop.index`, `overtime_hours or '۰'`, `pass_records|length`, `record.date`, `record.description`, `record.duration`
* **Jinja blocks:** `for leave`, `for record`, `for ticket`, `if pass_records`, `if pass_records|length`, `if ticket.ticket_status`, `if ticket_records`

### `app/templates/register.html` (749 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/login`, `/registration`
* **Jinja expressions:** `url_for`

### `app/templates/master-admin.html` (686 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/api/session/destroy`, `/logout`, `/master-admin/admin-actions`, `/master-admin/audit-logs`, `/master-admin/dashboard`, `/master-admin/errors`, `/master-admin/label-printer`, `/master-admin/password-resets`, `/master-admin/security`, `/master-admin/sessions`, `/master-admin/subscriptions`, `/master-admin/system-settings`, `/master-admin/tickets`, `/master-admin/users`
* **Jinja expressions:** `active_section`, `url_for`, `username`
* **Jinja blocks:** `if active_section`, `include partials/label_queue.html`

### `app/templates/login.html` (645 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/api/system-config`
* **Jinja expressions:** `url_for`

### `app/templates/rules.html` (502 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/login`
* **Jinja expressions:** `url_for`

### `app/templates/call-management.html` (500 lines)

* **Scripts:** `/static/js/call-system-standalone.js`, `/static/js/csrf-bootstrap.js`, `/static/js/hastama-ux.js`, `/static/js/number-format.js`, `/static/js/toast.js`
* **Stylesheets:** `/static/css/call-system-standalone.css`, `/static/css/hastama-ux.css`, `/static/css/toast.css`

### `app/templates/final_report_page.html` (267 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `month_name`, `url_for`, `year`

### `app/templates/offline.html` (202 lines)

* **Scripts:** — inline only
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `lan_url`, `outage_message`, `outage_title or 'ارتباط با سامانه برقرار نیست'`, `retry_seconds or 15`
* **Jinja blocks:** `if lan_enabled`, `if lan_url`, `if outage_manual`

### `app/templates/training.html` (195 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/login`
* **Jinja expressions:** `back_url`, `cat.description`, `cat.icon`, `cat.lessons | length`, `cat.title`, `cat_id`, `error`, `lesson.description`, `lesson.estimated_time`, `lesson.icon`, `lesson.title`, `lesson_id`, `loop.index`, `loop.index0`
* **Jinja blocks:** `for cat_id,`, `for lesson_id`, `if _show`, `if active_category`, `if error`, `if from_login`, `if lesson`, `if not`, `if user_role`

### `app/templates/vpn-warning.html` (180 lines)

* **Scripts:** — inline only
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `line`, `retry_seconds or 30`, `vpn_ip`, `vpn_message`, `vpn_reason`, `vpn_title or 'دسترسی از این آی`
* **Jinja blocks:** `for line`, `if vpn_help_lines`, `if vpn_ip`, `if vpn_reason`

### `app/templates/training-lesson.html` (168 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **API paths referenced in markup:** `/login`
* **Jinja expressions:** `back_url`, `error`, `lesson.before_start`, `lesson.category`, `lesson.description`, `lesson.estimated_time`, `lesson.for_what`, `lesson.icon`, `lesson.id`, `lesson.if_wrong`, `lesson.important`, `lesson.next_lesson`, `lesson.previous_lesson`, `lesson.title`
* **Jinja blocks:** `for step`, `if error`, `if lesson.before_start`, `if lesson.for_what`, `if lesson.if_wrong`, `if lesson.important`, `if lesson.next_lesson`, `if lesson.previous_lesson`, `if lesson.role`, `if lesson.steps`, `if next_`, `if prev`, `if user_role`

### `app/templates/call-display.html` (154 lines)

* **Scripts:** `/static/js/call-display.js`, `/static/js/number-format.js`
* **Stylesheets:** `/static/css/call-display.css`, `/static/css/vazir.css`

### `app/templates/partials/label_queue.html` (110 lines)

* **Scripts:** — inline only
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `admission`, `assets.brand`, `assets.logo`, `lbl.get`, `number`, `root_class`, `root_id`, `root_style`, `tpl`
* **Jinja blocks:** `if minimal`, `if not`, `if root_class`, `if root_id`, `if root_style`, `include `

### `app/templates/hourlypass_Report_page.html` (98 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `url_for`

### `app/templates/overtime_report_page.html` (98 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `url_for`

### `app/templates/leave_report_page.html` (96 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `url_for`

### `app/templates/label_print_document.html` (47 lines)

* **Scripts:** — inline only
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `head_styles`, `label_js`, `title`
* **Jinja blocks:** `include partials/label_queue.html`

### `app/templates/payroll_report_page.html` (33 lines)

* **Scripts:** `{{ url_for(`
* **Stylesheets:** — inline / inherited from the head
* **Jinja expressions:** `url_for`

