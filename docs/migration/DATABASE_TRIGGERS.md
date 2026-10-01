# Database triggers — business logic that lives in SQL Server

Nine triggers are installed on five tables. **They are part of the application's behaviour.**
A Laravel implementation that writes to these tables gets this behaviour for free; one that
duplicates it in PHP will drift. The migration must:

1. never drop or alter these triggers,
2. never write `leave_report` or `ezafe_total_table` directly — those two are owned by the
   triggers (`trg_UpdateLeaveReport`, `trg_CalculateDailyOvertime` + `trg_UpdateTotalOvertime`),
   and are enforced as read-only by `App\Models\LeaveReport` / `App\Models\OvertimeTotal`,
3. re-read the row after a write when the computed column matters, because the trigger fires
   **after** the statement.

> **Correction (Phase 3).**  An earlier revision of this list also named `totalpass_table` as
> trigger-owned and untouchable.  That was wrong, and reading the application showed it:
> `app/main.py` inserts the admin-approval row itself
> (`INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)
> VALUES (?, ?, ?, ?, N'انتظار تایید')`) with the comment «این جدول صف تأیید مدیریت است؛ بدون آن،
> درخواست تازه در پنل مدیر دیده نمی‌شود» — *this table is the admin approval queue; without it a
> new request is invisible in the admin panel*.  It also updates `status` on approval.  So
> `totalpass_table` is an application-writable queue (see `App\Models\HourlyPass`), and only
> `leave_report` and `ezafe_total_table` are genuinely read-only.
>
> **Observed duplication risk, deliberately not changed.**  Because `trg_*PssToTotalPass` inserts
> into `totalpass_table` from `inserted` whenever `total_time_*` is not null, and the application
> inserts its own row for the same pass, a single submission can in principle produce two queue
> rows.  Which one the admin sees is not documented anywhere, and the triggers cannot be altered
> (rule 1).  The migration reproduces the existing behaviour *including* this overlap rather than
> guessing at a fix; it is recorded as an open item in `docs/migration/OPEN_QUESTIONS.md`.

| Trigger | Table | Timing | What it does |
|---|---|---|---|
| `trg_CalculateTotalTime` | `avalpss_table` | AFTER INSERT, UPDATE | Sets `total_time_aval` = absolute difference between `officialtime` and `entrytime`. |
| `trg_AvalPssToTotalPass` | `avalpss_table` | AFTER INSERT, UPDATE | Copies the row into `totalpass_table` as `pass_title='avalpss'` with status `انتظار تایید` when `total_time_aval` is not null. |
| `trg_CalculateTotalTimeBeynpss` | `beynpss_table` | AFTER INSERT, UPDATE | Sets `total_time_beyn` = \|`exitTime` − `entryTime`\|. |
| `trg_BeynPssToTotalPass` | `beynpss_table` | AFTER INSERT, UPDATE | Copies the row into `totalpass_table` as `pass_title='beyn'` with status `انتظار تایید`. |
| `trg_CalculateTotalTimeAkhrpss` | `akhrpss_table` | AFTER INSERT, UPDATE | Sets `total_time_akhr` = \|`officialTime` − `exitTime`\|. |
| `trg_AkhrPssToTotalPass` | `akhrpss_table` | AFTER INSERT, UPDATE | Copies the row into `totalpass_table` as `pass_title='akhr'` with status `انتظار تایید`. |
| `trg_UpdateLeaveReport` | `mrkhc_table` | AFTER INSERT, UPDATE, DELETE | Deletes stale users from `leave_report`, then MERGEs `SUM(CAST(days AS INT))` over rows whose `LTRIM(RTRIM(status)) = N'تایید شده'` and sets `remaining_days = 30 − total_days`. **The 30-day entitlement is hard-coded here.** |
| `trg_CalculateDailyOvertime` | `ezafe_table` | AFTER INSERT, UPDATE | Sets `daily_overtime` from the row's `from_time`/`to_time`. |
| `trg_UpdateTotalOvertime` | `ezafe_table` | AFTER INSERT, UPDATE | Maintains `ezafe_total_table.total_ezafe_time` per username. |

## Verbatim definitions (from `sys.triggers`)

### TRIGGER trg_AvalPssToTotalPass ON avalpss_table

```sql
CREATE TRIGGER trg_AvalPssToTotalPass

ON avalpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    -- وارد کردن رکوردهای جدید و همچنین رکوردهای قبلی که در جدول avalpss_table وجود دارند

    INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)

    SELECT username, date, 'avalpss', total_time_aval, 'انتظار تایید'

    FROM inserted

    WHERE total_time_aval IS NOT NULL;

END;
```

### TRIGGER trg_BeynPssToTotalPass ON beynpss_table

```sql
CREATE TRIGGER trg_BeynPssToTotalPass

ON beynpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)

    SELECT username, date, 'beyn', total_time_beyn, 'انتظار تایید'

    FROM inserted

    WHERE total_time_beyn IS NOT NULL;

END;
```

### TRIGGER trg_AkhrPssToTotalPass ON akhrpss_table

