# Database schema — `userDB` on `localhost\SQLEXPRESS`

Extracted from the live server with `INFORMATION_SCHEMA` + `sys.indexes` + `sys.triggers`.
The row counts are a **snapshot**: the FastAPI application keeps serving while the migration
proceeds, so `audit_logs` and `user_sessions` grow between runs. Only the schema is stable.
**44 base tables, 0 views, 0 application stored procedures, 9 triggers.**
Only the SQL Server diagram helper procedures (`sp_helpdiagrams`, `sp_creatediagram`, …) and
`fn_diagramobjects` exist under `ROUTINES`; nothing in the application calls them.

## Generation census

The database contains three schema generations. They must all be migrated as-is; the migration
may not unify or rename them.

| Generation | Tables |
|---|---|
| legacy (Araz/payroll generation) | akhrpss_table, avalpss_table, beynpss_table, ezafe_table, ezafe_total_table, hozoor, leave_report, mrkhc_table, shiftha, sysdiagrams, ticket_table, totalpass_table, user_table |
| first Hastama build | admin_actions, admin_payroll_calculations, audit_logs, customer_subscriptions, password_reset_requests, security_events, system_config, user_sessions |
| newest features | dbo.admin_actions, dbo.admin_payroll_calculations, dbo.akhrpss_table, dbo.audit_logs, dbo.automation_attachments, dbo.automation_conversations, dbo.automation_messages, dbo.automation_participants, dbo.automation_reopen_requests, dbo.avalpss_table, dbo.beynpss_table, dbo.customer_subscriptions, dbo.display_queue, dbo.ezafe_table, dbo.ezafe_total_table, dbo.hozoor, dbo.leave_report, dbo.mrkhc_table, dbo.notification_targets, dbo.notifications, dbo.password_reset_requests, dbo.push_subscriptions, dbo.queue_tickets, dbo.reception_calls, dbo.security_events, dbo.shiftha, dbo.slides, dbo.sysdiagrams, dbo.system_config, dbo.system_errors, dbo.ticket_attachments, dbo.ticket_categories, dbo.ticket_events, dbo.ticket_messages, dbo.ticket_table, dbo.ticket_tag_relations, dbo.ticket_tags, dbo.tickets, dbo.totalpass_table, dbo.user_notifications, dbo.user_registration_requests, dbo.user_sessions, dbo.user_table, dbo.waiting_queue |

Tables carrying **live triggers** (business logic in the database — see DATABASE_TRIGGERS.md):
akhrpss_table, avalpss_table, beynpss_table, ezafe_table, mrkhc_table.

## Summary

| Table | Rows | Cols | PK | Identity | Generation | Triggers |
|---|---:|---:|---|---|---|---|
| `dbo.admin_actions` | 133 | 13 | id | id | first Hastama build | — |
| `dbo.admin_payroll_calculations` | 54 | 9 | id | id | first Hastama build | — |
| `dbo.akhrpss_table` | 0 | 6 | id | id | legacy (Araz/payroll generation) | yes |
| `dbo.audit_logs` | 3,509 | 20 | id | id | first Hastama build | — |
| `dbo.automation_attachments` | 0 | 9 | id | id | newest features | — |
| `dbo.automation_conversations` | 4 | 6 | id | id | newest features | — |
| `dbo.automation_messages` | 6 | 5 | id | id | newest features | — |
| `dbo.automation_participants` | 8 | 2 | conversation_id, username | — | newest features | — |
| `dbo.automation_reopen_requests` | 0 | 6 | id | id | newest features | — |
| `dbo.avalpss_table` | 5 | 6 | id | id | legacy (Araz/payroll generation) | yes |
| `dbo.beynpss_table` | 1 | 6 | id | id | legacy (Araz/payroll generation) | yes |
| `dbo.customer_subscriptions` | 1 | 21 | id | id | first Hastama build | — |
| `dbo.display_queue` | 1 | 6 | id | id | newest features | — |
| `dbo.ezafe_table` | 2 | 8 | id | id | legacy (Araz/payroll generation) | yes |
| `dbo.ezafe_total_table` | 1 | 2 | — | — | legacy (Araz/payroll generation) | — |
| `dbo.hozoor` | 59 | 5 | — | id | legacy (Araz/payroll generation) | — |
| `dbo.leave_report` | 0 | 3 | — | — | legacy (Araz/payroll generation) | — |
| `dbo.mrkhc_table` | 4 | 7 | id | id | legacy (Araz/payroll generation) | yes |
| `dbo.notification_targets` | 2 | 2 | notification_id, target_value | — | newest features | — |
| `dbo.notifications` | 2 | 16 | id | id | newest features | — |
| `dbo.password_reset_requests` | 7 | 15 | id | id | first Hastama build | — |
| `dbo.push_subscriptions` | 3 | 10 | id | id | newest features | — |
| `dbo.queue_tickets` | 42 | 15 | id | id | newest features | — |
| `dbo.reception_calls` | 3 | 6 | id | id | newest features | — |
| `dbo.security_events` | 4 | 12 | id | id | first Hastama build | — |
| `dbo.shiftha` | 4 | 15 | id | id | legacy (Araz/payroll generation) | — |
| `dbo.slides` | 4 | 6 | id | id | newest features | — |
| `dbo.sysdiagrams` | 0 | 5 | diagram_id | diagram_id | legacy (Araz/payroll generation) | — |
| `dbo.system_config` | 12 | 5 | config_key | — | first Hastama build | — |
| `dbo.system_errors` | 0 | 18 | id | id | newest features | — |
| `dbo.ticket_attachments` | 0 | 9 | id | id | newest features | — |
| `dbo.ticket_categories` | 4 | 6 | id | id | newest features | — |
| `dbo.ticket_events` | 19 | 6 | id | id | newest features | — |
| `dbo.ticket_messages` | 19 | 8 | id | id | newest features | — |
| `dbo.ticket_table` | 1 | 9 | id | id | legacy (Araz/payroll generation) | — |
| `dbo.ticket_tag_relations` | 0 | 3 | tag_id, ticket_id | — | newest features | — |
| `dbo.ticket_tags` | 0 | 4 | id | id | newest features | — |
| `dbo.tickets` | 3 | 16 | id | id | newest features | — |
| `dbo.totalpass_table` | 6 | 6 | id | id | legacy (Araz/payroll generation) | — |
| `dbo.user_notifications` | 2 | 7 | id | id | newest features | — |
| `dbo.user_registration_requests` | 0 | 20 | id | id | newest features | — |
| `dbo.user_sessions` | 303 | 10 | id | id | first Hastama build | — |
| `dbo.user_table` | 16 | 25 | id | — | legacy (Araz/payroll generation) | — |
| `dbo.waiting_queue` | 1 | 7 | id | id | newest features | — |

