/* Assign the existing installation users to Dr. Amini's laboratory.
   Additive SQL Server migration; safe to run more than once. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'user_table' AND COLUMN_NAME = 'customer_id'
)
    EXEC sys.sp_executesql N'ALTER TABLE dbo.user_table ADD customer_id NVARCHAR(128) NULL';

IF NOT EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'user_table' AND COLUMN_NAME = 'customer_name'
)
    EXEC sys.sp_executesql N'ALTER TABLE dbo.user_table ADD customer_name NVARCHAR(255) NULL';

EXEC sys.sp_executesql N'
    UPDATE dbo.user_table
    SET customer_id = N''DR-AMINI-LAB'',
        customer_name = N''آزمایشگاه تشخیص طبی دکتر امینی'';';

IF OBJECT_ID(N'dbo.customer_subscriptions', N'U') IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM dbo.customer_subscriptions WHERE customer_id = N'DR-AMINI-LAB'
)
BEGIN
    INSERT INTO dbo.customer_subscriptions (
        customer_id, customer_code, customer_name, plan_name,
        subscription_status, starts_at, expires_at, max_users, notes
    )
    VALUES (
        N'DR-AMINI-LAB', N'DR-AMINI-LAB', N'آزمایشگاه تشخیص طبی دکتر امینی',
        N'اشتراک سازمانی', N'active', SYSUTCDATETIME(),
        DATEADD(YEAR, 10, SYSUTCDATETIME()), 0,
        N'مشتری پیش‌فرض کاربران موجود سامانه'
    );
END;

COMMIT TRANSACTION;