```sql
CREATE TRIGGER trg_AkhrPssToTotalPass

ON akhrpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)

    SELECT username, date, 'akhr', total_time_akhr, 'انتظار تایید'

    FROM inserted

    WHERE total_time_akhr IS NOT NULL;

END;
```

### TRIGGER trg_CalculateTotalTime ON avalpss_table

```sql
CREATE TRIGGER trg_CalculateTotalTime

ON avalpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    UPDATE avalpss_table

    SET total_time_aval = 

        CAST(DATEADD(SECOND, ABS(DATEDIFF(SECOND, inserted.entrytime, inserted.officialtime)), '00:00:00') AS TIME)

    FROM avalpss_table

    INNER JOIN inserted ON avalpss_table.id = inserted.id

END
```

### TRIGGER trg_UpdateLeaveReport ON mrkhc_table

```sql
CREATE TRIGGER trg_UpdateLeaveReport  

ON dbo.mrkhc_table  

AFTER INSERT, UPDATE, DELETE  

AS  

BEGIN  

    SET NOCOUNT ON;



    -- حذف کاربران از leave_report که دیگر در mrkhc_table وجود ندارند

    DELETE FROM leave_report 

    WHERE username NOT IN (SELECT DISTINCT username FROM mrkhc_table);



    -- به‌روزرسانی یا درج اطلاعات کاربران در leave_report

    MERGE leave_report AS target  

    USING (  

        -- محاسبه مجموع روزهای مرخصی تایید شده برای هر کاربر

        SELECT   

            username,    

            SUM(CAST(days AS INT)) AS total_days  

        FROM   

            mrkhc_table  

        WHERE   

            LTRIM(RTRIM(status)) = N'تایید شده'  

        GROUP BY   

            username  

    ) AS source  

    ON target.username = source.username  

    WHEN MATCHED THEN   

        UPDATE SET   

            total_days = source.total_days,  

            remaining_days = 30 - source.total_days  

    WHEN NOT MATCHED THEN  

        INSERT (username, total_days, remaining_days)  

        VALUES (source.username, source.total_days, 30 - source.total_days);



END;
```

### TRIGGER trg_CalculateTotalTimeBeynpss ON beynpss_table

```sql
CREATE TRIGGER trg_CalculateTotalTimeBeynpss

ON beynpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    UPDATE beynpss_table

    SET total_time_beyn = 

        CAST(DATEADD(SECOND, ABS(DATEDIFF(SECOND, inserted.exitTime, inserted.entryTime)), '00:00:00') AS TIME)

    FROM beynpss_table

    INNER JOIN inserted ON beynpss_table.id = inserted.id

END
```

### TRIGGER trg_CalculateTotalTimeAkhrpss ON akhrpss_table

```sql
CREATE TRIGGER trg_CalculateTotalTimeAkhrpss

ON akhrpss_table

AFTER INSERT, UPDATE

AS

BEGIN

    UPDATE akhrpss_table

    SET total_time_akhr = 

        CAST(DATEADD(SECOND, ABS(DATEDIFF(SECOND, inserted.officialTime, inserted.exitTime)), '00:00:00') AS TIME)

    FROM akhrpss_table

    INNER JOIN inserted ON akhrpss_table.id = inserted.id

END
```

### TRIGGER trg_CalculateDailyOvertime ON ezafe_table

```sql
CREATE TRIGGER trg_CalculateDailyOvertime

ON dbo.ezafe_table

AFTER INSERT, UPDATE

AS

BEGIN

    -- محاسبه تفاوت زمان و به‌روزرسانی ستون daily_overtime

    UPDATE ezafe_table

    SET daily_overtime = CAST(DATEADD(SECOND, DATEDIFF(SECOND, i.from_time, i.to_time), '00:00:00') AS TIME(7))

    FROM ezafe_table e

    INNER JOIN inserted i ON e.id = i.id;  -- استفاده از ستون id برای شناسایی رکوردها

END;
```

### TRIGGER trg_UpdateTotalOvertime ON ezafe_table

```sql
CREATE   TRIGGER trg_UpdateTotalOvertime

ON ezafe_table

AFTER INSERT, UPDATE, DELETE

AS

BEGIN

    SET NOCOUNT ON;



    -- به‌روزرسانی یا درج مقدار کل اضافه‌کاری برای هر کاربر فقط برای رکوردهای تایید شده

    MERGE INTO ezafe_total_table AS target

    USING (

        SELECT 

            username, 

            SUM(DATEDIFF(SECOND, '00:00:00', daily_overtime)) AS total_seconds

        FROM ezafe_table 

        WHERE status = N'تایید شده'

        GROUP BY username

    ) AS source

    ON target.username = source.username

    WHEN MATCHED THEN 

        UPDATE SET target.total_ezafe_time = DATEADD(SECOND, source.total_seconds, '00:00:00')

    WHEN NOT MATCHED THEN

        INSERT (username, total_ezafe_time) 

        VALUES (source.username, DATEADD(SECOND, source.total_seconds, '00:00:00'));

END;
```

