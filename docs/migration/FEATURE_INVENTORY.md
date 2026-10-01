# Feature inventory — current implementation → target Laravel + Vue

Every feature that exists in the running application today, with the mapping the migration must
honour. **Status legend:** `Pending` · `In Progress` · `Migrated` · `Tested` · `Verified`.
`Verified` is reserved for behaviour confirmed against the running Laravel application with the
regression table filled in — nothing in this document is `Verified` yet, because Phase 1 is
inspection only.

Columns: **Cur** = current implementation · **Route** = current route(s) ·
**Tpl** = current template · **JS** = current JavaScript · **DB** = database objects ·
**Logic** = where the current business logic lives · **Laravel** = target backend ·
**Vue** = target frontend.

---

## A. Identity and access

### A1. Login (username + password + optional CAPTCHA)

| Field | Value |
|---|---|
| Cur | `app/api/routes/auth.py::login`, `app/services/captcha.py`, `app/core/password_utils.py` |
| Route | `POST /login_user`, `GET /api/csrf-token`, `GET /captcha`, `POST /captcha/refresh`, `GET /captcha/status` |
| Tpl | `login.html` |
| JS | `script.js`, `csrf-bootstrap.js`, `login.html` inline |
| DB | `user_table` (`username`, `password` nchar(10), `password_hash`, `role`, `is_active`, `last_login`, `failed_login_count`), `system_config.captcha_enabled`, `audit_logs`, `user_sessions` |
| Logic | CAPTCHA validated from the signed session (`captcha_code`, `captcha_ts`, `captcha_attempts`); failure throttle per IP **and** per account (`LOGIN_FAILURE_WINDOW`, `LOGIN_MAX_FAILURES_PER_IP`); single generic failure message (no user enumeration); `session.clear()` before writing the new session |
| Laravel | `Auth\LoginController` + `LoginRequest` + `LoginService` + `CaptchaController` (SessionGuard custom provider against `user_table`) |
| Vue | `pages/auth/LoginPage.vue` + `stores/auth.js` + `services/api.js` |
| Status | Pending |
| Test | `tests/test_security_hardening.py`, `tests/test_login_experience.py` (Python); no Laravel test yet |

### A2. Legacy plaintext password verification + upgrade

| Field | Value |
|---|---|
| Cur | `password_utils.verify_password(legacy_plaintext, hash, supplied)`; `tools/migrate_passwords.py` |
| DB | `user_table.password` (**populated for 16/16**), `password_hash` (`varbinary(64)`, **1/16**) |
| Logic | If `password_hash` is set → bcrypt check; otherwise compare the plaintext legacy column. Neither value may ever be logged |
| Laravel | Custom `UserProvider::validateCredentials()` implementing the same dual path; `Hash::make` written back on first successful legacy login |
| Vue | none (transparent) |
| Status | Pending |
| Test | `tests/test_security_hardening.py` |
| Risk | **Highest-risk item in the audit.** A hash-only provider locks out 15 of 16 users |

### A3. Session management

| Field | Value |
|---|---|
| Cur | `app/core/sessions.py`, `app/core/session_cookie.py`, `_SessionRegistryMiddleware`, `_CSRFMiddleware` |
| Route | `GET /logout`, `POST /api/session/destroy`, `GET /api/system-config`, and every authenticated route |
| DB | `user_sessions` (303 rows, 58 `is_active`), `system_config.session_timeout_minutes` (480) / `idle_timeout_seconds` (300) / `idle_timeout_enabled` (1) |
| Logic | Signed session cookie; server-side `sid` registry; rotation on login; idle + absolute timeout; revocation on logout, password reset, account disable and administrator terminate |
| Laravel | `SESSION_DRIVER=database` with a `sessions`-shaped table mapping onto `user_sessions`, or a custom session handler; `EnsureSessionIsCurrent` middleware; `TerminateSession` action |
| Vue | `stores/auth.js` (session-expired handling) + axios response interceptor |
| Status | Pending |
| Test | `tests/test_session_bulk_actions.py`, `tests/test_security_regressions.py` |

### A4. Authorization: admin, master admin, ownership

| Field | Value |
|---|---|
| Cur | `_require_admin`, `_require_auth`, `_ticket_actor`, `get_is_admin_from_session`, `_master_admin()`, `_actor()`, `_require_call_page_access` |
| DB | `user_table.role` (`nchar(10)`, `admin` ×3 / `user` ×13); `MASTER_ADMIN_USERNAMES` env (default `ali`); `security_events`, `admin_actions` |
| Logic | Master-admin plane accepts **only** `is_master_admin`; denied attempts logged to `security_events`; per-row ownership inside handlers (e.g. `/get_user_info` 403 unless admin or self) |
| Laravel | `AdminMiddleware`, `MasterAdminMiddleware`, `TicketPolicy`, `UserPolicy`, Gates + `EnforceOwnership` trait; **never** a Vue route guard alone |
| Vue | `router/index.js` guards (UX only) + `stores/auth.js` |
| Status | Pending |
| Test | `tests/test_security_regressions.py` (62), `tests/test_security_hardening.py` (114) |

