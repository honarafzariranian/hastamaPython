/* Customer subscriptions for the master-admin control plane.
   Additive SQL Server migration; safe to run more than once. */
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.customer_subscriptions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.customer_subscriptions (
        id                  BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_customer_subscriptions PRIMARY KEY,
        customer_id         NVARCHAR(128) NULL,
        customer_code       NVARCHAR(128) NULL,
        customer_name       NVARCHAR(255) NOT NULL,
        contact_name        NVARCHAR(255) NULL,
        contact_email       NVARCHAR(320) NULL,
        contact_phone       NVARCHAR(64) NULL,
        plan_name           NVARCHAR(128) NOT NULL,
        subscription_status NVARCHAR(32) NULL,
        purchased_at        DATETIME2(3) NULL,
        starts_at           DATETIME2(3) NOT NULL,
        expires_at          DATETIME2(3) NOT NULL,
        max_users           INT NOT NULL CONSTRAINT DF_customer_subscriptions_max_users DEFAULT 0,
        price               DECIMAL(19,4) NULL,
        currency            CHAR(3) NULL,
        payment_method      NVARCHAR(64) NULL,
        payment_reference   NVARCHAR(255) NULL,
        invoice_number      NVARCHAR(128) NULL,
        notes               NVARCHAR(MAX) NULL,
        created_at          DATETIME2(3) NOT NULL CONSTRAINT DF_customer_subscriptions_created_at DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2(3) NOT NULL CONSTRAINT DF_customer_subscriptions_updated_at DEFAULT SYSUTCDATETIME()
    );

    CREATE INDEX IX_customer_subscriptions_customer
        ON dbo.customer_subscriptions(customer_code, customer_name);
    CREATE INDEX IX_customer_subscriptions_dates
        ON dbo.customer_subscriptions(starts_at, expires_at);
    CREATE INDEX IX_customer_subscriptions_status
        ON dbo.customer_subscriptions(subscription_status, expires_at);
    CREATE INDEX IX_customer_subscriptions_contact
        ON dbo.customer_subscriptions(contact_email, contact_phone);
END;
