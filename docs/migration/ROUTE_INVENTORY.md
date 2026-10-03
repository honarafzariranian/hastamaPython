# Route inventory - every FastAPI endpoint (249 total)

Generated mechanically from the decorators in `app/main.py` and `app/api/routes/*.py`, so no
route can be silently missed. Router prefixes are already applied to the URLs.

## Migration progress

Phase 4 answered ten of the 249 routes, plus one deliberate addition. They
are registered in `routes/web.php` (not `routes/api.php`) because they are **browser-session**
endpoints — they mint a session, publish a CSRF cookie and read `system_config`. The `/api` prefix on
some of their paths is a naming artifact of the single-process Python application, and Laravel's `api`
middleware group has no session at all.

| Legacy route | Laravel route name | Notes |
|---|---|---|
| `POST /login_user` | `login_user` | JSON only; CAPTCHA → length caps → throttle → credentials; generic failures; first-login bcrypt upgrade |
| `GET /logout` | `logout` | `GET` because every existing logout link and the idle-timeout redirect issues one |
| `GET /captcha` | `captcha` | PNG; the code never leaves the session |
| `POST /captcha/refresh` | `captcha.refresh` | data URI for the refresh button |
| `GET /captcha/status` | `captcha.status` | remaining TTL, so the page can warn *before* submitting |
| `GET /api/csrf-token` | `api.csrf-token` | mints into the session and republishes the readable cookie |
| `GET /api/system-config` | `api.system-config` | the public subset, values kept as strings |
| `POST /api/session/destroy` | `api.session.destroy` | the `pagehide` beacon; revokes the registry row |
| `POST /forgot_password` | `forgot_password` | unified message; decoy request id for an unknown account |
| `POST /reset_password` | `reset_password` | verifies the code, sets bcrypt, revokes every session of that account |
| **added** `GET /api/me` | `api.me` | **not a port** — one call for "is this session good, and what is my role", behind `legacy.session` |

**Client convention.** The Vue client's shared axios instance (`resources/js/services/api.js`)
prefixes `/api`, so every call to one of these root-level paths has to pass `{ baseURL: '' }`.
Forgetting it does not fail loudly — the request lands on the SPA shell or on a same-named route
that rejects the verb, which is how `POST /api/login_user` came back **405** and login stopped
working. `python tools/api_paths.py` resolves every `api.*` call in the Vue source against
`php artisan route:list --json` and reports the calls whose route exists one prefix over.

Twenty-seven of the 249 routes are now answered by the Laravel application (the ten authentication
endpoints above, plus seventeen read endpoints in Phase 5), plus one deliberate addition. Added by
Phase 5 - the user-panel and control-centre **read** surface, every one of them a port of a specific
handler:

| Legacy route | Laravel route name | Notes |
|---|---|---|
| `GET /master-admin/api/dashboard/stats` | `master-admin.dashboard.stats` | eleven `COUNT(*)`, midnight **UTC** as in the Python |
| `GET /master-admin/api/dashboard/activity` | `master-admin.dashboard.activity` | `TOP (?)`, bound as an integer or SQL Server rejects it |
| `GET /master-admin/api/users` | `master-admin.users.index` | thirteen named columns - never `SELECT *`, which would publish `password` |
| `GET /master-admin/api/users/{username}` | `master-admin.users.show` | 404 body `{"detail": "کاربر یافت نشد."}` |
| `GET /master-admin/api/audit-logs` | `master-admin.audit-logs.index` | eleven optional filters, all bound |
| `GET /master-admin/api/audit-logs/{event_id}` | `master-admin.audit-logs.show` | **200 here where the Python raises `IndexError` and answers 500** - see `MIGRATION_STATUS.md` |
| `GET /master-admin/api/sessions` | `master-admin.sessions.index` | the only read endpoint that publishes `session_key` |
| `GET /master-admin/api/system-health` | `master-admin.system-health` | always 200, with per-probe sentinels |
| `GET /master-admin/api/search` | `master-admin.search` | four `TOP 10` queries into one flat list; key is `results`, not `data` |
| `GET /get_users` | `get_users` | `_ticket_actor`; excludes the caller |
| `GET /get_receivers` | `get_receivers` | bare array, master admins only |
| `GET /get_user_info` | `get_user_info` | the session user; no guard in the handler |
| `GET /get_user_info_report` | `get_user_info_report` | required `?username=`, resolved **before** `_require_auth`; IDOR check |
| `GET /get_today_date` | `get_today_date` | public Jalali triple, no `success` key |
| `GET /get_active_shifts` | `get_active_shifts` | `_require_admin`; Jalali day window over `shiftha` |
| `GET /get_leave_info` | `get_leave_info` | the caller's own rows |
| `GET /get_leave_requests` | `get_leave_requests` | `_require_admin`; bare array with dashed dates |

Not yet migrated from the same module: `POST /public/support-ticket`, and the whole of
`app/api/routes/master_admin.py` (including the admin half of password recovery).

`GET /login` is also registered, as the SPA shell: the Vue login view is Phase 6, so today it serves the
mount document the Python `/login` template's replacement will render into.