### A5. Password reset (request → admin approval → 8-char recovery code → reset)

| Field | Value |
|---|---|
| Cur | `auth.py::forgot_password/reset_password`, `app/services/audit.py` (`create_password_reset_request`, `approve_password_reset`, `reject_password_reset`, `verify_recovery_code`) |
| Route | `POST /forgot_password`, `POST /reset_password`, `GET /master-admin/api/password-resets`, `POST /password-resets/{id}/approve|reject`, `DELETE /password-resets/{id}` |
| Tpl | `login.html`, `master-admin.html` |
| JS | `script.js`, `master-admin.js` |
| DB | `password_reset_requests` (7 rows: `recovery_code`, `code_expires_at`, `code_attempts`, `max_attempts`, `approved_by`, `completed_at`), `system_config.password_reset_code_ttl_minutes` (60) |
| Logic | Unified response message (no enumeration); code is 8 chars from `secrets.choice(A–Z0–9)`; HMAC-protected; **fails closed** when `HASTAMA_HMAC_SECRET` is empty; code never returned by the history endpoint |
| Laravel | `PasswordResetController` + `PasswordResetService` + `ApprovalPolicy`; `Str::random`/`random_int` — **must stay CSPRNG**; TTL read from the settings table |
| Vue | `auth/ForgotPassword.vue`, `control-centre/PasswordResets.vue` |
| Status | Pending |
| Test | `tests/test_security_hardening.py` |
| Note | This is **not** an email flow and must not become one |

### A6. Self-registration with admin approval

| Field | Value |
|---|---|
| Cur | `app/api/routes/registration.py` (689 lines) |
| Route | `GET /registration/check-username`, `/check-national-id`, `/departments`, `/work-schedules`, `/active-users`, `/status/{id}`; `POST /registration/submit`; `GET/POST /registration/admin/requests…` |
| Tpl | `register.html` |
| JS | `register.html` inline |
| DB | `user_registration_requests` (20 cols, 0 rows) |
| Logic | Public submit rate-limited **5 per 10 min per IP** (bcrypt CPU guard); request-id guessing on `/status/{id}` made impractical; admin approve/reject creates the `user_table` row |
| Laravel | `RegistrationController` + `RegistrationRequest` + `RegisterUserJob`; keep the rate limiter |
| Vue | `auth/RegisterPage.vue`, `admin/RegistrationRequests.vue` |
| Status | Pending |
| Test | `tests/test_register_layout.py` |

---

## B. Attendance

### B1. Attendance read (dual source)

| Field | Value |
|---|---|
| Cur | `main.py::get_hozoor` (line 4759) + `app/services/araz_connector.py::ArazAccessDB` |
| Route | `GET /get_hozoor/{username}?start_date&end_date` (Jalali input), `POST /get_hozoor_filtered`, `GET /get_hozoor_today` |
| Tpl | `admin.html`, `final_report_page.html` |
| JS | `admin.js`, `final-report-script.js` |
| DB | `user_table.hozoor_num` `nchar(10)` (+ `work_hours`, six weekday columns), `shiftha`, `hozoor`; Access `Arazdb.mdb` → `TPrsInOut (CardNo, Date, Time, InOutType)`, `TPrsNames` |
| Logic | Access punches sorted, first four mapped to `EntryTime`/`ExitTime`/`EntryTime2`/`ExitTime2`; `hozoor` rows merged in when the date is absent (marked `CardNo='DB'`); every day in the range is emitted, empty ones padded; `weekday_map {0..5}`; `shiftha` month-range override wins over the weekday column which wins over `work_hours`; **column-name trap:** `user_table.chrshanbeh` vs `shiftha.chaharshanbeh` |
| Laravel | `AttendanceController` + `AttendanceService` + `ArazRepository` (Access read via the documented helper process) + `WorkScheduleResolver` |
| Vue | `attendance/AttendanceReport.vue`, `FinalReport.vue` |
| Status | Pending |
| Test | `tests/test_attendance.py` (19) |

### B2. Check-in / check-out and manual attendance

| Field | Value |
|---|---|
| Cur | `main.py::sabt_hozoor`, `sabt_hozoor_checkin`, `sabt_hozoor_checkout` |
| DB | `hozoor (id, username, date, vrood, khoroj)` — one row per `(username, date)` |
| Logic | `SELECT TOP 1 … WITH (UPDLOCK, HOLDLOCK)` before write; re-check-in stores into `EntryTime2`, i.e. it **overwrites `vrood` and nulls `khoroj`** in the current implementation |
| Laravel | `AttendanceService::checkIn()/checkOut()` in a `DB::transaction` with row locks (`lockForUpdate`) |
| Vue | `attendance/CheckInCard.vue` |
| Status | Pending |
| Test | `tests/test_attendance.py`, `tests/test_presence_summary.py` |

### B3. Live presence summary (ring)

