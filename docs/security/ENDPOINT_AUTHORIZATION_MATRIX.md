# Endpoint Authorization Matrix — Hastama

Generated from the AST of `app/main.py` and `app/api/routes/*.py` (full function bodies,
not a text window) and cross-checked with anonymous HTTP probes against the real ASGI app
(`TestClient`, no session, `follow_redirects=False`).

**Total routes: 190**

| Family | Count |
|---|---|
| master-admin | 30 |
| admin | 60 |
| admin (indirect) | 2 |
| authenticated | 49 |
| authenticated (indirect) | 5 |
| bridge-secret | 1 |
| public-by-design | 35 |
| public-by-design (kiosk) | 2 |
| static shell | 5 |
| redirect-only | 1 |

Probe column = HTTP status returned to an anonymous `GET` (parameterless routes only).

## Routes

| Method | Path | Handler | Family | Guard | Anon probe | Note |
|---|---|---|---|---|---|---|
| GET | `/master-admin/api/admin-actions` | `list_admin_actions` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/audit-logs` | `list_audit_logs` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/audit-logs/{event_id}` | `get_audit_event` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/config` | `get_config` | master-admin | _master_admin | 401 |  |
| POST | `/master-admin/api/config` | `update_config` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/dashboard/activity` | `dashboard_activity` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/dashboard/stats` | `dashboard_stats` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/errors` | `list_errors` | master-admin | _master_admin | 401 |  |
| POST | `/master-admin/api/errors/{error_id}/resolve` | `resolve_error` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/password-resets` | `list_password_resets` | master-admin | _master_admin | 401 |  |
| POST | `/master-admin/api/password-resets/{request_id}/approve` | `approve_reset` | master-admin | _master_admin | n/a |  |
| POST | `/master-admin/api/password-resets/{request_id}/reject` | `reject_reset` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/search` | `global_search` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/security` | `list_security_events` | master-admin | _master_admin | 401 |  |
| POST | `/master-admin/api/security/{event_id}/resolve` | `resolve_security_event` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/sessions` | `list_sessions` | master-admin | _master_admin | 401 |  |
| POST | `/master-admin/api/sessions/{session_key}/terminate` | `terminate_user_session` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/system-health` | `system_health` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/tickets` | `list_all_tickets` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/tickets/categories/all` | `ticket_categories_admin` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/tickets/stats` | `ticket_stats` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/tickets/users/all` | `ticket_users_admin` | master-admin | _master_admin | 401 |  |
| DELETE | `/master-admin/api/tickets/{ticket_id}` | `delete_ticket_admin` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/tickets/{ticket_id}` | `get_ticket_detail` | master-admin | _master_admin | n/a |  |
| PATCH | `/master-admin/api/tickets/{ticket_id}` | `update_ticket_admin` | master-admin | _master_admin | n/a |  |
| POST | `/master-admin/api/tickets/{ticket_id}/reply` | `reply_ticket_admin` | master-admin | _master_admin | n/a |  |
| GET | `/master-admin/api/users` | `list_users` | master-admin | _master_admin | 401 |  |
| GET | `/master-admin/api/users/{username}` | `get_user_detail` | master-admin | _master_admin | n/a |  |
| POST | `/master-admin/api/users/{username}/change-role` | `change_user_role` | master-admin | _master_admin | n/a |  |
| POST | `/master-admin/api/users/{username}/toggle-status` | `toggle_user_status` | master-admin | _master_admin | n/a |  |
| POST | `/add_shift` | `add_shift` | admin | _require_admin | n/a |  |
| POST | `/add_user` | `add_user` | admin | _require_admin | n/a |  |
| GET | `/admin` | `admin` | admin | get_is_admin_from_session | 303 |  |
| GET | `/api/admin/notification-targets` | `target_options` | admin | admin=True | 401 |  |
| GET | `/api/admin/notifications` | `admin_list` | admin | admin=True | 401 |  |
| POST | `/api/admin/notifications` | `create_notification` | admin | admin=True | n/a |  |
| DELETE | `/api/admin/notifications/delete-all` | `delete_all_notifications` | admin | admin=True | n/a |  |
| POST | `/api/admin/notifications/read-all` | `admin_mark_all_read` | admin | admin=True | n/a |  |
| DELETE | `/api/admin/notifications/{notification_id}` | `delete_notification` | admin | admin=True | n/a |  |
| PUT | `/api/admin/notifications/{notification_id}` | `update_notification` | admin | admin=True | n/a |  |
| POST | `/api/admin/notifications/{notification_id}/archive` | `archive_notification` | admin | admin=True | n/a |  |
| POST | `/api/admin/notifications/{notification_id}/publish` | `publish_notification` | admin | admin=True | n/a |  |
| POST | `/api/admin/notifications/{notification_id}/read` | `admin_mark_read` | admin | admin=True | n/a |  |
| POST | `/api/admin/notifications/{notification_id}/unread` | `admin_mark_unread` | admin | admin=True | n/a |  |
| POST | `/api/admin/employment-status` | `update_employment_status` | admin | get_is_admin_from_session | n/a |  |
| GET | `/api/admin/payroll/load` | `load_admin_payroll` | admin | get_is_admin_from_session | 422 |  |
| POST | `/api/admin/payroll/save` | `save_admin_payroll` | admin | get_is_admin_from_session | n/a |  |
| POST | `/api/calls` | `create_call` | admin | admin=True | n/a |  |
| GET | `/api/calls/recent` | `recent_calls` | admin | admin=True | 500 |  |
| POST | `/api/calls/refresh-display` | `refresh_display` | admin | admin=True | n/a |  |
| POST | `/api/calls/remove` | `remove_call` | admin | admin=True | n/a |  |
| POST | `/api/calls/repeat` | `repeat_last_call` | admin | admin=True | n/a |  |
| POST | `/api/calls/reset-display` | `reset_display` | admin | admin=True | n/a |  |
| POST | `/api/calls/slides/upload` | `upload_slide` | admin | admin=True | n/a |  |
| DELETE | `/api/calls/slides/{slide_id}` | `delete_slide` | admin | _require_admin | n/a |  |
| PUT | `/api/calls/slides/{slide_id}/toggle` | `toggle_slide` | admin | _require_admin | n/a |  |
| POST | `/api/calls/test-audio` | `test_audio` | admin | admin=True | n/a |  |
| POST | `/api/calls/test-display` | `test_display` | admin | admin=True | n/a |  |
| POST | `/api/calls/test-voice` | `test_voice` | admin | admin=True | n/a |  |
| POST | `/api/calls/waiting-queue` | `add_to_waiting_queue` | admin | admin=True | n/a |  |
| POST | `/api/calls/waiting-queue/{item_id}/call` | `call_from_queue` | admin | admin=True | n/a |  |
| POST | `/change_hourly_pass_status` | `change_hourly_pass_status` | admin | _require_admin | n/a |  |
| GET | `/api/araz/config` | `get_device_config` | admin | _require_admin | 401 |  |
| POST | `/api/araz/config` | `update_device_config` | admin | _require_admin | n/a |  |
| POST | `/delete_shift/{shift_id}` | `delete_shift` | admin | _require_admin | n/a |  |
| GET | `/fetch_user_data` | `fetch_user_data` | admin | _require_admin | 422 |  |
| POST | `/generate_individual_report` | `generate_individual_report` | admin | _require_admin | n/a |  |
| GET | `/get_active_shifts` | `get_active_shifts` | admin | _require_admin | 401 |  |
| POST | `/get_hourly_pass_report` | `get_hourly_pass_report` | admin | _require_admin | n/a |  |
| GET | `/get_hourly_pass_requests` | `get_hourly_pass_requests` | admin | _require_admin | 401 |  |
| GET | `/get_hozoor/{username}` | `get_hozoor` | admin | _require_admin | n/a |  |
| POST | `/get_hozoor_filtered` | `get_hozoor_filtered` | admin | _require_admin | n/a |  |
| GET | `/get_leave_requests` | `get_leave_requests` | admin | _require_admin | 401 |  |
| POST | `/get_overtime_report` | `get_overtime_report` | admin | _require_admin | n/a |  |
| GET | `/get_overtime_requests` | `get_overtime_requests` | admin | _require_admin | 401 |  |
| GET | `/get_shifts/{username}/{year}/{month}` | `get_shifts` | admin | _require_admin | n/a |  |
| GET | `/get_user_info_final_report_page/{username}` | `get_user_info_final_report_page` | admin | _require_admin | n/a |  |
| GET | `/api/notifications/admin-stream` | `admin_request_stream` | admin | admin=True | 401 |  |
| GET | `/api/araz/records` | `get_device_records` | admin | _require_admin | 401 |  |
| POST | `/sabt_hozoor` | `sabt_hozoor` | admin | _require_admin | n/a |  |
| POST | `/api/araz/sync` | `sync_records_to_database` | admin | _require_admin | n/a |  |
| GET | `/api/araz/test` | `test_device_connection` | admin | _require_admin | 401 |  |
| GET | `/api/araz/time` | `get_device_time` | admin | _require_admin | 401 |  |
| POST | `/api/araz/time/sync` | `sync_device_time` | admin | _require_admin | n/a |  |
| POST | `/update_hourly_pass_status` | `update_hourly_pass_status` | admin | _require_admin | n/a |  |
| POST | `/update_leave_status` | `update_leave_status` | admin | _require_admin | n/a |  |
| POST | `/update_overtime_Indivisual_status` | `update_overtime_indivisual_status` | admin | _require_admin | n/a |  |
| POST | `/update_overtime_status` | `update_overtime_status` | admin | _require_admin | n/a |  |
| POST | `/update_shift` | `update_shift` | admin | _require_admin | n/a |  |
| POST | `/update_user` | `update_user` | admin | _require_admin | n/a |  |
| GET | `/admin/dashboard` | `admin_dashboard` | admin (indirect) | _render_admin_page | 303 | 303 anonymous; guard is inside the shared renderer |
| GET | `/admin/{section}` | `admin_section` | admin (indirect) | _render_admin_page | n/a | 303 anonymous; guard is inside the shared renderer |
| GET | `/api/tickets` | `list_tickets` | authenticated | _actor | 401 |  |
| POST | `/api/tickets` | `create_ticket` | authenticated | _actor | n/a |  |
| GET | `/registration/active-users` | `get_active_users` | authenticated | session.get("username") | 403 |  |
| GET | `/registration/admin/requests` | `list_registration_requests` | authenticated | session.get("username") | 403 |  |
| GET | `/registration/admin/requests/{request_id}` | `get_registration_request` | authenticated | session.get("username") | n/a |  |
| POST | `/registration/admin/requests/{request_id}/approve` | `approve_registration` | authenticated | session.get("username") | n/a |  |
| POST | `/registration/admin/requests/{request_id}/reject` | `reject_registration` | authenticated | session.get("username") | n/a |  |
| POST | `/api/session/destroy` | `destroy_session` | authenticated | session.get("username") | n/a |  |
| DELETE | `/api/calls/waiting-queue/{item_id}` | `remove_from_waiting_queue` | authenticated | session.get("username") | n/a |  |
| GET | `/api/tickets/categories` | `categories` | authenticated | _actor | 401 |  |
| POST | `/delete-profile-image` | `delete_profile_image` | authenticated | session.get('username') | n/a |  |
| POST | `/delete-ticket` | `delete_ticket` | authenticated | _ticket_actor | n/a |  |
| GET | `/download_pdf` | `download_pdf` | authenticated | _require_auth | 401 |  |
| GET | `/get_hozoor_today` | `get_hozoor_today` | authenticated | _attendance_actor | 401 |  |
| GET | `/get_leave_info` | `get_leave_info` | authenticated | session.get('username') | 400 |  |
| GET | `/get_receivers` | `get_receivers` | authenticated | _ticket_actor | 403 |  |
| GET | `/get_ticket_details/{ticket_id}` | `get_ticket_details` | authenticated | _ticket_actor | n/a |  |
| GET | `/get_ticket_details_payam/{ticket_id}` | `get_ticket_details_payam` | authenticated | _ticket_actor | n/a |  |
| GET | `/get_ticket_requests` | `get_ticket_requests` | authenticated | _ticket_actor | 410 |  |
| GET | `/get_ticket_requests_admin` | `get_ticket_requests_admin` | authenticated | _ticket_actor | 410 |  |
| GET | `/get_user_info` | `get_user_info` | authenticated | session.get('username') | 200 |  | 200 with a failure payload when there is no session (returns no data); candidate to normalise to 401 |
| GET | `/get_user_info_report` | `get_user_info_report` | authenticated | _require_auth | 422 |  |
| GET | `/get_users` | `get_users` | authenticated | _ticket_actor | 403 |  |
| GET | `/logout` | `logout` | authenticated | session.get("username") | 307 |  |
| POST | `/mark_ticket_as_read/{ticket_id}` | `mark_ticket_as_read` | authenticated | _ticket_actor | n/a |  |
| GET | `/master-admin/{section}` | `master_admin_page` | authenticated | session.get('username') | n/a |  |
| GET | `/api/notifications` | `user_list` | authenticated | _actor | 401 |  |
| GET | `/api/notifications/poll` | `notification_poll` | authenticated | _actor | 401 |  |
| POST | `/api/notifications/read-all` | `mark_all_read` | authenticated | _actor | n/a |  |
| GET | `/api/notifications/stream` | `notification_stream` | authenticated | _actor | 401 |  |
| GET | `/api/notifications/unread-count` | `unread_count` | authenticated | _actor | 401 |  |
| GET | `/api/notifications/{notification_id}` | `notification_detail` | authenticated | _actor | n/a |  |
| GET | `/overtime_report` | `overtime_report` | authenticated | _require_auth | 401 |  |
| POST | `/sabt_hozoor_checkin` | `sabt_hozoor_checkin` | authenticated | _attendance_actor | n/a |  |
| POST | `/sabt_hozoor_checkout` | `sabt_hozoor_checkout` | authenticated | _attendance_actor | n/a |  |
| POST | `/submit_hourly_pass` | `submit_hourly_pass` | authenticated | session.get("username") | n/a |  |
| POST | `/submit_leave` | `submit_leave` | authenticated | session.get("username") | n/a |  |
| POST | `/submit_overtime` | `submit_overtime` | authenticated | session.get("username") | n/a |  |
| POST | `/submit_ticket` | `submit_ticket` | authenticated | _ticket_actor | n/a |  |
| POST | `/update_ticket` | `update_ticket` | authenticated | _ticket_actor | n/a |  |
| POST | `/update_ticket_status` | `update_ticket_status` | authenticated | _ticket_actor | n/a |  |
| POST | `/upload-profile-image` | `upload_profile_image` | authenticated | session.get('username') | n/a |  |
| GET | `/user_panel` | `user_panel` | authenticated | session.get('username') | 303 |  |
| GET | `/api/tickets/users` | `ticket_users` | authenticated | _actor | 401 |  |
| GET | `/api/tickets/{ticket_id}` | `get_ticket` | authenticated | _actor | n/a |  |
| PATCH | `/api/tickets/{ticket_id}` | `update_ticket` | authenticated | _actor | n/a |  |
| POST | `/api/tickets/{ticket_id}/attachments` | `upload_attachment` | authenticated | _actor | n/a |  |
| GET | `/api/tickets/{ticket_id}/attachments/{attachment_id}` | `download_attachment` | authenticated | _actor | n/a |  |
| POST | `/api/tickets/{ticket_id}/messages` | `add_message` | authenticated | _actor | n/a |  |
| POST | `/add_ticket_response` | `add_ticket_response` | authenticated (indirect) | _create_ticket_response | n/a | delegates to the modern ticketing API; session required |
| POST | `/add_ticket_response_userpanel` | `add_ticket_response_userpanel` | authenticated (indirect) | _create_ticket_response | n/a | delegates to the modern ticketing API; session required |
| DELETE | `/api/notifications/{notification_id}` | `dismiss` | authenticated (indirect) | _owned_update -> _actor | n/a | ownership enforced in the SQL WHERE clause |
| POST | `/api/notifications/{notification_id}/read` | `mark_read` | authenticated (indirect) | _owned_update -> _actor | n/a | ownership enforced in the SQL WHERE clause |
| POST | `/api/notifications/{notification_id}/unread` | `mark_unread` | authenticated (indirect) | _owned_update -> _actor | n/a | ownership enforced in the SQL WHERE clause |
| POST | `/api/araz/bridge-sync` | `bridge_sync` | bridge-secret | BRIDGE_SECRET | n/a |  |
| GET | `/api/csrf-token` | `csrf_token_bootstrap` | public-by-design | - | 200 |  |
| GET | `/api/date` | `get_date` | public-by-design | - | 200 |  |
| GET | `/api/system-config` | `public_system_config` | public-by-design | - | 200 |  | returns three public UI settings (captcha/idle-timeout flags) only |
| GET | `/api/training/search` | `training_search` | public-by-design | - | 200 |  | training catalogue, no personal data |
| GET | `/call-display` | `call_display` | public-by-design | - | 200 |  |
| GET | `/call-management` | `call_management` | public-by-design | - | 200 |  |
| GET | `/api/calls/audio-status` | `audio_status` | public-by-design | - | 200 |  |
| GET | `/api/calls/display-queue` | `get_display_queue` | public-by-design | - | 500 |  |
| GET | `/api/calls/slides` | `list_slides` | public-by-design | - | 500 |  |
| GET | `/api/calls/slides/active` | `list_active_slides` | public-by-design | - | 200 |  |
| GET | `/api/calls/status` | `display_status` | public-by-design | - | 200 |  |
| GET | `/captcha` | `get_captcha` | public-by-design | - | 200 |  |
| POST | `/captcha/refresh` | `refresh_captcha` | public-by-design | - | n/a |  |
| GET | `/captcha/status` | `captcha_status` | public-by-design | - | 200 |  |
| GET | `/registration/check-national-id` | `check_national_id` | public-by-design | - | 200 | registration availability check (see registration enumeration note) | answers whether a national id is registered — see RR-11 (product requirement, rate-limited) |
| GET | `/registration/check-username` | `check_username` | public-by-design | - | 200 | registration availability check (see registration enumeration note) | answers whether a username is free — see RR-11 (product requirement, rate-limited) |
| GET | `/registration/departments` | `get_departments` | public-by-design | - | 200 | department list for the registration form |
| POST | `/forgot_password` | `forgot_password` | public-by-design | - | n/a |  |
| GET | `/get_today_date` | `get_today_date` | public-by-design | - | 200 |  |
| GET | `/health` | `health` | public-by-design | - | 200 |  |
| GET | `/health` | `health` | public-by-design | - | 200 |  |
| GET | `/health/database` | `health_database` | public-by-design | - | 200 |  | returns only status, never connection details |
| GET | `/login` | `home` | public-by-design | - | 200 |  |
| POST | `/login_user` | `login` | public-by-design | password+captcha | n/a | pre-authentication endpoint; rate limited 15 failures/10 min per IP |
| POST | `/predict` | `predict` | public-by-design | - | n/a | ML predictor shipped with the repo; no data access, must be reviewed before LAN exposure |
| POST | `/public/support-ticket` | `public_support_ticket` | public-by-design | - | n/a |  |
| GET | `/register` | `register_page` | public-by-design | - | 200 |  |
| POST | `/reset_password` | `reset_password` | public-by-design | - | n/a |  |
| GET | `/rules` | `rules` | public-by-design | - | 200 |  |
| GET | `/registration/status/{request_id}` | `check_request_status` | public-by-design | - | n/a | registration status by opaque request id |
| POST | `/registration/submit` | `submit_registration` | public-by-design | - | n/a | public registration form; captcha + validation + rate limits |
| GET | `/training` | `training_hub` | public-by-design | - | 200 |  |
| GET | `/training/lesson/{lesson_id}` | `training_lesson` | public-by-design | - | n/a |  |
| GET | `/training/{category}` | `training_category` | public-by-design | - | n/a |  |
| GET | `/registration/work-schedules` | `get_work_schedules` | public-by-design | - | 200 | work schedule list for the registration form |
| GET | `/api/calls/waiting-queue` | `get_waiting_queue` | public-by-design (kiosk) | origin check on writes | 500 | reception/TV read endpoint; returns reception numbers and departments to the LAN |
| WEBSOCKET | `/api/ws/call-display` | `call_display_ws` | public-by-design (kiosk) | origin check | n/a | TV display; cross-site and opaque origins are closed with code 1008 |
| GET | `/final_report_page` | `final_report` | static shell | - | 200 | 200 anonymous: HTML shell only; data endpoints require a session/admin |
| GET | `/hourlypass_Report_page` | `hourly_pass_report_page` | static shell | - | 200 | 200 anonymous: HTML shell only; report data endpoint now admin-gated |
| GET | `/leave_report_page` | `report_page` | static shell | - | 200 | 200 anonymous: HTML shell only, data comes from guarded endpoints |
| GET | `/overtime_report_page` | `overtime_report_page` | static shell | - | 200 | 200 anonymous: HTML shell only; report data endpoint now admin-gated |
| GET | `/payroll_report_page` | `payroll_report_page` | static shell | - | 200 | 200 anonymous: HTML shell only; /api/admin/payroll/* requires admin |
| GET | `/master-admin` | `master_admin_root` | redirect-only | - | 303 | 303 to /master-admin/dashboard; the dashboard route requires a master-admin session |

## Public surface (intentional)

The kiosk/TV endpoints (`/call-display`, `/call-management`, `/calls/*`, `/ws/call-display`) are
unauthenticated by design because a television has no user session. Their boundary is the LAN plus a
browser Origin check on every mutation and on the WebSocket handshake; they must not be exposed beyond
the internal network. The registration and password-recovery endpoints are pre-authentication by
definition and are captcha-, validation- and rate-limit-protected.

The static report shells (`/leave_report_page`, `/hourlypass_Report_page`, `/overtime_report_page`,
`/payroll_report_page`, `/final_report_page`) return HTML with no data. The data endpoints behind them
were re-checked during this assessment: the two that could be called anonymously (`/get_hourly_pass_report`,
`/get_overtime_report`) are now admin-only.