`Auth (existing)` is the strongest guard reachable inside the handler body:

| Value | Meaning |
|---|---|
| `bridge secret (HMAC, fail-closed)` | Shared secret compared with `hmac.compare_digest`; answers 503 while `ARAZ_BRIDGE_SECRET` is unset |
| `master-admin session` | Session must carry `is_master_admin`; denials are written to `security_events` |
| `admin session` | Session must carry `is_admin` (a master admin also satisfies it) |
| `same-site Origin + per-IP rate limit (kiosk)` | Deliberately **not** a login guard - the reception kiosk and the waiting queue work without a session, so the boundary is a same-site `Origin`/`Referer` check plus an IP rate limit (these paths are CSRF-exempt in `_CSRFMiddleware`) |
| `authenticated session` | Any logged-in user; per-row ownership checks happen inside the handler |
| `none (public)` | No guard in the handler |

## Reading the `none (public)` rows

The count looks alarming and is not one: those rows are the unauthenticated HTML page routes
(`/login`, `/register`, `/rules`, `/training*`, `/call-display`, `/call-management`,
`/ticket-kiosk`, `/master-admin`, `/admin/dashboard` - the two panel shells are rendered first and
the JavaScript layer then calls the guarded APIs), the health/static endpoints, and the public
`auth.py` endpoints (`GET /api/csrf-token`, `GET /captcha`, `POST /captcha/refresh`,
`GET /captcha/status`, `POST /login_user`, `POST /forgot_password`, `POST /reset_password`,
`POST /public/support-ticket`).

Authentication for the panel shells is enforced when the shell is rendered (`/master-admin` and
`/master-admin/{section}` redirect a non-master-admin to `/login`; `/admin*` redirects a
non-admin; `/call-management` requires `is_master_admin` through `_require_call_page_access`) and
by every API the shell then calls.

**Migration rule:** no route may become public in Laravel unless it is public here today, and
every `admin session` / `master-admin session` row must become a Laravel middleware + policy
backed by a server-side check - never a Vue route guard alone.