| Field | Value |
|---|---|
| Cur | `app/services/presence_summary.py::build_presence_summary` |
| DB | `user_table` weekday columns + `hozoor` |
| Logic | `normalize_work_hours` swaps an inverted range; `scheduled_work_minutes` handles midnight-crossing shifts by adding 24 h; `green_percent` = worked / scheduled; **`overtime_minutes = max(0, now − work_end)` — zero-minute threshold**; `ring_mode = blue` as soon as overtime > 0 |
| Laravel | `PresenceService::buildSummary()` — a pure service, easy to port and unit-test |
| Vue | `components/PresenceRing.vue` |
| Status | Pending |
| Test | `tests/test_presence_summary.py` (6) |
| Note | There is **no 10-minute rule**. Do not add one |

### B4. Shifts / work schedule

| Field | Value |
|---|---|
| Cur | `main.py` shift routes |
| Route | `GET /get_shifts/{username}/{year}/{month}`, `POST /add_shift`, `POST /update_shift`, `POST /delete_shift/{id}`, `GET /get_active_shifts` |
| DB | `shiftha` (4 rows: `jalali_year`, `jalali_month`, `start_day`, `end_day`, seven weekday columns incl. `jomeh`, `title`) |
| Logic | Overlap validation on insert **and** update (`start_day <= ? AND end_day >= ?`, excluding self on update); `get_active_shifts` matches today's Jalali day inside `[start_day, end_day]`; the `پنجشنبه` variant `panjshanbeh` is spelled `panjshanbeh` in both tables but Wednesday is `chrshanbeh`/`chaharshanbeh` |
| Laravel | `ShiftController` + `ShiftRequest` + `ShiftService` |
| Vue | `admin/ShiftsSection.vue`, `user/Schedule.vue` |
| Status | Pending |
| Test | covered indirectly by `tests/test_attendance.py` |

---

## C. Requests: leave, hourly pass, overtime

### C1. Leave

| Field | Value |
|---|---|
| Cur | `main.py::submit_leave`, `get_leave_requests`, `update_leave_status`, `get_leave_info`, `generate_individual_report`, `leave_report_page` |
| Tpl | `leave_report_page.html`; JS `leave-report-script.js`, `admin.js` |
| DB | `mrkhc_table (start_date, end_date, days, substitute, username, status)`, `leave_report (username, total_days, remaining_days)` + **`trg_UpdateLeaveReport`** |
| Logic | Jalali → Gregorian conversion on write; `_pass_duration` computes the day count; the **trigger** owns `leave_report` and hard-codes a **30-day** annual entitlement with `remaining_days = 30 − approved days` |
| Laravel | `LeaveController` + `LeaveRequest` + `LeaveService`; **do not** write `leave_report` directly |
| Vue | `user/LeaveRequest.vue`, `admin/LeaveSection.vue`, `reports/LeaveReport.vue` |
| Status | Pending |
| Test | none dedicated (report layout only) |

### C2. Hourly pass (three kinds)

| Field | Value |
|---|---|
| Cur | `main.py::submit_hourly_pass`, `get_hourly_pass_requests`, `change_hourly_pass_status`, `update_hourly_pass_status`, `hourlypass_Report_page` |
| Tpl | `hourlypass_Report_page.html`; JS `hourlypass-report-script.js` |
| DB | `avalpss_table` (`officialtime`, `entrytime`, `total_time_aval`), `beynpss_table` (`entryTime`, `exitTime`, `total_time_beyn`), `akhrpss_table` (`officialTime`, `exitTime`, `total_time_akhr`), `totalpass_table` — **all totals and the `totalpass_table` rows are written by six triggers** |
| Logic | `aval` = before shift, `beyn` = mid-shift, `akhr` = after shift; totals are absolute time differences; `totalpass_table.status` starts at `انتظار تایید` |
| Laravel | `HourlyPassController` + `HourlyPassService`; writes go to the per-kind tables only |
| Vue | `user/HourlyPassRequest.vue`, `admin/HourlyPassSection.vue`, `reports/HourlyPassReport.vue` |
| Status | Pending |
| Test | none dedicated |

### C3. Overtime

| Field | Value |
|---|---|
| Cur | `main.py::submit_overtime`, `get_overtime_requests`, `update_overtime_status`, `update_overtime_Indivisual_status`, `overtime_report*` |
| DB | `ezafe_table (overtime_date, from_time, to_time, description, status, username, daily_overtime)`, `ezafe_total_table (username, total_ezafe_time)` + `trg_CalculateDailyOvertime`, `trg_UpdateTotalOvertime` |
| Logic | User-submitted `from_time`/`to_time`; the triggers compute `daily_overtime` and the per-user running total. **No threshold, no automatic inference** — see B3 for the live display rule, which is separate |
| Laravel | `OvertimeController` + `OvertimeService` |
| Vue | `user/OvertimeRequest.vue`, `admin/OvertimeSection.vue`, `reports/OvertimeReport.vue` |
| Status | Pending |
| Test | none dedicated |

### C4. Payroll / Karaneh (calculation storage only)

