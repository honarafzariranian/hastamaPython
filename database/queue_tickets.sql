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

/* Patient details entered at the kiosk */
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'patient_name')
    ALTER TABLE dbo.queue_tickets ADD patient_name NVARCHAR(200) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'patient_age')
    ALTER TABLE dbo.queue_tickets ADD patient_age NVARCHAR(3) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'patient_national_id')
    ALTER TABLE dbo.queue_tickets ADD patient_national_id NVARCHAR(10) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'patient_phone')
    ALTER TABLE dbo.queue_tickets ADD patient_phone NVARCHAR(11) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'insurance_base')
    ALTER TABLE dbo.queue_tickets ADD insurance_base NVARCHAR(100) NULL;
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.queue_tickets') AND name = N'insurance_extra')
    ALTER TABLE dbo.queue_tickets ADD insurance_extra NVARCHAR(100) NULL;
