-- Slideshow management for the TV display
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='slides' AND xtype='U')
CREATE TABLE dbo.slides (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    filename      NVARCHAR(255) NOT NULL,
    original_name NVARCHAR(255) NOT NULL,
    is_active     BIT NOT NULL DEFAULT 1,
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
