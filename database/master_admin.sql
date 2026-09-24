/* ═══════════════════════════════════════════════════════════════
   HASTAMA MASTER ADMINISTRATION — Database Migration
   ═══════════════════════════════════════════════════════════════
   Run once. Additive only — never drops or renames existing tables.
   ═══════════════════════════════════════════════════════════════ */
SET XACT_ABORT ON;

-- ─────────────────────────────────────────────────────────────
-- 1. AUDIT LOGS — append-only event store
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.audit_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.audit_logs (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_audit_logs PRIMARY KEY,
        event_id        VARCHAR(32) NOT NULL,           -- HST-YYYYMMDD-HEX8
        event_type      VARCHAR(32) NOT NULL,           -- AUTHENTICATION, USER, SECURITY, …
        action          VARCHAR(64) NOT NULL,           -- login, logout, create, update, delete, …
        username        NVARCHAR(255) NULL,
        role            VARCHAR(16) NULL,
        module          VARCHAR(64) NULL,               -- attendance, vacation, shift, ticket, …
        resource_type   VARCHAR(64) NULL,               -- user, ticket, shift, …
        resource_id     NVARCHAR(128) NULL,
        request_id      VARCHAR(32) NULL,               -- correlation id
        session_id      NVARCHAR(255) NULL,
        ip_address      VARCHAR(45) NULL,
        user_agent      NVARCHAR(500) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'success',  -- success, failure, error
        severity        VARCHAR(16) NOT NULL DEFAULT 'info',     -- info, low, medium, high, critical
        before_data     NVARCHAR(MAX) NULL,
        after_data      NVARCHAR(MAX) NULL,
        metadata        NVARCHAR(MAX) NULL,             -- JSON extra fields
        error_id        VARCHAR(32) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_audit_logs_created    ON dbo.audit_logs(created_at DESC);
    CREATE INDEX IX_audit_logs_username   ON dbo.audit_logs(username, created_at DESC);
    CREATE INDEX IX_audit_logs_event_type ON dbo.audit_logs(event_type, created_at DESC);
    CREATE INDEX IX_audit_logs_severity   ON dbo.audit_logs(severity, created_at DESC) WHERE severity IN ('high','critical');
    CREATE INDEX IX_audit_logs_request    ON dbo.audit_logs(request_id) WHERE request_id IS NOT NULL;
    CREATE INDEX IX_audit_logs_module     ON dbo.audit_logs(module, created_at DESC);
    CREATE INDEX IX_audit_logs_action     ON dbo.audit_logs(action, created_at DESC);
END;

-- ─────────────────────────────────────────────────────────────
-- 2. SECURITY EVENTS — suspicious / high-risk detections
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.security_events', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.security_events (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_security_events PRIMARY KEY,
        event_id        VARCHAR(32) NOT NULL,
        event_type      VARCHAR(64) NOT NULL,           -- repeated_failed_login, unusual_ip, …
        severity        VARCHAR(16) NOT NULL DEFAULT 'medium',
        username        NVARCHAR(255) NULL,
        ip_address      VARCHAR(45) NULL,
        description     NVARCHAR(1000) NOT NULL,
        metadata        NVARCHAR(MAX) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'open',  -- open, investigated, resolved, false_positive
        resolved_by     NVARCHAR(255) NULL,
        resolved_at     DATETIME2(3) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_security_events_created  ON dbo.security_events(created_at DESC);
    CREATE INDEX IX_security_events_severity ON dbo.security_events(severity, created_at DESC);
    CREATE INDEX IX_security_events_status   ON dbo.security_events(status, created_at DESC) WHERE status = 'open';
    CREATE INDEX IX_security_events_username ON dbo.security_events(username, created_at DESC);
END;

-- ─────────────────────────────────────────────────────────────
-- 3. USER SESSIONS — track active / historical sessions
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.user_sessions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.user_sessions (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_user_sessions PRIMARY KEY,
        session_key     NVARCHAR(255) NOT NULL,          -- opaque session id
        username        NVARCHAR(255) NOT NULL,
        ip_address      VARCHAR(45) NULL,
        user_agent      NVARCHAR(500) NULL,
        login_at        DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_activity   DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        logout_at       DATETIME2(3) NULL,
        is_active       BIT NOT NULL DEFAULT 1,
        terminated_by   NVARCHAR(255) NULL               -- admin who force-logged-out
    );
    CREATE INDEX IX_user_sessions_active    ON dbo.user_sessions(is_active, last_activity DESC) WHERE is_active = 1;
    CREATE INDEX IX_user_sessions_username  ON dbo.user_sessions(username, login_at DESC);
    CREATE INDEX IX_user_sessions_key       ON dbo.user_sessions(session_key);
END;

-- ─────────────────────────────────────────────────────────────
-- 4. PASSWORD RESET REQUESTS
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.password_reset_requests', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.password_reset_requests (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_password_reset_requests PRIMARY KEY,
        request_id      VARCHAR(32) NOT NULL,
        username        NVARCHAR(255) NOT NULL,
        ip_address      VARCHAR(45) NULL,
        user_agent      NVARCHAR(500) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending, approved, rejected, completed, expired, cancelled
        recovery_code   VARCHAR(128) NULL,               -- hashed one-time code
        code_expires_at DATETIME2(3) NULL,
        code_attempts   INT NOT NULL DEFAULT 0,
        max_attempts    INT NOT NULL DEFAULT 5,
        approved_by     NVARCHAR(255) NULL,
        approved_at     DATETIME2(3) NULL,
        completed_at    DATETIME2(3) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_prr_username ON dbo.password_reset_requests(username, created_at DESC);
    CREATE INDEX IX_prr_status   ON dbo.password_reset_requests(status, created_at DESC) WHERE status = 'pending';
    CREATE INDEX IX_prr_request  ON dbo.password_reset_requests(request_id);
END;

-- ─────────────────────────────────────────────────────────────
-- 5. SYSTEM ERRORS — centralized error store
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.system_errors', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.system_errors (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_system_errors PRIMARY KEY,
        error_id        VARCHAR(32) NOT NULL,
        error_type      VARCHAR(64) NOT NULL,            -- application, database, api, auth, …
        severity        VARCHAR(16) NOT NULL DEFAULT 'medium',
        message         NVARCHAR(2000) NOT NULL,
        detail          NVARCHAR(MAX) NULL,
        endpoint        VARCHAR(255) NULL,
        method          VARCHAR(10) NULL,
        username        NVARCHAR(255) NULL,
        ip_address      VARCHAR(45) NULL,
        request_id      VARCHAR(32) NULL,
        session_id      NVARCHAR(255) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'open',  -- open, investigating, resolved, ignored
        occurrences     INT NOT NULL DEFAULT 1,
        first_seen      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_seen       DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        resolved_by     NVARCHAR(255) NULL,
        resolved_at     DATETIME2(3) NULL
    );
    CREATE INDEX IX_syserr_created  ON dbo.system_errors(first_seen DESC);
    CREATE INDEX IX_syserr_severity ON dbo.system_errors(severity, first_seen DESC);
    CREATE INDEX IX_syserr_status   ON dbo.system_errors(status, first_seen DESC) WHERE status = 'open';
    CREATE INDEX IX_syserr_type     ON dbo.system_errors(error_type, first_seen DESC);