## Full column detail

### `dbo.admin_actions` — 133 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_admin_actions_action` on `action,created_at`
* `IX_admin_actions_admin` on `admin_username,created_at`
* `IX_admin_actions_created` on `created_at`
* `IX_admin_actions_target` on `target_username,created_at`
* `PK_admin_actions` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `action_id` | `varchar(32)` | NO | — |
| `admin_username` | `nvarchar(255)` | NO | — |
| `action` | `varchar(64)` | NO | — |
| `target_username` | `nvarchar(255)` | YES | — |
| `target_type` | `varchar(64)` | YES | — |
| `target_id` | `nvarchar(128)` | YES | — |
| `description` | `nvarchar(1000)` | YES | — |
| `before_data` | `nvarchar(max)` | YES | — |
| `after_data` | `nvarchar(max)` | YES | — |
| `ip_address` | `varchar(45)` | YES | — |
| `request_id` | `varchar(32)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.admin_payroll_calculations` — 54 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__admin_pa__3213E83F1881DCF5` (UNIQUE) on `id`
* `UQ_admin_payroll_row` (UNIQUE) on `calculation_type,period_year,period_month,username`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `calculation_type` | `nvarchar(32)` | NO | — |
| `period_year` | `int` | NO | — |
| `period_month` | `nvarchar(40)` | NO | — |
| `username` | `nvarchar(255)` | NO | — |
| `payload_json` | `nvarchar(max)` | NO | — |
| `saved_by` | `nvarchar(255)` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.akhrpss_table` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__akhrpss___3213E83F2A9580D9` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `officialTime` | `time` | YES | — |
| `exitTime` | `time` | YES | — |
| `date` | `date` | YES | — |
| `username` | `nchar(10)` | YES | — |
| `total_time_akhr` | `time` | YES | — |
| `id` | `int` | NO | — |

### `dbo.audit_logs` — 3,509 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_audit_logs_action` on `action,created_at`
* `IX_audit_logs_created` on `created_at`
* `IX_audit_logs_event_type` on `event_type,created_at`
* `IX_audit_logs_module` on `module,created_at`
* `IX_audit_logs_request` on `request_id`
* `IX_audit_logs_severity` on `severity,created_at`
* `IX_audit_logs_username` on `username,created_at`
* `PK_audit_logs` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `event_id` | `varchar(32)` | NO | — |
| `event_type` | `varchar(32)` | NO | — |
| `action` | `varchar(64)` | NO | — |
| `username` | `nvarchar(255)` | YES | — |
| `role` | `varchar(16)` | YES | — |
| `module` | `varchar(64)` | YES | — |
| `resource_type` | `varchar(64)` | YES | — |
| `resource_id` | `nvarchar(128)` | YES | — |
| `request_id` | `varchar(32)` | YES | — |
| `session_id` | `nvarchar(255)` | YES | — |
| `ip_address` | `varchar(45)` | YES | — |
| `user_agent` | `nvarchar(500)` | YES | — |
| `status` | `varchar(16)` | NO | `('success')` |
| `severity` | `varchar(16)` | NO | `('info')` |
| `before_data` | `nvarchar(max)` | YES | — |
| `after_data` | `nvarchar(max)` | YES | — |
| `metadata` | `nvarchar(max)` | YES | — |
| `error_id` | `varchar(32)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.automation_attachments` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK_automation_attachments` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `conversation_id` | `bigint` | NO | — |
| `message_id` | `bigint` | YES | — |
| `uploaded_by` | `nvarchar(255)` | NO | — |
| `original_name` | `nvarchar(255)` | NO | — |
| `storage_name` | `varchar(180)` | NO | — |
| `content_type` | `varchar(120)` | NO | — |
| `size_bytes` | `bigint` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.automation_conversations` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK_automation_conversations` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `subject` | `nvarchar(180)` | NO | — |
| `created_by` | `nvarchar(255)` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `status` | `varchar(20)` | NO | `('open')` |

### `dbo.automation_messages` — 6 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_automation_messages_conversation` on `conversation_id,created_at,id`
* `PK_automation_messages` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `conversation_id` | `bigint` | NO | — |
| `author_username` | `nvarchar(255)` | NO | — |
| `body` | `nvarchar(4000)` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.automation_participants` — 8 rows

**Primary key:** `conversation_id`, `username`
**Identity column:** none — inserts must supply the key value.

**Indexes:**
* `IX_automation_participants_user` on `username,conversation_id`
* `PK_automation_participants` (UNIQUE) on `conversation_id,username`

| Column | Type | Null | Default |
|---|---|---|---|
| `conversation_id` | `bigint` | NO | — |
| `username` | `nvarchar(255)` | NO | — |

### `dbo.automation_reopen_requests` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK_automation_reopen_requests` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `conversation_id` | `bigint` | NO | — |
| `requester` | `nvarchar(255)` | NO | — |
| `status` | `varchar(20)` | NO | `('pending')` |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `resolved_at` | `datetime2` | YES | — |

