/* ═══════════════════════════════════════════════════════════════
   HASTAMA SECURITY HARDENING — Database Migration
   ═══════════════════════════════════════════════════════════════
   Run once. Safe migration — preserves existing data.
   ═══════════════════════════════════════════════════════════════ */
SET XACT_ABORT ON;

-- ─────────────────────────────────────────────────────────────
-- 1. Fix username column: ntext → nvarchar(100)
-- ─────────────────────────────────────────────────────────────
DECLARE @col_type NVARCHAR(50);
SELECT @col_type = DATA_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'username';

IF @col_type = 'ntext'
BEGIN
    ALTER TABLE dbo.user_table ADD username_new NVARCHAR(100) NULL;
    EXEC('UPDATE dbo.user_table SET username_new = CAST(username AS NVARCHAR(100))');
    ALTER TABLE dbo.user_table DROP COLUMN username;
    EXEC sp_rename 'dbo.user_table.username_new', 'username', 'COLUMN';
    PRINT 'username column migrated from ntext to nvarchar(100).';
END
ELSE
BEGIN
    PRINT 'username column is already ' + ISNULL(@col_type, 'unknown') + ' — skipping.';
END;

-- ─────────────────────────────────────────────────────────────
-- 2. Add index on username for faster lookups
-- ─────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_user_table_username' AND object_id = OBJECT_ID('user_table'))
BEGIN
    CREATE INDEX IX_user_table_username ON dbo.user_table(username);
    PRINT 'Index IX_user_table_username created.';
END;

-- ─────────────────────────────────────────────────────────────
-- 3. Ensure password_hash column exists (bcrypt storage)
-- ─────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'password_hash')
BEGIN
    ALTER TABLE dbo.user_table ADD password_hash VARBINARY(64) NULL;
    PRINT 'password_hash column added.';
END;

-- ─────────────────────────────────────────────────────────────
-- 4. Ensure is_active column exists
-- ─────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'user_table' AND COLUMN_NAME = 'is_active')
BEGIN
    ALTER TABLE dbo.user_table ADD is_active VARCHAR(16) NULL DEFAULT 'active';
    PRINT 'is_active column added.';
END;

-- ─────────────────────────────────────────────────────────────
-- 5. Ensure password_reset_requests table exists
-- ─────────────────────────────────────────────────────────────
IF OBJECT_ID('dbo.password_reset_requests', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.password_reset_requests (
        id              BIGINT IDENTITY(1,1) NOT NULL,
        request_id      VARCHAR(32) NOT NULL,
        username        NVARCHAR(255) NOT NULL,
        ip_address      VARCHAR(45) NULL,
        user_agent      NVARCHAR(500) NULL,
        status          VARCHAR(16) NOT NULL DEFAULT 'pending',
        recovery_code   VARCHAR(128) NULL,
        code_expires_at DATETIME2(3) NULL,
        code_attempts   INT NOT NULL DEFAULT 0,
        max_attempts    INT NOT NULL DEFAULT 5,
        approved_by     NVARCHAR(255) NULL,
        approved_at     DATETIME2(3) NULL,
        completed_at    DATETIME2(3) NULL,
        created_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_password_reset_requests PRIMARY KEY (id)
    );
    CREATE INDEX IX_prr_username ON dbo.password_reset_requests(username, created_at DESC);
    CREATE INDEX IX_prr_status ON dbo.password_reset_requests(status, created_at DESC);
    CREATE INDEX IX_prr_request ON dbo.password_reset_requests(request_id);
    PRINT 'password_reset_requests table created.';
END;

PRINT 'Security hardening migration completed successfully.';