| Field | Value |
|---|---|
| Cur | `main.py::save_admin_payroll`, `load_admin_payroll`, `PAYROLL_CALCULATION_TYPES` |
| Route | `POST /api/admin/payroll/save`, `GET /api/admin/payroll/load`, `payroll_report_page` |
| DB | `admin_payroll_calculations` (54 rows: `calculation_type`, `period_year`, `period_month`, `username`, `payload_json`, `saved_by`, unique on those four) |
| Logic | Only four calculation types are accepted (`overtime`, `comprehensive`, `hourly`, `summary`); `_payroll_ascii_digits` normalises Persian numerals; `summary` rows are not user-scoped; the table is created on demand if absent |
| Laravel | `PayrollCalculationController` + model; real migration for the table (remove `_ensure_payroll_calculations_table`) |
| Vue | `reports/PayrollReport.vue` |
| Status | Pending |
| Test | none dedicated |
| Note | **No payroll is computed here.** Karaneh does not exist as a module. Keep it read/write of the stored payload and nothing more |

---

## D. Ticketing

### D1. Current ticketing (`tickets` / `ticket_messages` / `ticket_events`)

| Field | Value |
|---|---|
| Cur | `app/services/ticketing.py` (653), `app/api/routes/ticketing.py`, `notifications.py` |
| Route | `GET /api/tickets/categories`, `/users`, `/{id}`, `/{id}/attachments/{aid}`; `POST /{id}/messages`, `/{id}/attachments`; `PATCH /{id}`; plus `main.py::submit_ticket`, `get_ticket_requests`, `get_ticket_requests_admin`, `update_ticket`, `update_ticket_status`, `delete-ticket`, `add_ticket_response*` |
| Tpl | `admin.html`, `user-panel.html`; JS `ticketing.js`, `admin.js`, `user-panel-script.js` |
| DB | `tickets` (16 cols incl. `first_response_at`, `sla_due_at`, `resolved_at`, `closed_at`, `legacy_parent_id`), `ticket_messages`, `ticket_events`, `ticket_categories` (self-FK `parent_id`), `ticket_tags`, `ticket_tag_relations`, `ticket_attachments` |
| Logic | Status/priority lifecycle, per-ticket visibility (`visibility` on messages), attachments served only through an authorising endpoint, `ticket_events` audit trail, SLA timestamps |
| Laravel | `TicketController`, `TicketMessageController`, `TicketAttachmentController`, `TicketPolicy`, `TicketService` |
| Vue | `ticketing/*` pages + `stores/tickets.js` |
| Status | Pending |
| Test | `tests/test_ticketing_service.py` (8, 2 currently failing) |

### D2. Legacy ticketing (`ticket_table`, `Parent_id` threads)

| Field | Value |
|---|---|
| Cur | `main.py::submit_ticket`, `get_ticket_requests*`, `add_ticket_response*` (still reachable) |
| DB | `ticket_table (ticketTitle, ticketDescription, username, ticket_date, ticket_status, target_username, Parent_id, is_read)` — 1 row |
| Logic | `Parent_id` builds the conversation; `target_username` addresses it; `is_read` tracks reading |
| Laravel | Same controller with a `legacy_parent_id` bridge (the new `tickets` table already has `legacy_parent_id`) |
| Vue | as D1 |
| Status | Pending |
| Note | Low row count but **reachable** — must not be dropped without evidence that no user depends on it |

---

## E. Announcements and notifications

### E1. Announcements / notifications

| Field | Value |
|---|---|
| Cur | `app/api/routes/notifications.py` (718), `app/services/background_tasks.py` |
| Route | admin: `/api/admin/notifications*` (10); user: `/api/notifications`, `/unread-count`, `/poll`, `/stream`, `/admin-stream`, `/{id}`, `/{id}/read`, `/{id}/unread`, `DELETE /{id}`, `/read-all` |
| Tpl | `admin.html`, `user-panel.html`; JS `notification-system.js` (SSE) |
| DB | `notifications`, `notification_targets`, `user_notifications`, `push_subscriptions` |
| Logic | Status-driven publish at `scheduled_at`; audience via `target_type` + `notification_targets`; per-user delivered/read/dismissed; archived; the scheduler sweeps due rows every second as a safety net while create/update publishes immediately |
| Laravel | `NotificationController` (admin + user), `Announcement` model, `PublishDueNotifications` job scheduled every minute, `UserNotification` model, `NotificationSent` event |
| Vue | `components/NotificationBell.vue`, `stores/notifications.js`, `composables/useNotificationStream.js` (SSE) |
| Status | Pending |
| Test | `tests/test_notification_system.py`, `tests/e2e_notification_test.py` |

### E2. Web Push subscriptions

| Field | Value |
|---|---|
| Cur | `push_subscriptions` management inside `notifications.py` |
| DB | `push_subscriptions (endpoint, p256dh, auth, user_agent, last_used_at, disabled_at)` |
| Logic | Store/refresh/disable browser push endpoints; delivery uses the VAPID keys configured for the origin |
| Laravel | `PushSubscriptionController` + a Web-Push library with the same VAPID keys |
| Vue | `composables/usePush.js` |
| Status | Pending |
| Test | none dedicated |