### `dbo.avalpss_table` — 5 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__avalpss___3213E83F33BA5D1B` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `officialtime` | `time` | YES | — |
| `entrytime` | `time` | YES | — |
| `date` | `date` | YES | — |
| `username` | `nchar(10)` | YES | — |
| `total_time_aval` | `time` | YES | — |
| `id` | `int` | NO | — |

### `dbo.beynpss_table` — 1 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__beynpss___3213E83F141816EE` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `exitTime` | `time` | YES | — |
| `entryTime` | `time` | YES | — |
| `date` | `date` | YES | — |
| `username` | `nchar(10)` | YES | — |
| `total_time_beyn` | `time` | YES | — |
| `id` | `int` | NO | — |

### `dbo.customer_subscriptions` — 1 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_customer_subscriptions_contact` on `contact_email,contact_phone`
* `IX_customer_subscriptions_customer` on `customer_code,customer_name`
* `IX_customer_subscriptions_dates` on `starts_at,expires_at`
* `IX_customer_subscriptions_status` on `subscription_status,expires_at`
* `PK_customer_subscriptions` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `customer_id` | `nvarchar(128)` | YES | — |
| `customer_code` | `nvarchar(128)` | YES | — |
| `customer_name` | `nvarchar(255)` | NO | — |
| `contact_name` | `nvarchar(255)` | YES | — |
| `contact_email` | `nvarchar(320)` | YES | — |
| `contact_phone` | `nvarchar(64)` | YES | — |
| `plan_name` | `nvarchar(128)` | NO | — |
| `subscription_status` | `nvarchar(32)` | YES | — |
| `purchased_at` | `datetime2` | YES | — |
| `starts_at` | `datetime2` | NO | — |
| `expires_at` | `datetime2` | NO | — |
| `max_users` | `int` | NO | `((0))` |
| `price` | `decimal` | YES | — |
| `currency` | `char(3)` | YES | — |
| `payment_method` | `nvarchar(64)` | YES | — |
| `payment_reference` | `nvarchar(255)` | YES | — |
| `invoice_number` | `nvarchar(128)` | YES | — |
| `notes` | `nvarchar(max)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.display_queue` — 1 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_display_queue_position` on `slot_position`
* `PK__display___3213E83F60C2A3F5` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `reception_number` | `nvarchar(50)` | NO | — |
| `department` | `nvarchar(100)` | NO | `(N'نمونه‌گیری')` |
| `called_by` | `nvarchar(255)` | YES | — |
| `slot_position` | `int` | NO | `((0))` |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.ezafe_table` — 2 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__ezafe_ta__3213E83FB2CD72CE` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `overtime_date` | `date` | YES | — |
| `from_time` | `time` | YES | — |
| `to_time` | `time` | YES | — |
| `description` | `varchar(max)` | YES | — |
| `status` | `nvarchar(50)` | YES | — |
| `username` | `nvarchar(100)` | YES | — |
| `daily_overtime` | `time` | YES | — |
| `id` | `int` | NO | — |

### `dbo.ezafe_total_table` — 1 rows

**Identity column:** none — inserts must supply the key value.

| Column | Type | Null | Default |
|---|---|---|---|
| `username` | `nchar(10)` | YES | — |
| `total_ezafe_time` | `time` | YES | — |

### `dbo.hozoor` — 59 rows

**Identity column:** `id`

**Indexes:**
* `IX_hozoor_username_date` on `username,date`
* `UX_hozoor_username_date` (UNIQUE) on `username,date`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `username` | `nvarchar(50)` | NO | — |
| `date` | `date` | NO | — |
| `vrood` | `time` | YES | — |
| `khoroj` | `time` | YES | — |

### `dbo.leave_report` — 0 rows

**Identity column:** none — inserts must supply the key value.

| Column | Type | Null | Default |
|---|---|---|---|
| `username` | `nchar(10)` | YES | — |
| `total_days` | `int` | YES | — |
| `remaining_days` | `int` | YES | — |

### `dbo.mrkhc_table` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__mrkhc_ta__3213E83F454C72C3` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `start_date` | `date` | YES | — |
| `end_date` | `date` | YES | — |
| `days` | `int` | YES | — |
| `substitute` | `varchar(50)` | YES | — |
| `username` | `nchar(10)` | YES | — |
| `status` | `nvarchar(max)` | YES | `(N'انتظار تایید')` |
| `id` | `int` | NO | — |