END;

-- ─────────────────────────────────────────────────────────────
-- 6. ADMIN ACTIONS — log privileged admin operations
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.admin_actions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.admin_actions (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_admin_actions PRIMARY KEY,
        action_id       VARCHAR(32) NOT NULL,
        admin_username  NVARCHAR(255) NOT NULL,
        action          VARCHAR(64) NOT NULL,            -- approve_reset, terminate_session, change_role, …
        target_username NVARCHAR(255) NULL,
        target_type     VARCHAR(64) NULL,                -- password_reset, session, user, permission, …
        target_id       NVARCHAR(128) NULL,
        description     NVARCHAR(1000) NULL,
        before_data     NVARCHAR(MAX) NULL,
        after_data      NVARCHAR(MAX) NULL,
        ip_address      VARCHAR(45) NULL,
        request_id      VARCHAR(32) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_admin_actions_admin  ON dbo.admin_actions(admin_username, created_at DESC);
    CREATE INDEX IX_admin_actions_target ON dbo.admin_actions(target_username, created_at DESC);
    CREATE INDEX IX_admin_actions_action ON dbo.admin_actions(action, created_at DESC);
    CREATE INDEX IX_admin_actions_created ON dbo.admin_actions(created_at DESC);
END;

-- ─────────────────────────────────────────────────────────────
-- 7. SYSTEM CONFIGURATION — key-value store for admin settings
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.system_config', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.system_config (
        config_key      VARCHAR(128) NOT NULL CONSTRAINT PK_system_config PRIMARY KEY,
        config_value    NVARCHAR(2000) NULL,
        description     NVARCHAR(500) NULL,
        updated_by      NVARCHAR(255) NULL,
        updated_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    INSERT INTO dbo.system_config (config_key, config_value, description) VALUES
        ('audit_retention_days', '365', 'تعداد روز نگهداری لاگ‌های حسابرسی'),
        ('security_retention_days', '730', 'تعداد روز نگهداری رویدادهای امنیتی'),
        ('session_timeout_minutes', '480', 'مدت زمان نشست به دقیقه'),
        ('max_login_attempts', '5', 'حداکثر تلاش ناموفق ورود'),
        ('lockout_duration_minutes', '30', 'مدت قفل حساب به دقیقه'),
        ('password_reset_code_ttl_minutes', '60', 'مدت اعتبار کد بازیابی رمز عبور');
END;

-- ─────────────────────────────────────────────────────────────
-- 8. Ensure password_hash column exists on user_table
-- ─────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'password_hash'
)
BEGIN
    ALTER TABLE dbo.user_table ADD password_hash VARBINARY(64) NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'is_active'
)
BEGIN
    ALTER TABLE dbo.user_table ADD is_active VARCHAR(16) NULL DEFAULT 'active';