### E3. Offline fallback (service worker)

| Field | Value |
|---|---|
| Cur | `app/static/sw.js`, `GET /offline`, `app/static/js/offline-guard.js`, `offline.html` |
| Logic | Caches `/offline` and answers **only failed top-level navigations** from the cache; nothing else is ever served stale |
| Laravel | identical static asset + `OfflineController`; keep the narrow scope |
| Vue | `views/OfflineView.vue` |
| Status | Pending |
| Test | `tests/test_outage_page.py` |

---

## F. Call system

### F1. Reception calls + display queue

| Field | Value |
|---|---|
| Cur | `app/api/routes/call_system.py` |
| Route | `POST /api/calls`, `/calls/repeat`, `/calls/test-display`, `/calls/test-voice`, `/calls/test-audio`, `/calls/reset-display`, `/calls/refresh-display`, `/calls/remove`, `/calls/waiting-queue*`; `GET /api/calls/audio-status`, `/calls/display-queue`, `/calls/waiting-queue`, `/calls/recent`, `/calls/status` |
| Tpl | `call-management.html`, `call-display.html`; JS `call-system-standalone.js`, `call-display.js`; CSS `call-system-standalone.css`, `call-display.css`, `call-tokens.css` |
| DB | `reception_calls`, `display_queue (slot_position)`, `waiting_queue` |
| Logic | `MAX_DISPLAY_SLOTS` slot shifting; `_validate_reception_number` bounds **1–2000** (input validation, not the numbering range); `_guard_kiosk_write` = same-site Origin + 120/min/IP; `_actor(required=False)` yields `guest` for the reception desk; Persian numerals on screen; audio activation re-broadcast |
| Laravel | `CallController` + `CallService` + `DisplayBroadcast` event (Reverb/Pusher-protocol or a small WS sidecar) |
| Vue | `calls/CallManagement.vue`, `calls/CallDisplay.vue` |
| Status | Pending |
| Test | `tests/test_queue_numbering.py` (16) |

### F2. Numbered tickets (kiosk + continuous numbering)

| Field | Value |
|---|---|
| Cur | `POST /api/queue/take`, `/queue/print`, `/queue/call/{id}`, `/queue/call-next`, `/queue/complete/{id}`, `PUT /queue/ticket/{n}`, `GET /queue/list`, `/queue/stats`, `/queue/ticket/{n}`, `DELETE /queue/{id}`, `DELETE /queue` |
| Tpl | `ticket-kiosk.html` (3 499 lines), `label_print_document.html`, `partials/label_queue.html` |
| DB | `queue_tickets` (42 rows: `ticket_number`, `ticket_date`, `status`, `service`, `called_for`, `patient_*`, `insurance_*`) |
| Logic | **Continuous global numbering**: `ISNULL(MAX(ticket_number),0)+1 … WITH (UPDLOCK, HOLDLOCK)`, never reset by day or restart; the waiting queue intentionally spans days; services include **«نمونه‌گیری»**; patient PII capture guarded by `_guard_queue_pii` (30/min/IP + same-site Origin) |
| Laravel | `QueueTicketController` + `QueueService` (`lockForUpdate` inside a transaction) + `TicketNumberIssued` event |
| Vue | `calls/TicketKiosk.vue`, `calls/QueueList.vue` |
| Status | Pending |
| Test | `tests/test_queue_numbering.py`, `tests/test_ticket_kiosk_dom.py` |

### F3. Display slideshow

| Field | Value |
|---|---|
| Cur | `call_system.py` slide routes |
| Route | `GET /api/calls/slides`, `/calls/slides/active`; `POST /calls/slides/upload`; `PUT /calls/slides/{id}/toggle`; `DELETE /calls/slides/{id}` |
| DB | `slides (filename, original_name, is_active, sort_order)`; files in `app/static/slides/` |
| Logic | Admin-only management (`_require_admin`); `sort_order` from `MAX(sort_order)+1`; the display rotates active slides between calls |
| Laravel | `SlideController` + `SlideService` + `storage:link`-style public serving (keep the local path contract) |
| Vue | `calls/SlidesAdmin.vue` |
| Status | Pending |
| Test | none dedicated |

---

## G. Printing

### G1. Label / numbered-ticket printing

