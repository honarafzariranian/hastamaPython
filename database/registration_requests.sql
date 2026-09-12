/* ═══════════════════════════════════════════════════════════════
   HASTAMA USER SELF-REGISTRATION — Database Migration
   ═══════════════════════════════════════════════════════════════ */
SET XACT_ABORT ON;

-- ─────────────────────────────────────────────────────────────
-- 1. USER REGISTRATION REQUESTS
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID(N'dbo.user_registration_requests', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.user_registration_requests (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_reg_requests PRIMARY KEY,
        request_id      VARCHAR(32) NOT NULL,           -- HST-YYYYMMDD-HEX8
        first_name      NVARCHAR(100) NOT NULL,
        last_name       NVARCHAR(100) NOT NULL,
        father_name     NVARCHAR(100) NULL,
        national_id     VARCHAR(10) NULL,
        mobile          VARCHAR(15) NULL,
        username        NVARCHAR(255) NOT NULL,
        password_hash   VARBINARY(64) NOT NULL,
        department      NVARCHAR(100) NULL,
        work_hours      NVARCHAR(50) NULL,
        substitute      NVARCHAR(255) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending, approved, rejected, cancelled, expired
        rejection_reason NVARCHAR(500) NULL,
        reviewed_by     NVARCHAR(255) NULL,
        reviewed_at     DATETIME2(3) NULL,
        created_ip      VARCHAR(45) NULL,
        created_user_agent NVARCHAR(500) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_regreq_status   ON dbo.user_registration_requests(status, created_at DESC) WHERE status = 'pending';
    CREATE INDEX IX_regreq_username ON dbo.user_registration_requests(username);
    CREATE INDEX IX_regreq_national ON dbo.user_registration_requests(national_id) WHERE national_id IS NOT NULL;
    CREATE INDEX IX_regreq_mobile   ON dbo.user_registration_requests(mobile) WHERE mobile IS NOT NULL;
    CREATE INDEX IX_regreq_created  ON dbo.user_registration_requests(created_at DESC);
END;

PRINT 'Registration requests migration completed successfully.';