| # | Method | URL | File:line | Handler | Auth (existing) |
|---|---|---|---|---|---|
| 1 | GET | `/api/araz/config` | `app/api/routes/araz_api.py:95` | `get_device_config` | admin session |
| 2 | POST | `/api/araz/config` | `app/api/routes/araz_api.py:111` | `update_device_config` | admin session |
| 3 | GET | `/api/araz/test` | `app/api/routes/araz_api.py:132` | `test_device_connection` | admin session |
| 4 | GET | `/api/araz/time` | `app/api/routes/araz_api.py:176` | `get_device_time` | admin session |
| 5 | POST | `/api/araz/time/sync` | `app/api/routes/araz_api.py:200` | `sync_device_time` | admin session |
| 6 | GET | `/api/araz/records` | `app/api/routes/araz_api.py:218` | `get_device_records` | admin session |
| 7 | POST | `/api/araz/sync` | `app/api/routes/araz_api.py:264` | `sync_records_to_database` | admin session |
| 8 | POST | `/api/araz/bridge-sync` | `app/api/routes/araz_api.py:401` | `bridge_sync` | bridge secret (HMAC, fail-closed) |
| 9 | GET | `/api/csrf-token` | `app/api/routes/auth.py:166` | `csrf_token_bootstrap` | authenticated session |
| 10 | GET | `/captcha` | `app/api/routes/auth.py:186` | `get_captcha` | none (public) |
| 11 | POST | `/captcha/refresh` | `app/api/routes/auth.py:208` | `refresh_captcha` | none (public) |
| 12 | GET | `/captcha/status` | `app/api/routes/auth.py:228` | `captcha_status` | authenticated session |
| 13 | POST | `/login_user` | `app/api/routes/auth.py:307` | `login` | master-admin session |
| 14 | POST | `/forgot_password` | `app/api/routes/auth.py:501` | `forgot_password` | none (public) |
| 15 | POST | `/reset_password` | `app/api/routes/auth.py:578` | `reset_password` | none (public) |
| 16 | POST | `/public/support-ticket` | `app/api/routes/auth.py:679` | `public_support_ticket` | admin session |
| 17 | GET | `/api/automation` | `app/api/routes/automation.py:27` | `list_conversations` | authenticated session |
| 18 | POST | `/api/automation/{conversation_id}/complete` | `app/api/routes/automation.py:40` | `complete_conversation` | authenticated session |
| 19 | POST | `/api/automation/{conversation_id}/reopen-request` | `app/api/routes/automation.py:46` | `request_reopen` | authenticated session |
| 20 | POST | `/api/automation/{conversation_id}/reopen-request/{request_id}/approve` | `app/api/routes/automation.py:52` | `approve_reopen` | authenticated session |
| 21 | POST | `/api/automation` | `app/api/routes/automation.py:58` | `create_conversation` | authenticated session |
| 22 | GET | `/api/automation/{conversation_id}` | `app/api/routes/automation.py:69` | `get_conversation` | authenticated session |
| 23 | DELETE | `/api/automation/{conversation_id}` | `app/api/routes/automation.py:77` | `delete_conversation` | authenticated session |
| 24 | POST | `/api/automation/{conversation_id}/messages` | `app/api/routes/automation.py:85` | `add_message` | authenticated session |
| 25 | POST | `/api/automation/{conversation_id}/attachments` | `app/api/routes/automation.py:91` | `upload_attachment` | authenticated session |
| 26 | GET | `/api/automation/{conversation_id}/attachments/{attachment_id}` | `app/api/routes/automation.py:106` | `download_attachment` | authenticated session |
| 27 | POST | `/api/calls` | `app/api/routes/call_system.py:363` | `create_call` | admin session |
| 28 | POST | `/api/calls/repeat` | `app/api/routes/call_system.py:416` | `repeat_last_call` | admin session |
| 29 | POST | `/api/calls/test-display` | `app/api/routes/call_system.py:481` | `test_display` | admin session |
| 30 | POST | `/api/calls/test-voice` | `app/api/routes/call_system.py:508` | `test_voice` | admin session |
| 31 | POST | `/api/calls/test-audio` | `app/api/routes/call_system.py:535` | `test_audio` | admin session |
| 32 | GET | `/api/calls/audio-status` | `app/api/routes/call_system.py:574` | `audio_status` | none (public) |
| 33 | GET | `/api/calls/display-queue` | `app/api/routes/call_system.py:601` | `get_display_queue` | none (public) |
| 34 | GET | `/api/calls/waiting-queue` | `app/api/routes/call_system.py:617` | `get_waiting_queue` | none (public) |
| 35 | POST | `/api/calls/waiting-queue` | `app/api/routes/call_system.py:643` | `add_to_waiting_queue` | admin session |
| 36 | DELETE | `/api/calls/waiting-queue/{item_id}` | `app/api/routes/call_system.py:678` | `remove_from_waiting_queue` | same-site Origin + per-IP rate limit (kiosk) |
| 37 | POST | `/api/calls/waiting-queue/{item_id}/call` | `app/api/routes/call_system.py:696` | `call_from_queue` | admin session |
| 38 | POST | `/api/calls/reset-display` | `app/api/routes/call_system.py:762` | `reset_display` | same-site Origin + per-IP rate limit (kiosk) |
| 39 | POST | `/api/calls/refresh-display` | `app/api/routes/call_system.py:784` | `refresh_display` | admin session |
| 40 | POST | `/api/calls/remove` | `app/api/routes/call_system.py:805` | `remove_call` | admin session |
| 41 | GET | `/api/calls/recent` | `app/api/routes/call_system.py:839` | `recent_calls` | admin session |
| 42 | DELETE | `/api/calls/recent` | `app/api/routes/call_system.py:867` | `clear_recent_calls` | admin session |
| 43 | GET | `/api/calls/status` | `app/api/routes/call_system.py:890` | `display_status` | none (public) |
| 44 | GET | `/api/calls/slides` | `app/api/routes/call_system.py:916` | `list_slides` | none (public) |
| 45 | GET | `/api/calls/slides/active` | `app/api/routes/call_system.py:939` | `list_active_slides` | none (public) |
| 46 | POST | `/api/calls/slides/upload` | `app/api/routes/call_system.py:963` | `upload_slide` | admin session |
| 47 | PUT | `/api/calls/slides/{slide_id}/toggle` | `app/api/routes/call_system.py:1040` | `toggle_slide` | admin session |
| 48 | DELETE | `/api/calls/slides/{slide_id}` | `app/api/routes/call_system.py:1060` | `delete_slide` | admin session |
| 49 | POST | `/api/queue/take` | `app/api/routes/call_system.py:1096` | `take_queue_ticket` | same-site Origin + per-IP rate limit (kiosk) |
| 50 | GET | `/api/label/config` | `app/api/routes/call_system.py:1192` | `label_config` | none (public) |
| 51 | POST | `/api/label/print-document` | `app/api/routes/call_system.py:1224` | `label_print_document` | master-admin session |
| 52 | POST | `/api/queue/print` | `app/api/routes/call_system.py:1275` | `print_queue_ticket` | same-site Origin + per-IP rate limit (kiosk) |
| 53 | GET | `/api/queue/list` | `app/api/routes/call_system.py:1353` | `list_queue_tickets` | admin session |
| 54 | GET | `/api/queue/stats` | `app/api/routes/call_system.py:1406` | `queue_stats` | none (public) |
| 55 | POST | `/api/queue/call/{ticket_id}` | `app/api/routes/call_system.py:1437` | `call_queue_ticket` | admin session |
| 56 | DELETE | `/api/queue/{ticket_id}` | `app/api/routes/call_system.py:1495` | `delete_queue_ticket` | admin session |
| 57 | DELETE | `/api/queue` | `app/api/routes/call_system.py:1524` | `delete_waiting_queue` | admin session |
| 58 | POST | `/api/queue/complete/{ticket_id}` | `app/api/routes/call_system.py:1548` | `complete_queue_ticket` | admin session |
| 59 | POST | `/api/queue/call-next` | `app/api/routes/call_system.py:1573` | `call_next_ticket` | admin session |
| 60 | GET | `/api/queue/ticket/{ticket_number}` | `app/api/routes/call_system.py:1642` | `get_queue_ticket` | same-site Origin + per-IP rate limit (kiosk) |
| 61 | PUT | `/api/queue/ticket/{ticket_number}` | `app/api/routes/call_system.py:1685` | `edit_queue_ticket` | same-site Origin + per-IP rate limit (kiosk) |
| 62 | WEBSOCKET | `/api/ws/call-display` | `app/api/routes/call_system.py:1743` | `call_display_ws` | none (public) |
| 63 | GET | `/health` | `app/api/routes/health.py:10` | `health` | none (public) |
| 64 | GET | `/health/database` | `app/api/routes/health.py:15` | `health_database` | none (public) |
| 65 | GET | `/master-admin/api/dashboard/stats` | `app/api/routes/master_admin.py:179` | `dashboard_stats` | master-admin session |
| 66 | GET | `/master-admin/api/dashboard/activity` | `app/api/routes/master_admin.py:243` | `dashboard_activity` | master-admin session |
| 67 | GET | `/master-admin/api/subscriptions/summary` | `app/api/routes/master_admin.py:274` | `subscriptions_summary` | master-admin session |
| 68 | GET | `/master-admin/api/subscriptions` | `app/api/routes/master_admin.py:310` | `list_subscriptions` | master-admin session |
| 69 | GET | `/master-admin/api/subscriptions/{subscription_id}` | `app/api/routes/master_admin.py:357` | `get_subscription_detail` | master-admin session |
| 70 | PATCH | `/master-admin/api/subscriptions/{subscription_id}` | `app/api/routes/master_admin.py:394` | `update_subscription` | master-admin session |
| 71 | GET | `/master-admin/api/audit-logs` | `app/api/routes/master_admin.py:475` | `list_audit_logs` | master-admin session |
| 72 | GET | `/master-admin/api/audit-logs/{event_id}` | `app/api/routes/master_admin.py:550` | `get_audit_event` | master-admin session |
| 73 | DELETE | `/master-admin/api/audit-logs/{event_id}` | `app/api/routes/master_admin.py:576` | `delete_audit_event` | master-admin session |
| 74 | GET | `/master-admin/api/users` | `app/api/routes/master_admin.py:603` | `list_users` | master-admin session |
| 75 | GET | `/master-admin/api/users/{username}` | `app/api/routes/master_admin.py:651` | `get_user_detail` | master-admin session |
| 76 | POST | `/master-admin/api/users/{username}/toggle-status` | `app/api/routes/master_admin.py:709` | `toggle_user_status` | master-admin session |
| 77 | POST | `/master-admin/api/users/{username}/change-role` | `app/api/routes/master_admin.py:750` | `change_user_role` | master-admin session |
| 78 | GET | `/master-admin/api/sessions` | `app/api/routes/master_admin.py:796` | `list_sessions` | master-admin session |
| 79 | POST | `/master-admin/api/sessions/{session_key}/terminate` | `app/api/routes/master_admin.py:838` | `terminate_user_session` | master-admin session |
| 80 | DELETE | `/master-admin/api/sessions/{session_key}` | `app/api/routes/master_admin.py:851` | `delete_user_session` | master-admin session |
| 81 | POST | `/master-admin/api/sessions/terminate-all` | `app/api/routes/master_admin.py:866` | `terminate_all_sessions` | master-admin session |
| 82 | DELETE | `/master-admin/api/sessions` | `app/api/routes/master_admin.py:894` | `delete_all_sessions` | master-admin session |
| 83 | GET | `/master-admin/api/password-resets` | `app/api/routes/master_admin.py:922` | `list_password_resets` | master-admin session |
| 84 | POST | `/master-admin/api/password-resets/{request_id}/approve` | `app/api/routes/master_admin.py:962` | `approve_reset` | master-admin session |
| 85 | POST | `/master-admin/api/password-resets/{request_id}/reject` | `app/api/routes/master_admin.py:975` | `reject_reset` | master-admin session |
| 86 | DELETE | `/master-admin/api/password-resets/{request_id}` | `app/api/routes/master_admin.py:988` | `delete_password_reset` | master-admin session |
| 87 | GET | `/master-admin/api/security` | `app/api/routes/master_admin.py:1019` | `list_security_events` | master-admin session |
| 88 | POST | `/master-admin/api/security/{event_id}/resolve` | `app/api/routes/master_admin.py:1061` | `resolve_security_event` | master-admin session |
| 89 | DELETE | `/master-admin/api/security/{event_id}` | `app/api/routes/master_admin.py:1087` | `delete_security_event` | master-admin session |
| 90 | GET | `/master-admin/api/errors` | `app/api/routes/master_admin.py:1114` | `list_errors` | master-admin session |
| 91 | POST | `/master-admin/api/errors/{error_id}/resolve` | `app/api/routes/master_admin.py:1159` | `resolve_error` | master-admin session |
| 92 | DELETE | `/master-admin/api/errors/{error_id}` | `app/api/routes/master_admin.py:1185` | `delete_error` | master-admin session |
| 93 | GET | `/master-admin/api/admin-actions` | `app/api/routes/master_admin.py:1212` | `list_admin_actions` | master-admin session |
| 94 | DELETE | `/master-admin/api/admin-actions/{action_id}` | `app/api/routes/master_admin.py:1254` | `delete_admin_action` | master-admin session |
| 95 | GET | `/master-admin/api/system-health` | `app/api/routes/master_admin.py:1281` | `system_health` | master-admin session |
| 96 | GET | `/master-admin/api/search` | `app/api/routes/master_admin.py:1328` | `global_search` | master-admin session |
| 97 | GET | `/master-admin/api/tickets` | `app/api/routes/master_admin.py:1384` | `list_all_tickets` | master-admin session |
| 98 | GET | `/master-admin/api/tickets/stats` | `app/api/routes/master_admin.py:1412` | `ticket_stats` | master-admin session |
| 99 | GET | `/master-admin/api/tickets/{ticket_id}` | `app/api/routes/master_admin.py:1435` | `get_ticket_detail` | master-admin session |
| 100 | PATCH | `/master-admin/api/tickets/{ticket_id}` | `app/api/routes/master_admin.py:1454` | `update_ticket_admin` | master-admin session |
| 101 | POST | `/master-admin/api/tickets/{ticket_id}/reply` | `app/api/routes/master_admin.py:1482` | `reply_ticket_admin` | master-admin session |
| 102 | DELETE | `/master-admin/api/tickets/{ticket_id}` | `app/api/routes/master_admin.py:1510` | `delete_ticket_admin` | master-admin session |
| 103 | GET | `/master-admin/api/tickets/categories/all` | `app/api/routes/master_admin.py:1542` | `ticket_categories_admin` | master-admin session |
| 104 | GET | `/master-admin/api/tickets/users/all` | `app/api/routes/master_admin.py:1556` | `ticket_users_admin` | master-admin session |
| 105 | GET | `/master-admin/api/config` | `app/api/routes/master_admin.py:1589` | `get_config` | master-admin session |
| 106 | POST | `/master-admin/api/config` | `app/api/routes/master_admin.py:1605` | `update_config` | master-admin session |
| 107 | GET | `/master-admin/api/lan-access` | `app/api/routes/master_admin.py:1667` | `get_lan_access` | master-admin session |
| 108 | POST | `/master-admin/api/lan-access` | `app/api/routes/master_admin.py:1676` | `set_lan_access` | master-admin session |
| 109 | POST | `/master-admin/api/lan-access/selftest` | `app/api/routes/master_admin.py:1720` | `lan_access_selftest` | master-admin session |
| 110 | GET | `/master-admin/api/outage` | `app/api/routes/master_admin.py:1750` | `get_outage` | master-admin session |
| 111 | POST | `/master-admin/api/outage` | `app/api/routes/master_admin.py:1759` | `set_outage` | master-admin session |
| 112 | POST | `/master-admin/api/outage/check` | `app/api/routes/master_admin.py:1798` | `check_outage` | master-admin session |
| 113 | POST | `/master-admin/api/outage/manual` | `app/api/routes/master_admin.py:1826` | `set_outage_manual` | master-admin session |
| 114 | GET | `/master-admin/api/iran-access` | `app/api/routes/master_admin.py:1862` | `get_iran_access` | master-admin session |
| 115 | POST | `/master-admin/api/iran-access` | `app/api/routes/master_admin.py:1871` | `set_iran_access` | master-admin session |
| 116 | POST | `/master-admin/api/iran-access/check` | `app/api/routes/master_admin.py:1908` | `check_iran_access` | master-admin session |
| 117 | POST | `/master-admin/api/iran-access/refresh` | `app/api/routes/master_admin.py:1944` | `refresh_iran_access` | master-admin session |
| 118 | POST | `/master-admin/api/iran-access/counters/reset` | `app/api/routes/master_admin.py:1979` | `reset_iran_access_counters` | master-admin session |
| 119 | GET | `/master-admin/api/login-experience` | `app/api/routes/master_admin.py:2003` | `get_login_experience` | master-admin session |
| 120 | POST | `/master-admin/api/login-experience` | `app/api/routes/master_admin.py:2012` | `set_login_experience` | master-admin session |
| 121 | GET | `/master-admin/api/printers` | `app/api/routes/master_admin.py:2052` | `get_printers` | master-admin session |
| 122 | GET | `/api/admin/notification-targets` | `app/api/routes/notifications.py:243` | `target_options` | admin session |
| 123 | GET | `/api/admin/notifications` | `app/api/routes/notifications.py:261` | `admin_list` | admin session |
| 124 | POST | `/api/admin/notifications/read-all` | `app/api/routes/notifications.py:316` | `admin_mark_all_read` | admin session |
| 125 | POST | `/api/admin/notifications` | `app/api/routes/notifications.py:342` | `create_notification` | admin session |
| 126 | PUT | `/api/admin/notifications/{notification_id}` | `app/api/routes/notifications.py:371` | `update_notification` | admin session |
| 127 | POST | `/api/admin/notifications/{notification_id}/publish` | `app/api/routes/notifications.py:403` | `publish_notification` | admin session |
| 128 | POST | `/api/admin/notifications/{notification_id}/read` | `app/api/routes/notifications.py:419` | `admin_mark_read` | admin session |
| 129 | POST | `/api/admin/notifications/{notification_id}/unread` | `app/api/routes/notifications.py:446` | `admin_mark_unread` | admin session |
| 130 | DELETE | `/api/admin/notifications/{notification_id}` | `app/api/routes/notifications.py:471` | `delete_notification` | admin session |
| 131 | POST | `/api/admin/notifications/{notification_id}/archive` | `app/api/routes/notifications.py:487` | `archive_notification` | admin session |
| 132 | DELETE | `/api/admin/notifications/delete-all` | `app/api/routes/notifications.py:503` | `delete_all_notifications` | admin session |
| 133 | GET | `/api/notifications` | `app/api/routes/notifications.py:519` | `user_list` | admin session |
| 134 | GET | `/api/notifications/unread-count` | `app/api/routes/notifications.py:560` | `unread_count` | authenticated session |
| 135 | GET | `/api/notifications/poll` | `app/api/routes/notifications.py:573` | `notification_poll` | authenticated session |
| 136 | GET | `/api/notifications/stream` | `app/api/routes/notifications.py:601` | `notification_stream` | authenticated session |
| 137 | GET | `/api/notifications/admin-stream` | `app/api/routes/notifications.py:661` | `admin_request_stream` | admin session |
| 138 | GET | `/api/notifications/{notification_id}` | `app/api/routes/notifications.py:668` | `notification_detail` | authenticated session |
| 139 | POST | `/api/notifications/{notification_id}/read` | `app/api/routes/notifications.py:699` | `mark_read` | none (public) |
| 140 | POST | `/api/notifications/{notification_id}/unread` | `app/api/routes/notifications.py:703` | `mark_unread` | none (public) |
| 141 | DELETE | `/api/notifications/{notification_id}` | `app/api/routes/notifications.py:707` | `dismiss` | none (public) |
| 142 | POST | `/api/notifications/read-all` | `app/api/routes/notifications.py:711` | `mark_all_read` | authenticated session |
| 143 | GET | `/registration/check-username` | `app/api/routes/registration.py:116` | `check_username` | none (public) |
| 144 | GET | `/registration/check-national-id` | `app/api/routes/registration.py:155` | `check_national_id` | none (public) |
| 145 | POST | `/registration/submit` | `app/api/routes/registration.py:187` | `submit_registration` | none (public) |
| 146 | GET | `/registration/status/{request_id}` | `app/api/routes/registration.py:328` | `check_request_status` | none (public) |
| 147 | GET | `/registration/admin/requests` | `app/api/routes/registration.py:364` | `list_registration_requests` | admin session |
| 148 | GET | `/registration/admin/requests/{request_id}` | `app/api/routes/registration.py:435` | `get_registration_request` | admin session |
| 149 | POST | `/registration/admin/requests/{request_id}/approve` | `app/api/routes/registration.py:470` | `approve_registration` | admin session |
| 150 | POST | `/registration/admin/requests/{request_id}/reject` | `app/api/routes/registration.py:583` | `reject_registration` | admin session |
| 151 | GET | `/registration/departments` | `app/api/routes/registration.py:631` | `get_departments` | none (public) |
| 152 | GET | `/registration/work-schedules` | `app/api/routes/registration.py:645` | `get_work_schedules` | none (public) |
| 153 | GET | `/registration/active-users` | `app/api/routes/registration.py:667` | `get_active_users` | admin session |
| 154 | GET | `/api/tickets/categories` | `app/api/routes/ticketing.py:90` | `categories` | authenticated session |
| 155 | GET | `/api/tickets/users` | `app/api/routes/ticketing.py:100` | `ticket_users` | master-admin session |
| 156 | GET | `/api/tickets` | `app/api/routes/ticketing.py:130` | `list_tickets` | master-admin session |
| 157 | POST | `/api/tickets` | `app/api/routes/ticketing.py:149` | `create_ticket` | master-admin session |
| 158 | GET | `/api/tickets/{ticket_id}` | `app/api/routes/ticketing.py:175` | `get_ticket` | master-admin session |
| 159 | POST | `/api/tickets/{ticket_id}/messages` | `app/api/routes/ticketing.py:188` | `add_message` | master-admin session |
| 160 | PATCH | `/api/tickets/{ticket_id}` | `app/api/routes/ticketing.py:204` | `update_ticket` | master-admin session |
| 161 | POST | `/api/tickets/{ticket_id}/attachments` | `app/api/routes/ticketing.py:226` | `upload_attachment` | master-admin session |
| 162 | GET | `/api/tickets/{ticket_id}/attachments/{attachment_id}` | `app/api/routes/ticketing.py:255` | `download_attachment` | master-admin session |
| 163 | GET | `/favicon.ico` | `app/main.py:1037` | `favicon` | none (public) |
| 164 | GET | `/offline` | `app/main.py:1042` | `offline_page` | none (public) |
| 165 | GET | `/sw.js` | `app/main.py:1059` | `offline_service_worker` | none (public) |
| 166 | GET | `/iran-only` | `app/main.py:1089` | `iran_only_page` | none (public) |
| 167 | GET | `/iran-only/check` | `app/main.py:1105` | `iran_only_check` | none (public) |
| 168 | GET | `/` | `app/main.py:1201` | `landing_page` | none (public) |
| 169 | GET | `/robots.txt` | `app/main.py:1205` | `robots_txt` | none (public) |
| 170 | GET | `/sitemap.xml` | `app/main.py:1209` | `sitemap_xml` | none (public) |
| 171 | GET | `/login` | `app/main.py:1213` | `home` | none (public) |
| 172 | GET | `/register` | `app/main.py:1217` | `register_page` | none (public) |
| 173 | GET | `/rules` | `app/main.py:1221` | `rules` | none (public) |
| 174 | GET | `/training` | `app/main.py:1264` | `training_hub` | none (public) |
| 175 | GET | `/training/{category}` | `app/main.py:1278` | `training_category` | none (public) |
| 176 | GET | `/training/lesson/{lesson_id}` | `app/main.py:1308` | `training_lesson` | none (public) |
| 177 | GET | `/api/training/search` | `app/main.py:1341` | `training_search` | none (public) |
| 178 | GET | `/master-admin` | `app/main.py:1371` | `master_admin_root` | none (public) |
| 179 | GET | `/master-admin/{section}` | `app/main.py:1375` | `master_admin_page` | master-admin session |
| 180 | GET | `/call-display` | `app/main.py:1446` | `call_display` | none (public) |
| 181 | GET | `/call-management` | `app/main.py:1454` | `call_management` | none (public) |
| 182 | GET | `/ticket-kiosk` | `app/main.py:1462` | `ticket_kiosk` | none (public) |
| 183 | GET | `/ticket-print` | `app/main.py:1467` | `ticket_print_page` | none (public) |
| 184 | GET | `/user_panel` | `app/main.py:1476` | `user_panel` | authenticated session |
| 185 | GET | `/api/date` | `app/main.py:1792` | `get_date` | none (public) |
| 186 | GET | `/get_users` | `app/main.py:1801` | `get_users` | authenticated session |
| 187 | GET | `/get_user_info` | `app/main.py:1844` | `get_user_info` | authenticated session |
| 188 | GET | `/get_user_info_report` | `app/main.py:1888` | `get_user_info_report` | admin session |
| 189 | GET | `/get_leave_info` | `app/main.py:1941` | `get_leave_info` | authenticated session |
| 190 | GET | `/get_receivers` | `app/main.py:2020` | `get_receivers` | authenticated session |
| 191 | POST | `/submit_leave` | `app/main.py:2125` | `submit_leave` | authenticated session |
| 192 | POST | `/submit_overtime` | `app/main.py:2180` | `submit_overtime` | authenticated session |
| 193 | POST | `/submit_hourly_pass` | `app/main.py:2234` | `submit_hourly_pass` | authenticated session |
| 194 | POST | `/submit_ticket` | `app/main.py:2431` | `submit_ticket` | authenticated session |
| 195 | GET | `/get_user_info_final_report_page/{username}` | `app/main.py:2488` | `get_user_info_final_report_page` | admin session |
| 196 | POST | `/get_hozoor_filtered` | `app/main.py:2518` | `get_hozoor_filtered` | admin session |
| 197 | POST | `/upload-profile-image` | `app/main.py:2566` | `upload_profile_image` | authenticated session |
| 198 | POST | `/delete-profile-image` | `app/main.py:2643` | `delete_profile_image` | authenticated session |
| 199 | POST | `/api/admin/employment-status` | `app/main.py:2769` | `update_employment_status` | admin session |
| 200 | GET | `/admin` | `app/main.py:2863` | `admin` | admin session |
| 201 | GET | `/admin/dashboard` | `app/main.py:3058` | `admin_dashboard` | none (public) |
| 202 | GET | `/admin/{section}` | `app/main.py:3062` | `admin_section` | none (public) |
| 203 | POST | `/api/admin/payroll/save` | `app/main.py:3094` | `save_admin_payroll` | admin session |
| 204 | GET | `/api/admin/payroll/load` | `app/main.py:3158` | `load_admin_payroll` | admin session |
| 205 | POST | `/add_user` | `app/main.py:3202` | `add_user` | admin session |
| 206 | POST | `/update_user` | `app/main.py:3355` | `update_user` | admin session |
| 207 | GET | `/get_shifts/{username}/{year}/{month}` | `app/main.py:3485` | `get_shifts` | admin session |
| 208 | POST | `/add_shift` | `app/main.py:3526` | `add_shift` | admin session |
| 209 | POST | `/update_shift` | `app/main.py:3582` | `update_shift` | admin session |
| 210 | POST | `/delete_shift/{shift_id}` | `app/main.py:3640` | `delete_shift` | admin session |
| 211 | GET | `/get_active_shifts` | `app/main.py:3663` | `get_active_shifts` | admin session |
| 212 | GET | `/get_leave_requests` | `app/main.py:3714` | `get_leave_requests` | admin session |
| 213 | POST | `/update_leave_status` | `app/main.py:3767` | `update_leave_status` | admin session |
| 214 | POST | `/generate_individual_report` | `app/main.py:3798` | `generate_individual_report` | admin session |
| 215 | GET | `/leave_report_page` | `app/main.py:3863` | `report_page` | authenticated session |
| 216 | GET | `/fetch_user_data` | `app/main.py:3870` | `fetch_user_data` | admin session |
| 217 | GET | `/get_hourly_pass_requests` | `app/main.py:3902` | `get_hourly_pass_requests` | admin session |
| 218 | POST | `/change_hourly_pass_status` | `app/main.py:3951` | `change_hourly_pass_status` | admin session |
| 219 | GET | `/hourlypass_Report_page` | `app/main.py:3984` | `hourly_pass_report_page` | authenticated session |
| 220 | POST | `/get_hourly_pass_report` | `app/main.py:3991` | `get_hourly_pass_report` | admin session |
| 221 | POST | `/update_hourly_pass_status` | `app/main.py:4054` | `update_hourly_pass_status` | admin session |
| 222 | GET | `/get_overtime_requests` | `app/main.py:4078` | `get_overtime_requests` | admin session |
| 223 | POST | `/update_overtime_status` | `app/main.py:4143` | `update_overtime_status` | admin session |
| 224 | POST | `/update_overtime_Indivisual_status` | `app/main.py:4177` | `update_overtime_indivisual_status` | admin session |
| 225 | GET | `/overtime_report_page` | `app/main.py:4207` | `overtime_report_page` | authenticated session |
| 226 | GET | `/overtime_report` | `app/main.py:4214` | `overtime_report` | admin session |
| 227 | POST | `/get_overtime_report` | `app/main.py:4239` | `get_overtime_report` | admin session |
| 228 | GET | `/get_ticket_requests_admin` | `app/main.py:4299` | `get_ticket_requests_admin` | authenticated session |
| 229 | POST | `/delete-ticket` | `app/main.py:4358` | `delete_ticket` | admin session |
| 230 | POST | `/update_ticket` | `app/main.py:4395` | `update_ticket` | admin session |
| 231 | GET | `/get_ticket_requests` | `app/main.py:4448` | `get_ticket_requests` | authenticated session |
| 232 | POST | `/update_ticket_status` | `app/main.py:4492` | `update_ticket_status` | admin session |
| 233 | GET | `/get_ticket_details/{ticket_id}` | `app/main.py:4530` | `get_ticket_details` | admin session |
| 234 | GET | `/get_ticket_details_payam/{ticket_id}` | `app/main.py:4602` | `get_ticket_details_payam` | admin session |
| 235 | POST | `/add_ticket_response` | `app/main.py:4674` | `add_ticket_response` | none (public) |
| 236 | POST | `/add_ticket_response_userpanel` | `app/main.py:4678` | `add_ticket_response_userpanel` | none (public) |
| 237 | POST | `/mark_ticket_as_read/{ticket_id}` | `app/main.py:4682` | `mark_ticket_as_read` | admin session |
| 238 | GET | `/get_hozoor/{username}` | `app/main.py:4759` | `get_hozoor` | admin session |
| 239 | POST | `/sabt_hozoor` | `app/main.py:5039` | `sabt_hozoor` | admin session |
| 240 | POST | `/sabt_hozoor_checkin` | `app/main.py:5127` | `sabt_hozoor_checkin` | admin session |
| 241 | POST | `/sabt_hozoor_checkout` | `app/main.py:5227` | `sabt_hozoor_checkout` | admin session |
| 242 | GET | `/get_hozoor_today` | `app/main.py:5308` | `get_hozoor_today` | admin session |
| 243 | GET | `/payroll_report_page` | `app/main.py:5438` | `payroll_report_page` | authenticated session |
| 244 | GET | `/final_report_page` | `app/main.py:5446` | `final_report` | authenticated session |
| 245 | GET | `/download_pdf` | `app/main.py:5469` | `download_pdf` | authenticated session |
| 246 | GET | `/get_today_date` | `app/main.py:5556` | `get_today_date` | none (public) |
| 247 | GET | `/logout` | `app/main.py:5565` | `logout` | authenticated session |
| 248 | GET | `/api/system-config` | `app/main.py:5581` | `public_system_config` | none (public) |
| 249 | POST | `/api/session/destroy` | `app/main.py:5616` | `destroy_session` | authenticated session |