| Field | Value |
|---|---|
| Cur | `app/services/ticket_print.py`, `label_render.py`, `printer.py`, `app/api/routes/call_system.py::label_config/label_print_document/print_queue` |
| Route | `GET /api/label/config`, `POST /api/label/print-document`, `POST /api/queue/print`, `GET /master-admin/api/printers` |
| Tpl | `label_print_document.html`; CSS `label-print.css`; JS `label-system.js` |
| DB | `system_config.label_print_settings` = `{"width_mm":76,"height_mm":105,"template":"queue","rotate":false,"show_name":true,"show_time":true,"show_hint":true,"layout_version":6}`, `system_config.label_target_printer` = `EPSON TM-T88III Receipt` |
| Logic | HTML → Edge headless → PNG/PDF → PowerShell `System.Printing` / `System.Drawing.Printing` → spooler, with `PageMediaSize(Unknown, w*100, h*100)` in 1/100 mm; fallbacks `edge-lp` → `edge-printto` → `Out-Printer`; printers enumerated live from the spooler; the printer name is escaped with `_ps_quote` |
| Laravel | `PrintingService` + `LabelRenderer` + `PrinterRepository`; a **local print helper** invoked by the Windows host (the rendering pipeline is intrinsically Windows) |
| Vue | `print/LabelDocument.vue` (render target only) |
| Status | Pending |
| Test | `tests/test_label_printer_api.py` (50), `tests/test_ticket_print_server.py` (41) |
| Note | **Never** change the calibration values. There is **no ESC/POS byte stream** to port |

### G2. Final report printing / PDF

| Field | Value |
|---|---|
| Cur | `final-report-print.js`, `final_report_page.html`, `GET /download_pdf`, `final-report-print.css` |
| Logic | Server-side PDF (pdfkit/wkhtmltopdf in the Python stack) plus a dedicated print stylesheet; the print layer must contain no screen-only decoration (asserted by a test) |
| Laravel | `ReportPdfController` + a PHP PDF renderer producing the same layout |
| Vue | `reports/FinalReport.vue` |
| Status | Pending |
| Test | `tests/test_final_report_print.py` (1 currently failing) |

---

## H. Araz T7 integration

| Field | Value |
|---|---|
| Cur | `app/services/araz_connector.py` (797), `app/api/routes/araz_api.py` (594), `tools/bridge_agent.py` |
| Route | `GET/POST /api/araz/config`, `GET /api/araz/test`, `/time`, `POST /api/araz/time/sync`, `/sync`, `/bridge-sync` |
| DB | Access `E:\Hastama\database\Arazdb.mdb` → `TPrsInOut (CardNo, Date, Time, InOutType)`, `TPrsNames`; SQL Server `hozoor`, `user_table.hozoor_num` |
| Logic | Two transports: the native device protocol (`ARAZREQPROTO0002`, default `192.168.3.200:1001`, record `YYMMDD\tCardNo\tHHMM\tInOutType\tFlag`) and the **authoritative** Access MDB read. `bridge-sync` authenticates with `hmac.compare_digest` against `ARAZ_BRIDGE_SECRET`, **503 when unset**, 60/min/IP, batch cap, Jalali → Gregorian conversion, and its **own** DB connection (sharing the process cursor was a real thread-safety bug). All other routes are admin-only |
| Laravel | `ArazController` + `ArazService` + `ArazRepository`; the Access read needs the documented helper process (see OPEN_QUESTIONS Q1) |
| Vue | `admin/ArazPanel.vue` |
| Status | Pending |
| Test | no dedicated test module |
| Note | `D:\python\database\Arazdb.mdb`, `Perdata.mdb`, `Server.ini`, `T7PrsInOut*.txt` do **not** exist in this project |

### H2. Card-number mapping (`hozoor_num` ↔ `CardNo`)

| Field | Value |
|---|---|
| Logic | plain trimmed string equality; `tools/card_mapping.json` as an operator side file |
| Data hazards | **3 users have `hozoor_num = NULL`**; **1 duplicate group exists after normalisation**; 13/16 values are space-padded |
| Laravel | keep the mapping logic identical; surface the data problems as a report, not a silent "fix" |
| Status | Pending |
| Test | none |

---

## I. Internal automation

| Field | Value |
|---|---|
| Cur | `app/api/routes/automation.py` (10 routes), `app/services/automation.py` (150), `database/automation.sql` executed lazily on first use |
| Route | `/api/automation/conversations*`, messages, attachments, reopen requests |
| Tpl | `admin.html`; JS `internal-automation.js`, `internal-automation-admin.js` |
| DB | `automation_conversations` (4), `automation_messages` (6), `automation_participants` (8), `automation_attachments` (0), `automation_reopen_requests` (0) |
| Logic | Participant-based authorisation (`_participant`), conversation status, message thread, attachment linkage, reopen requests |
| Laravel | `AutomationController` + `AutomationService` + `ConversationPolicy`; the SQL must move from lazy `_ensure_schema` into a real migration |
| Vue | `automation/*` |
| Status | Pending |
| Test | none dedicated |

---

## J. Admin panel and control centre