### `dbo.notification_targets` — 2 rows

**Primary key:** `notification_id`, `target_value`
**Identity column:** none — inserts must supply the key value.

**Indexes:**
* `PK_notification_targets` (UNIQUE) on `notification_id,target_value`

| Column | Type | Null | Default |
|---|---|---|---|
| `notification_id` | `bigint` | NO | — |
| `target_value` | `nvarchar(255)` | NO | — |

### `dbo.notifications` — 2 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_notifications_admin_list` on `status,type,priority,target_type,created_at`
* `IX_notifications_status_schedule` on `created_at,published_at,status,scheduled_at`
* `PK_notifications` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `title` | `nvarchar(180)` | NO | — |
| `content` | `nvarchar(4000)` | NO | — |
| `type` | `varchar(24)` | NO | `('general')` |
| `priority` | `varchar(16)` | NO | `('normal')` |
| `status` | `varchar(16)` | NO | `('draft')` |
| `target_type` | `varchar(16)` | NO | `('all')` |
| `action_label` | `nvarchar(80)` | YES | — |
| `action_url` | `nvarchar(500)` | YES | — |
| `created_by` | `nvarchar(255)` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `published_at` | `datetime2` | YES | — |
| `scheduled_at` | `datetime2` | YES | — |
| `archived_at` | `datetime2` | YES | — |
| `push_tag` | `nvarchar(160)` | YES | — |

