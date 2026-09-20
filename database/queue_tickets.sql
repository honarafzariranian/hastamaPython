/* Queue Ticketing System — Samaneh Nobatdehi
   Visitors take tickets from a touchscreen kiosk. */
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.queue_tickets', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.queue_tickets (
        id              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_queue_tickets PRIMARY KEY,
        ticket_number   INT             NOT NULL,
        ticket_date     DATE            NOT NULL DEFAULT CAST(SYSUTCDATETIME() AS DATE),
        status          NVARCHAR(20)    NOT NULL DEFAULT N'waiting',
        service         NVARCHAR(50)    NULL DEFAULT N'\u067E\u0686\u06CC\u0631\u0634',
        called_for      NVARCHAR(50)    NULL,
        called_at       DATETIME2(0)    NULL,
        completed_at    DATETIME2(0)    NULL,
        created_at      DATETIME2(0)    NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE INDEX IX_queue_tickets_date_status ON dbo.queue_tickets (ticket_date, status);
    CREATE INDEX IX_queue_tickets_number ON dbo.queue_tickets (ticket_number);
END;

/* Add service column if missing (upgrade for existing databases) */
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'service')
    ALTER TABLE dbo.queue_tickets ADD service NVARCHAR(50) NULL DEFAULT N'\u067E\u0686\u06CC\u0631\u0634';