| Feature | Cur | Route | Tpl | JS | DB | Laravel | Vue | Status |
|---|---|---|---|---|---|---|---|---|
| Admin dashboard | `main.py` | `/admin/dashboard`, `/admin/{section}` | `admin.html` | `admin.js`, `admin-mobile.js` | `user_table`, `hozoor`, `mrkhc_table`, `ezafe_table`, `avalpss_table` | `Admin\DashboardController` | `admin/Dashboard.vue` | Pending |
| User management | `main.py` | `POST /add_user`, `/update_user`, `/get_users`, `/get_user_info`, `/api/admin/employment-status` | `admin.html` | `admin.js` | `user_table` | `Admin\UserController` + `UserRequest` | `admin/UsersSection.vue` | Pending |
| Reports (leave / hourly-pass / overtime / payroll / final) | `main.py` | `/leave_report_page`, `/hourlypass_Report_page`, `/overtime_report_page`, `/payroll_report_page`, `/final_report_page`, `/generate_individual_report`, `/download_pdf` | 5 report templates | 5 report scripts | request tables + `hozoor` | `ReportController` | `reports/*` | Pending |
| Profile images | `main.py` | `POST /upload-profile-image`, `/delete-profile-image` | `user-panel.html` | `user-panel-script.js` | `user_table.profile_image`, `app/static/uploads/` | `ProfileImageController` + `ImageRequest` | `profile/ProfileForm.vue` | Pending |
| Control centre dashboard | `master_admin.py` | `GET /master-admin/api/dashboard/stats`, `/dashboard/activity` | `master-admin.html` | `master-admin.js` | `audit_logs`, `user_sessions`, `system_errors` | `MasterAdmin\DashboardController` | `control-centre/Dashboard.vue` | Pending |
| Users / roles / status | `master_admin.py` | `/users`, `/users/{u}`, `/toggle-status`, `/change-role` | ″ | ″ | `user_table`, `admin_actions` | `MasterAdmin\UserController` | `control-centre/Users.vue` | Pending |
| Sessions (incl. bulk terminate) | `master_admin.py` | `/sessions`, `/sessions/{k}/terminate`, `/sessions/terminate-all`, `DELETE /sessions` | ″ | ″ | `user_sessions` | `MasterAdmin\SessionController` | `control-centre/Sessions.vue` | Pending |
| Password resets | `master_admin.py` | `/password-resets*` | ″ | ″ | `password_reset_requests` | `MasterAdmin\PasswordResetController` | `control-centre/PasswordResets.vue` | Pending |
| Audit logs | `master_admin.py` | `/audit-logs`, `/audit-logs/{id}` | ″ | ″ | `audit_logs` (3 509) | `MasterAdmin\AuditLogController` | `control-centre/AuditLogs.vue` | Pending |
| Security events | `master_admin.py` | `/security`, `/security/{id}/resolve` | ″ | ″ | `security_events` | ″ | `control-centre/SecurityEvents.vue` | Pending |
| Errors | `master_admin.py` | `/errors`, `/errors/{id}/resolve` | ″ | ″ | `system_errors` | ″ | `control-centre/Errors.vue` | Pending |
| Admin actions | `master_admin.py` | `/admin-actions` | ″ | ″ | `admin_actions` (133) | ″ | `control-centre/AdminActions.vue` | Pending |
| System health / search | `master_admin.py` | `/system-health`, `/search` | ″ | ″ | many | ″ | `control-centre/SystemHealth.vue`, `Search.vue` | Pending |
| Subscriptions | `master_admin.py` | `/subscriptions*` | ″ | ″ | `customer_subscriptions`, `user_table.customer_id` | `MasterAdmin\SubscriptionController` | `control-centre/Subscriptions.vue` | Pending |
| System settings | `master_admin.py` | `GET/POST /config` | ″ | ″ | `system_config` (12 rows) | `MasterAdmin\SettingsController` | `control-centre/Settings.vue` | Pending |
| Master-admin tickets | `master_admin.py` | `/tickets*`, `/tickets/categories/all`, `/tickets/users/all` | ″ | ″ | `tickets`, `ticket_messages` | `MasterAdmin\TicketController` | `control-centre/Tickets.vue` | Pending |
| LAN access toggle | `master_admin.py`, `lan_access.py` | `GET/POST /lan-access`, `POST /lan-access/selftest` | ″ | ″ | `system_config.lan_access_enabled` (no row → off) | `MasterAdmin\LanAccessController` + `LanRelayService` | `control-centre/LanAccess.vue` | Pending |
| Outage mode | `master_admin.py`, `outage.py` | `GET/POST /outage`, `/outage/check`, `/outage/manual` | ″ | ″ | `system_config.outage_*` | `MasterAdmin\OutageController` + `OutageMonitor` | `control-centre/Outage.vue` | Pending |
| Iran-only filter | `master_admin.py`, `iran_access.py` | `GET/POST /iran-access`, `/iran-access/check`, `/refresh`, `/counters/reset` | ″ | ″ | `app/data/iran_ip_ranges.txt` + `iran_ip_ranges_extra.txt`, `audit_logs` | `MasterAdmin\IranAccessController` + `IranAccessService` | `control-centre/IranAccess.vue` | Pending |
| Login experience | `master_admin.py`, `login_experience.py` | `GET/POST /login-experience` | ″ | ″ | `system_config` (defaults when absent) | `MasterAdmin\LoginExperienceController` | `control-centre/LoginExperience.vue` | Pending |
| Printers | `master_admin.py`, `printer.py` | `GET /master-admin/api/printers` | ″ | ″ | `system_config.label_target_printer` | `MasterAdmin\PrinterController` | `control-centre/Printers.vue` | Pending |
| Notification administration | `notifications.py` | `/api/admin/notifications*` | ″ | `master-admin.js` | `notifications` et al. | `MasterAdmin\NotificationController` | `control-centre/Notifications.vue` | Pending |
| Internal automation admin | `automation.py` | `/api/automation/*` | ″ | `internal-automation-admin.js` | automation tables | `AutomationController` | `automation/*` | Pending |