### `dbo.password_reset_requests` — 7 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_prr_request` on `request_id`
* `IX_prr_status` on `status,created_at`
* `IX_prr_username` on `username,created_at`
* `PK_password_reset_requests` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `request_id` | `varchar(32)` | NO | — |
| `username` | `nvarchar(255)` | NO | — |
| `ip_address` | `varchar(45)` | YES | — |
| `user_agent` | `nvarchar(500)` | YES | — |
| `status` | `varchar(16)` | NO | `('pending')` |
| `recovery_code` | `varchar(128)` | YES | — |
| `code_expires_at` | `datetime2` | YES | — |
| `code_attempts` | `int` | NO | `((0))` |
| `max_attempts` | `int` | NO | `((5))` |
| `approved_by` | `nvarchar(255)` | YES | — |
| `approved_at` | `datetime2` | YES | — |
| `completed_at` | `datetime2` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.push_subscriptions` — 3 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_push_subscriptions_user` on `username,disabled_at,updated_at`
* `PK_push_subscriptions` (UNIQUE) on `id`
* `UQ_push_subscriptions_endpoint` (UNIQUE) on `endpoint`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `username` | `nvarchar(255)` | NO | — |
| `endpoint` | `nvarchar(2048)` | NO | — |
| `p256dh` | `nvarchar(512)` | NO | — |
| `auth` | `nvarchar(512)` | NO | — |
| `user_agent` | `nvarchar(500)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `last_used_at` | `datetime2` | YES | — |
| `disabled_at` | `datetime2` | YES | — |

### `dbo.queue_tickets` — 42 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_queue_tickets_date_status` on `ticket_date,status`
* `IX_queue_tickets_number` on `ticket_number`
* `PK_queue_tickets` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `ticket_number` | `int` | NO | — |
| `ticket_date` | `date` | NO | `(CONVERT([date],sysutcdatetime()))` |
| `status` | `nvarchar(20)` | NO | `(N'waiting')` |
| `service` | `nvarchar(50)` | YES | `(N'\u067E\u0686\u06CC\u0631\u0634')` |
| `called_for` | `nvarchar(50)` | YES | — |
| `called_at` | `datetime2` | YES | — |
| `completed_at` | `datetime2` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `patient_name` | `nvarchar(200)` | YES | — |
| `patient_age` | `nvarchar(3)` | YES | — |
| `patient_national_id` | `nvarchar(10)` | YES | — |
| `patient_phone` | `nvarchar(11)` | YES | — |
| `insurance_base` | `nvarchar(100)` | YES | — |
| `insurance_extra` | `nvarchar(100)` | YES | — |