END;

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'last_login'
)
BEGIN
    ALTER TABLE dbo.user_table ADD last_login DATETIME2(3) NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'failed_login_count'
)
BEGIN
    ALTER TABLE dbo.user_table ADD failed_login_count INT NOT NULL DEFAULT 0;
END;

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'password_changed_at'
)
BEGIN
    ALTER TABLE dbo.user_table ADD password_changed_at DATETIME2(3) NULL;
END;

-- ─────────────────────────────────────────────────────────────
-- 9. CAPTCHA & IDLE TIMEOUT config keys
-- ─────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM dbo.system_config WHERE config_key = 'captcha_enabled')
    INSERT INTO dbo.system_config (config_key, config_value, description)
    VALUES ('captcha_enabled', '1', 'فعال‌سازی کپچا در صفحه ورود');

IF NOT EXISTS (SELECT 1 FROM dbo.system_config WHERE config_key = 'idle_timeout_enabled')
    INSERT INTO dbo.system_config (config_key, config_value, description)
    VALUES ('idle_timeout_enabled', '1', 'فعال‌سازی خروج خودکار پس از بیکاری');

IF NOT EXISTS (SELECT 1 FROM dbo.system_config WHERE config_key = 'idle_timeout_seconds')
    INSERT INTO dbo.system_config (config_key, config_value, description)
    VALUES ('idle_timeout_seconds', '300', 'زمان بیکاری برای خروج خودکار (ثانیه)');

-- 10. Label / ticket printer selected in master-admin label studio
IF NOT EXISTS (SELECT 1 FROM dbo.system_config WHERE config_key = 'label_target_printer')
    INSERT INTO dbo.system_config (config_key, config_value, description)
    VALUES ('label_target_printer', '', 'نام چاپگر انتخابی برای چاپ لیبل و بلیت نوبت');

PRINT 'Master Admin migration completed successfully.';