---

## K. Public / content pages

| Feature | Cur | Route | Tpl | Status |
|---|---|---|---|---|
| Landing | `main.py::landing_page` | `GET /` | redirects to login/home | Pending |
| Rules | `main.py` | `GET /rules` | `rules.html` + `rules.js` | Pending |
| Training hub, categories, lesson, search | `main.py` | `GET /training`, `/training/{category}`, `/training/lesson/{id}`, `/api/training/search` | `training.html`, `training-lesson.html` + `training.js` | Pending |
| Iran-only policy page | `main.py` | `GET /iran-only`, `/iran-only/check` | `vpn-warning.html` | Pending |
| Outage page | `main.py`, `outage.py` | served by `_OutageGateMiddleware` | `offline.html` | Pending |
| Offline page | `main.py` | `GET /offline` (+ `sw.js`) | `offline.html` | Pending |
| robots.txt / sitemap.xml / favicon | `main.py` | `GET /robots.txt`, `/sitemap.xml`, `/favicon.ico` | — | Pending |
| Health | `health.py` | `GET /health`, `/health/database` | — | Pending |
| Jalali date endpoint | `main.py` | `GET /api/date`, `/get_today_date` | — | Pending |

---

## L. Platform behaviours that must survive

| Behaviour | Cur | Laravel equivalent | Status |
|---|---|---|---|
| Trusted-proxy client IP | `app/core/net.py` (last valid XFF, `TRUSTED_PROXY_IPS`) | `TrustProxies` + a `ClientIp` helper preserving the same rule | Pending |
| Host allow-list | `TrustedHostMiddleware` + `HASTAMA_ALLOWED_HOSTS` | `TrustHosts` | Pending |
| Security headers + no server header | `_SecurityHeadersMiddleware`, `--no-server-header` | `SecurityHeaders` middleware; `server_tokens`/response-header suppression | Pending |
| CSRF | `_CSRFMiddleware` + session token + cookie + JS interceptor | Laravel CSRF middleware + `XSRF-TOKEN` cookie + axios | Pending |
| Rate limiting | `app/core/rate_limit.py` for login, registration, bridge, kiosk, PII | `RateLimiter` with the same windows and the same keys | Pending |
| Iran-only gate | `iran_access.py` (1 600 v4 + 571 v6 + operator supplement) | `IranAccessMiddleware` + a ported `IranAccessService` | Pending |
| Outage gate | `outage.py` | `OutageMiddleware` + a scheduled monitor | Pending |
| LAN relay | `lan_access.py` (byte-transparent, header-sanitising) | a `LanRelayService` command or an equivalent out-of-band relay | Pending |
| Client asset minification | `client_assets.py` + `HASTAMA_MINIFY_CLIENT_ASSETS` | Vite production build (switch retired) | Pending |
| Persian numerals | `core/number_format.py`, `number-format.js`, `to_persian_numbers` | `Support\PersianNumber` + `utils/numbers.js` | Pending |
| Jalali <-> Gregorian | `jdatetime`, `persiantools` throughout `main.py` | `morilog/jalali` or ICU, storage stays Gregorian `date`/`datetime2` | Pending |
| RTL + dark theme + local Vazir | `dark-theme.css` (must be last), `vazir.css`, `Vazirmatn/Vazir.ttf/woff/woff2` | component-scoped dark rules + local font assets | Pending |
| Branding strings | `samanehlogo.png`, `lab-logo.png`, «آزمایشگاه دکتر امینی», «همگام با تکنولوژی روز، به پشتوانه تجربه دیروز» | unchanged assets and strings | Pending |
| Production supervision | `\HastamaServer` + `\HastamaWatchdog` tasks, `watchdog_server.ps1`, launcher log rotation, `health` endpoint contract | same supervision contract with a Laravel launcher | Pending |

---

## Aggregate status

| Area | Features | Pending | Verified |
|---|---:|---:|---:|
| Identity and access | 6 | 6 | 0 |
| Attendance | 4 | 4 | 0 |
| Requests (leave / hourly pass / overtime / payroll storage) | 4 | 4 | 0 |
| Ticketing | 2 | 2 | 0 |
| Announcements and notifications | 3 | 3 | 0 |
| Call system | 3 | 3 | 0 |
| Printing | 2 | 2 | 0 |
| Araz | 2 | 2 | 0 |
| Internal automation | 1 | 1 | 0 |
| Admin panel and control centre | 22 | 22 | 0 |
| Public / content | 9 | 9 | 0 |
| Platform behaviours | 14 | 14 | 0 |
| **Total** | **72** | **72** | **0** |

Nothing is migrated. Nothing is verified.