### `dbo.reception_calls` — 3 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_reception_calls_called_at` on `called_at`
* `PK_reception_calls` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `reception_number` | `nvarchar(50)` | NO | — |
| `department` | `nvarchar(100)` | NO | `(N'نمونه‌گیری')` |
| `called_by` | `nvarchar(255)` | NO | — |
| `is_test` | `bit` | NO | `((0))` |
| `called_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.security_events` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_security_events_created` on `created_at`
* `IX_security_events_severity` on `severity,created_at`
* `IX_security_events_status` on `status,created_at`
* `IX_security_events_username` on `username,created_at`
* `PK_security_events` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `event_id` | `varchar(32)` | NO | — |
| `event_type` | `varchar(64)` | NO | — |
| `severity` | `varchar(16)` | NO | `('medium')` |
| `username` | `nvarchar(255)` | YES | — |
| `ip_address` | `varchar(45)` | YES | — |
| `description` | `nvarchar(1000)` | NO | — |
| `metadata` | `nvarchar(max)` | YES | — |
| `status` | `varchar(16)` | NO | `('open')` |
| `resolved_by` | `nvarchar(255)` | YES | — |
| `resolved_at` | `datetime2` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.shiftha` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_shiftha_username_year_month` on `username,jalali_year,jalali_month`
* `PK__shiftha__3213E83FA4541DFF` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `username` | `varchar(50)` | NO | — |
| `jalali_year` | `int` | NO | — |
| `jalali_month` | `int` | NO | — |
| `start_day` | `int` | NO | — |
| `end_day` | `int` | NO | — |
| `shanbeh` | `varchar(20)` | YES | — |
| `yekshanbeh` | `varchar(20)` | YES | — |
| `doshanbeh` | `varchar(20)` | YES | — |
| `seshanbeh` | `varchar(20)` | YES | — |
| `chaharshanbeh` | `varchar(20)` | YES | — |
| `panjshanbeh` | `varchar(20)` | YES | — |
| `jomeh` | `varchar(20)` | YES | — |
| `title` | `varchar(100)` | YES | — |
| `created_at` | `datetime` | YES | `(getdate())` |

### `dbo.slides` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__slides__3213E83F69C2998B` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `filename` | `nvarchar(255)` | NO | — |
| `original_name` | `nvarchar(255)` | NO | — |
| `is_active` | `bit` | NO | `((1))` |
| `sort_order` | `int` | NO | `((0))` |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.sysdiagrams` — 0 rows

**Primary key:** `diagram_id`
**Identity column:** `diagram_id`

**Indexes:**
* `PK__sysdiagr__C2B05B6134BD358B` (UNIQUE) on `diagram_id`
* `UK_principal_name` (UNIQUE) on `principal_id,name`

| Column | Type | Null | Default |
|---|---|---|---|
| `name` | `nvarchar(128)` | NO | — |
| `principal_id` | `int` | NO | — |
| `diagram_id` | `int` | NO | — |
| `version` | `int` | YES | — |
| `definition` | `varbinary(max)` | YES | — |

### `dbo.system_config` — 12 rows

**Primary key:** `config_key`
**Identity column:** none — inserts must supply the key value.

**Indexes:**
* `PK_system_config` (UNIQUE) on `config_key`

| Column | Type | Null | Default |
|---|---|---|---|
| `config_key` | `varchar(128)` | NO | — |
| `config_value` | `nvarchar(2000)` | YES | — |
| `description` | `nvarchar(500)` | YES | — |
| `updated_by` | `nvarchar(255)` | YES | — |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.system_errors` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_syserr_created` on `first_seen`
* `IX_syserr_severity` on `severity,first_seen`
* `IX_syserr_status` on `status,first_seen`
* `IX_syserr_type` on `error_type,first_seen`
* `PK_system_errors` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `error_id` | `varchar(32)` | NO | — |
| `error_type` | `varchar(64)` | NO | — |
| `severity` | `varchar(16)` | NO | `('medium')` |
| `message` | `nvarchar(2000)` | NO | — |
| `detail` | `nvarchar(max)` | YES | — |
| `endpoint` | `varchar(255)` | YES | — |
| `method` | `varchar(10)` | YES | — |
| `username` | `nvarchar(255)` | YES | — |
| `ip_address` | `varchar(45)` | YES | — |
| `request_id` | `varchar(32)` | YES | — |
| `session_id` | `nvarchar(255)` | YES | — |
| `status` | `varchar(16)` | NO | `('open')` |
| `occurrences` | `int` | NO | `((1))` |
| `first_seen` | `datetime2` | NO | `(sysutcdatetime())` |
| `last_seen` | `datetime2` | NO | `(sysutcdatetime())` |
| `resolved_by` | `nvarchar(255)` | YES | — |
| `resolved_at` | `datetime2` | YES | — |

### `dbo.ticket_attachments` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_ticket_attachments_ticket` on `ticket_id,created_at`
* `PK_ticket_attachments` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `ticket_id` | `bigint` | NO | — |
| `message_id` | `bigint` | YES | — |
| `uploaded_by` | `nvarchar(255)` | NO | — |
| `original_name` | `nvarchar(255)` | NO | — |
| `storage_name` | `varchar(180)` | NO | — |
| `content_type` | `varchar(120)` | NO | — |
| `size_bytes` | `bigint` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.ticket_categories` — 4 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK_ticket_categories` (UNIQUE) on `id`
* `UQ_ticket_categories_slug` (UNIQUE) on `slug`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `name` | `nvarchar(120)` | NO | — |
| `slug` | `varchar(120)` | NO | — |
| `parent_id` | `int` | YES | — |
| `is_active` | `bit` | NO | `((1))` |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.ticket_events` — 19 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_ticket_events_ticket` on `ticket_id,created_at`
* `PK_ticket_events` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `ticket_id` | `bigint` | NO | — |
| `actor_username` | `nvarchar(255)` | NO | — |
| `event_type` | `varchar(40)` | NO | — |
| `metadata` | `nvarchar(2000)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.ticket_messages` — 19 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_ticket_messages_ticket` on `ticket_id,created_at,id`
* `PK_ticket_messages` (UNIQUE) on `id`
* `UX_ticket_messages_legacy` (UNIQUE) on `legacy_message_id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `ticket_id` | `bigint` | NO | — |
| `author_username` | `nvarchar(255)` | NO | — |
| `body` | `nvarchar(4000)` | NO | — |
| `visibility` | `varchar(16)` | NO | `('public')` |
| `legacy_message_id` | `int` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `edited_at` | `datetime2` | YES | — |

### `dbo.ticket_table` — 1 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__ticket_t__3213E83FDD47F5D2` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `ticketTitle` | `varchar(50)` | YES | — |
| `ticketDescription` | `varchar(max)` | YES | — |
| `username` | `nchar(10)` | YES | — |
| `ticket_date` | `datetime` | YES | — |
| `ticket_status` | `varchar(max)` | YES | — |
| `target_username` | `nchar(10)` | YES | — |
| `Parent_id` | `int` | YES | — |
| `id` | `int` | NO | — |
| `is_read` | `varchar(max)` | YES | `((0))` |

### `dbo.ticket_tag_relations` — 0 rows

**Primary key:** `tag_id`, `ticket_id`
**Identity column:** none — inserts must supply the key value.

**Indexes:**
* `PK_ticket_tag_relations` (UNIQUE) on `ticket_id,tag_id`

