/* Reception call schema for Microsoft SQL Server.
   Samaneh Farakhan Nemonegiri — Sample Collection Call System.
   Run once with a principal allowed to create tables/indexes. The API also
   executes this migration lazily, making existing installations upgrade safely. */
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.reception_calls', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.reception_calls (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_reception_calls PRIMARY KEY,
        reception_number NVARCHAR(50) NOT NULL,
        department NVARCHAR(100) NOT NULL DEFAULT N'نمونه‌گیری',
        called_by NVARCHAR(255) NOT NULL,
        is_test BIT NOT NULL DEFAULT 0,
        called_at DATETIME2(0) NOT NULL CONSTRAINT DF_reception_calls_called_at DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_reception_calls_called_at
        ON dbo.reception_calls (called_at DESC);
END;
