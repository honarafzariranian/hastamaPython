IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.display_queue') AND type in (N'U'))
BEGIN
    CREATE TABLE dbo.display_queue (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        reception_number NVARCHAR(50)   NOT NULL,
        department      NVARCHAR(100)   NOT NULL DEFAULT N'نمونه‌گیری',
        called_by       NVARCHAR(255)   NULL,
        slot_position   INT             NOT NULL DEFAULT 0,
        created_at      DATETIME2       NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_display_queue_position ON dbo.display_queue (slot_position);
END;