| Column | Type | Null | Default |
|---|---|---|---|
| `ticket_id` | `bigint` | NO | — |
| `tag_id` | `int` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.ticket_tags` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK_ticket_tags` (UNIQUE) on `id`
* `UQ_ticket_tags_name` (UNIQUE) on `name`
* `UQ_ticket_tags_slug` (UNIQUE) on `slug`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `name` | `nvarchar(60)` | NO | — |
| `slug` | `varchar(60)` | NO | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.tickets` — 3 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_tickets_assignment` on `assigned_to,status,updated_at`
* `IX_tickets_category` on `category_id,status,updated_at`
* `IX_tickets_inbox` on `requester_username,recipient_username,assigned_to,subject,status,priority,updated_at`
* `IX_tickets_participants` on `requester_username,recipient_username,updated_at`
* `PK_tickets` (UNIQUE) on `id`
* `UX_tickets_legacy_parent` (UNIQUE) on `legacy_parent_id`
* `UX_tickets_number` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `legacy_parent_id` | `int` | YES | — |
| `requester_username` | `nvarchar(255)` | NO | — |
| `recipient_username` | `nvarchar(255)` | NO | — |
| `subject` | `nvarchar(180)` | NO | — |
| `status` | `varchar(32)` | NO | `('new')` |
| `priority` | `varchar(16)` | NO | `('normal')` |
| `category_id` | `int` | YES | — |
| `assigned_to` | `nvarchar(255)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `last_message_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `first_response_at` | `datetime2` | YES | — |
| `resolved_at` | `datetime2` | YES | — |
| `closed_at` | `datetime2` | YES | — |
| `sla_due_at` | `datetime2` | YES | — |

### `dbo.totalpass_table` — 6 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `PK__totalpas__3213E83F9D5B4BBF` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `username` | `nchar(10)` | YES | — |
| `request_date` | `date` | YES | — |
| `pass_title` | `nvarchar(max)` | YES | — |
| `pass_duration` | `time` | YES | — |
| `status` | `varchar(50)` | YES | — |
| `id` | `int` | NO | — |

