IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.waiting_queue') AND type in (N'U'))
BEGIN
    CREATE TABLE dbo.waiting_queue (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        reception_number NVARCHAR(50)   NOT NULL,
        department      NVARCHAR(100)   NOT NULL DEFAULT N'نمونه‌گیری',
        added_by        NVARCHAR(255)   NULL,
        status          NVARCHAR(20)    NOT NULL DEFAULT N'waiting',
        created_at      DATETIME2       NOT NULL DEFAULT SYSUTCDATETIME(),
        called_at       DATETIME2       NULL
    );
    CREATE INDEX IX_waiting_queue_status ON dbo.waiting_queue (status);
END;
