-- Additive internal automation conversation domain.
IF OBJECT_ID(N'dbo.automation_conversations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.automation_conversations (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_automation_conversations PRIMARY KEY,
        subject NVARCHAR(180) NOT NULL,
        created_by NVARCHAR(255) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_automation_conversations_created DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_automation_conversations_updated DEFAULT SYSUTCDATETIME(),
        status VARCHAR(20) NOT NULL CONSTRAINT DF_automation_conversations_status DEFAULT 'open'
    );
END;
IF COL_LENGTH(N'dbo.automation_conversations', N'status') IS NULL
    ALTER TABLE dbo.automation_conversations ADD status VARCHAR(20) NOT NULL CONSTRAINT DF_automation_conversations_status DEFAULT 'open';
IF OBJECT_ID(N'dbo.automation_reopen_requests', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.automation_reopen_requests (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_automation_reopen_requests PRIMARY KEY,
        conversation_id BIGINT NOT NULL,
        requester NVARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL CONSTRAINT DF_automation_reopen_requests_status DEFAULT 'pending',
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_automation_reopen_requests_created DEFAULT SYSUTCDATETIME(),
        resolved_at DATETIME2(0) NULL,
        CONSTRAINT FK_automation_reopen_requests_conversation FOREIGN KEY (conversation_id) REFERENCES dbo.automation_conversations(id) ON DELETE CASCADE
    );
END;
IF OBJECT_ID(N'dbo.automation_participants', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.automation_participants (
        conversation_id BIGINT NOT NULL,
        username NVARCHAR(255) NOT NULL,
        CONSTRAINT PK_automation_participants PRIMARY KEY (conversation_id, username),
        CONSTRAINT FK_automation_participants_conversation FOREIGN KEY (conversation_id) REFERENCES dbo.automation_conversations(id) ON DELETE CASCADE
    );
END;
IF OBJECT_ID(N'dbo.automation_messages', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.automation_messages (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_automation_messages PRIMARY KEY,
        conversation_id BIGINT NOT NULL,
        author_username NVARCHAR(255) NOT NULL,
        body NVARCHAR(4000) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_automation_messages_created DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_automation_messages_conversation FOREIGN KEY (conversation_id) REFERENCES dbo.automation_conversations(id) ON DELETE CASCADE
    );
END;
IF OBJECT_ID(N'dbo.automation_attachments', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.automation_attachments (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_automation_attachments PRIMARY KEY,
        conversation_id BIGINT NOT NULL,
        message_id BIGINT NULL,
        uploaded_by NVARCHAR(255) NOT NULL,
        original_name NVARCHAR(255) NOT NULL,
        storage_name VARCHAR(180) NOT NULL,
        content_type VARCHAR(120) NOT NULL,
        size_bytes BIGINT NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_automation_attachments_created DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_automation_attachments_conversation FOREIGN KEY (conversation_id) REFERENCES dbo.automation_conversations(id) ON DELETE CASCADE,
        CONSTRAINT FK_automation_attachments_message FOREIGN KEY (message_id) REFERENCES dbo.automation_messages(id),
        CONSTRAINT CK_automation_attachments_size CHECK (size_bytes > 0)
    );
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_automation_participants_user' AND object_id=OBJECT_ID('dbo.automation_participants'))
    CREATE INDEX IX_automation_participants_user ON dbo.automation_participants(username, conversation_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_automation_messages_conversation' AND object_id=OBJECT_ID('dbo.automation_messages'))
    CREATE INDEX IX_automation_messages_conversation ON dbo.automation_messages(conversation_id, created_at, id);