### `dbo.user_notifications` — 2 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_user_notifications_inbox` on `notification_id,read_at,username,dismissed_at,delivered_at`
* `IX_user_notifications_unread` on `dismissed_at,notification_id,username,read_at`
* `PK_user_notifications` (UNIQUE) on `id`
* `UQ_user_notifications` (UNIQUE) on `notification_id,username`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `notification_id` | `bigint` | NO | — |
| `username` | `nvarchar(255)` | NO | — |
| `delivered_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `read_at` | `datetime2` | YES | — |
| `dismissed_at` | `datetime2` | YES | — |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.user_registration_requests` — 0 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_regreq_created` on `created_at`
* `IX_regreq_mobile` on `mobile`
* `IX_regreq_national` on `national_id`
* `IX_regreq_status` on `status,created_at`
* `IX_regreq_username` on `username`
* `PK_reg_requests` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `request_id` | `varchar(32)` | NO | — |
| `first_name` | `nvarchar(100)` | NO | — |
| `last_name` | `nvarchar(100)` | NO | — |
| `father_name` | `nvarchar(100)` | YES | — |
| `national_id` | `varchar(10)` | YES | — |
| `mobile` | `varchar(15)` | YES | — |
| `username` | `nvarchar(255)` | NO | — |
| `password_hash` | `varbinary(64)` | NO | — |
| `department` | `nvarchar(100)` | YES | — |
| `work_hours` | `nvarchar(50)` | YES | — |
| `substitute` | `nvarchar(255)` | YES | — |
| `status` | `varchar(16)` | NO | `('pending')` |
| `rejection_reason` | `nvarchar(500)` | YES | — |
| `reviewed_by` | `nvarchar(255)` | YES | — |
| `reviewed_at` | `datetime2` | YES | — |
| `created_ip` | `varchar(45)` | YES | — |
| `created_user_agent` | `nvarchar(500)` | YES | — |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `updated_at` | `datetime2` | NO | `(sysutcdatetime())` |

### `dbo.user_sessions` — 303 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_user_sessions_active` on `is_active,last_activity`
* `IX_user_sessions_key` on `session_key`
* `IX_user_sessions_username` on `username,login_at`
* `PK_user_sessions` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `bigint` | NO | — |
| `session_key` | `nvarchar(255)` | NO | — |
| `username` | `nvarchar(255)` | NO | — |
| `ip_address` | `varchar(45)` | YES | — |
| `user_agent` | `nvarchar(500)` | YES | — |
| `login_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `last_activity` | `datetime2` | NO | `(sysutcdatetime())` |
| `logout_at` | `datetime2` | YES | — |
| `is_active` | `bit` | NO | `((1))` |
| `terminated_by` | `nvarchar(255)` | YES | — |

### `dbo.user_table` — 16 rows

**Primary key:** `id`
**Identity column:** none — inserts must supply the key value.

**Indexes:**
* `IX_user_table_username` on `username`
* `PK_user_table` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `username` | `nvarchar(50)` | YES | — |
| `password` | `nchar(10)` | YES | — |
| `name` | `nvarchar(50)` | YES | — |
| `last_name` | `nvarchar(50)` | YES | — |
| `department` | `nvarchar(50)` | YES | — |
| `work_hours` | `nvarchar(max)` | YES | — |
| `substitute` | `nvarchar(50)` | YES | — |
| `role` | `nchar(10)` | YES | — |
| `hozoor_num` | `nchar(10)` | YES | — |
| `shanbeh` | `nvarchar(max)` | YES | — |
| `yekshanbeh` | `nvarchar(max)` | YES | — |
| `doshanbeh` | `nvarchar(max)` | YES | — |
| `seshanbeh` | `nvarchar(max)` | YES | — |
| `chrshanbeh` | `nvarchar(max)` | YES | — |
| `panjshanbeh` | `nvarchar(max)` | YES | — |
| `id` | `int` | NO | — |
| `profile_image` | `nvarchar(255)` | YES | — |
| `employment_status` | `nvarchar(20)` | YES | — |
| `is_active` | `nvarchar(10)` | YES | — |
| `password_hash` | `varbinary(64)` | YES | — |
| `last_login` | `datetime2` | YES | — |
| `failed_login_count` | `int` | NO | `((0))` |
| `password_changed_at` | `datetime2` | YES | — |
| `customer_id` | `nvarchar(128)` | YES | — |
| `customer_name` | `nvarchar(255)` | YES | — |

### `dbo.waiting_queue` — 1 rows

**Primary key:** `id`
**Identity column:** `id`

**Indexes:**
* `IX_waiting_queue_status` on `status`
* `PK__waiting___3213E83F3C0D3667` (UNIQUE) on `id`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `int` | NO | — |
| `reception_number` | `nvarchar(50)` | NO | — |
| `department` | `nvarchar(100)` | NO | `(N'نمونه\u065cگیری')` |
| `added_by` | `nvarchar(255)` | YES | — |
| `status` | `nvarchar(20)` | NO | `(N'waiting')` |
| `created_at` | `datetime2` | NO | `(sysutcdatetime())` |
| `called_at` | `datetime2` | YES | — |

## Foreign keys (authoritative)

Read from `sys.foreign_keys` + `sys.foreign_key_columns` on the live server, not from the
schema generator: the tables above each carried the same global list, which was both mislabelled
("Incoming" for every table) and incomplete. **15 constraints exist** — the four that were missing
from the generated list are marked below.

| # | Child column | References | Constraint |
|---|---|---|---|
| 1 | `automation_attachments.conversation_id` | `automation_conversations.id` | `FK_automation_attachments_conversation` |
| 2 | `automation_attachments.message_id` | `automation_messages.id` | `FK_automation_attachments_message` |
| 3 | `automation_messages.conversation_id` | `automation_conversations.id` | `FK_automation_messages_conversation` |
| 4 | `automation_participants.conversation_id` | `automation_conversations.id` | `FK_automation_participants_conversation` |
| 5 | `automation_reopen_requests.conversation_id` | `automation_conversations.id` | `FK_automation_reopen_requests_conversation` |
| 6 | `notification_targets.notification_id` | `notifications.id` | `FK_notification_targets_notification` |
| 7 | `ticket_attachments.message_id` | `ticket_messages.id` | `FK_ticket_attachments_message` |
| 8 | `ticket_attachments.ticket_id` | `tickets.id` | `FK_ticket_attachments_ticket` *(missing from the list)* |
| 9 | `ticket_categories.parent_id` | `ticket_categories.id` | `FK_ticket_categories_parent` |
| 10 | `ticket_events.ticket_id` | `tickets.id` | `FK_ticket_events_ticket` *(missing from the list)* |
| 11 | `ticket_messages.ticket_id` | `tickets.id` | `FK_ticket_messages_ticket` *(missing from the list)* |
| 12 | `ticket_tag_relations.tag_id` | `ticket_tags.id` | `FK_ticket_tag_relations_tag` |
| 13 | `ticket_tag_relations.ticket_id` | `tickets.id` | `FK_ticket_tag_relations_ticket` *(missing from the list)* |
| 14 | `tickets.category_id` | `ticket_categories.id` | `FK_tickets_category` |
| 15 | `user_notifications.notification_id` | `notifications.id` | `FK_user_notifications_notification` |

Every other relationship in this database is by convention only — for example `user_table.hozoor_num`
to an Araz card number, `user_table.username` to `user_sessions.username`, and
`admin_payroll_calculations.username` to `user_table.username`. None of them is enforced, and all of
them compare padded strings, which is why the models use `LTRIM(RTRIM(...))` / `RTRIM(...)`
predicates rather than `JOIN`s on a foreign key.

Reproduce with `php artisan hastama:schema-check` (`--json`) or by querying `sys.foreign_keys`
directly.
