/* Production notification schema for Microsoft SQL Server.
   Run once with a principal allowed to create tables/indexes. The API also
   executes this migration lazily, making existing installations upgrade safely. */
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.notifications', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.notifications (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_notifications PRIMARY KEY,
        title NVARCHAR(180) NOT NULL,
        content NVARCHAR(4000) NOT NULL,
        type VARCHAR(24) NOT NULL CONSTRAINT DF_notifications_type DEFAULT 'general',
        priority VARCHAR(16) NOT NULL CONSTRAINT DF_notifications_priority DEFAULT 'normal',
        status VARCHAR(16) NOT NULL CONSTRAINT DF_notifications_status DEFAULT 'draft',
        target_type VARCHAR(16) NOT NULL CONSTRAINT DF_notifications_target DEFAULT 'all',
        action_label NVARCHAR(80) NULL,
        action_url NVARCHAR(500) NULL,
        created_by NVARCHAR(255) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_notifications_created DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_notifications_updated DEFAULT SYSUTCDATETIME(),
        published_at DATETIME2(0) NULL,
        scheduled_at DATETIME2(0) NULL,
        archived_at DATETIME2(0) NULL,
        CONSTRAINT CK_notifications_type CHECK (type IN ('general','announcement','system','warning','information','success','reminder')),
        CONSTRAINT CK_notifications_priority CHECK (priority IN ('normal','important','high','critical')),
        CONSTRAINT CK_notifications_status CHECK (status IN ('draft','scheduled','published','archived')),
        CONSTRAINT CK_notifications_target CHECK (target_type IN ('all','selected','role','department')),
        CONSTRAINT CK_notifications_action_url CHECK (action_url IS NULL OR action_url LIKE '/%')
    );
END;

IF OBJECT_ID(N'dbo.notification_targets', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.notification_targets (
        notification_id BIGINT NOT NULL,
        target_value NVARCHAR(255) NOT NULL,
        CONSTRAINT PK_notification_targets PRIMARY KEY (notification_id, target_value),
        CONSTRAINT FK_notification_targets_notification FOREIGN KEY (notification_id)
            REFERENCES dbo.notifications(id) ON DELETE CASCADE
    );
END;

IF OBJECT_ID(N'dbo.user_notifications', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.user_notifications (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_user_notifications PRIMARY KEY,
        notification_id BIGINT NOT NULL,
        username NVARCHAR(255) NOT NULL,
        delivered_at DATETIME2(0) NOT NULL CONSTRAINT DF_user_notifications_delivered DEFAULT SYSUTCDATETIME(),
        read_at DATETIME2(0) NULL,
        dismissed_at DATETIME2(0) NULL,
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_user_notifications_updated DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_user_notifications UNIQUE (notification_id, username),
        CONSTRAINT FK_user_notifications_notification FOREIGN KEY (notification_id)
            REFERENCES dbo.notifications(id) ON DELETE CASCADE
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_notifications_status_schedule' AND object_id = OBJECT_ID('dbo.notifications'))
    CREATE INDEX IX_notifications_status_schedule ON dbo.notifications(status, scheduled_at DESC) INCLUDE (created_at, published_at);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_notifications_admin_list' AND object_id = OBJECT_ID('dbo.notifications'))
    CREATE INDEX IX_notifications_admin_list ON dbo.notifications(created_at DESC) INCLUDE (status, type, priority, target_type);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_user_notifications_inbox' AND object_id = OBJECT_ID('dbo.user_notifications'))
    CREATE INDEX IX_user_notifications_inbox ON dbo.user_notifications(username, dismissed_at, delivered_at DESC) INCLUDE (notification_id, read_at);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_user_notifications_unread' AND object_id = OBJECT_ID('dbo.user_notifications'))
    CREATE INDEX IX_user_notifications_unread ON dbo.user_notifications(username, read_at) INCLUDE (dismissed_at, notification_id);
