<?php

namespace App\Models;

use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `dbo.queue_tickets` — the numbered reception queue (42 rows).
 *
 * Three facts about this table are business rules, not implementation details.
 *
 * **1. The number is continuous and global; it never resets.**  The allocator is
 * the existing query, verbatim:
 *
 *     SELECT ISNULL(MAX(ticket_number),0)+1 FROM dbo.queue_tickets WITH (UPDLOCK, HOLDLOCK)
 *
 * The `2000` that appears in the code is only an input bound
 * (`if number < 1 or number > 2000`) and `total_expected`; it is *not* a wrap
 * point, and a "next number" that restarts daily would break the printed labels and
 * the display.
 *
 * **2. `lockForUpdate()` cannot be used to take that lock.**  Laravel's SQL Server
 * grammar compiles `compileLock()` to an empty string, so `lockForUpdate()` is a
 * silent no-op here and the read would race.  The allocator below therefore issues
 * the raw statement with its `WITH (UPDLOCK, HOLDLOCK)` hint, inside the caller's
 * transaction — hence {@see self::nextNumber()}.
 *
 * **3. Patient data is protected.**  `patient_name`, `patient_age`,
 * `patient_national_id`, `patient_phone`, `insurance_base` and
 * `insurance_extra` are `PII`; the existing `_guard_queue_pii` wrapper allowed
 * 30 requests/minute per IP and required a same-site `Origin`.  Nothing in this
 * model may publish those columns; the guarding belongs to the endpoint.
 *
 * `ticket_date` is a `date` defaulting to today and `status` moves
 * `waiting` → `called` → `completed`.
 */
#[Table(name: 'queue_tickets', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'ticket_number', 'ticket_date', 'status', 'service', 'called_for',
    'called_at', 'completed_at', 'patient_name', 'patient_age',
    'patient_national_id', 'patient_phone', 'insurance_base', 'insurance_extra',
])]
class QueueTicket extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CALLED = 'called';

    public const STATUS_COMPLETED = 'completed';

    /** The default service, as stored in the column default. */
    public const DEFAULT_SERVICE = 'پذیرش';

    /** The only service that has a dedicated display path. */
    public const SERVICE_SAMPLING = 'نمونه‌گیری';

    /** Upper bound enforced by the existing input validation, not a wrap point. */
    public const MAX_NUMBER = 2000;

    /** Columns that must never be handed to an unauthorised caller. */
    public const PII_COLUMNS = [
        'patient_name', 'patient_age', 'patient_national_id', 'patient_phone',
        'insurance_base', 'insurance_extra',
    ];

    /**
     * `_QUEUE_PII_FIELDS` — what `GET /api/queue/list` withholds from an anonymous
     * caller.  **Five** columns, not six: `patient_age` is absent from the Python's tuple,
     * so the kiosk's counter has always shown an age next to a ticket with no name.
     *
     * Two lists on purpose rather than one — {@see self::PII_COLUMNS} states what the
     * data *is*, and this states what one particular endpoint *does*, and conflating them
     * would either have hidden the age (a visible change to the running console) or
     * published the name.
     */
    public const LIST_HIDDEN_COLUMNS = [
        'patient_name', 'patient_national_id', 'patient_phone',
        'insurance_base', 'insurance_extra',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_date' => 'date',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
            'ticket_number' => 'integer',
        ];
    }

    /**
     * Allocate the next continuous queue number.
     *
     * **Must be called inside a transaction.**  The statement takes an update lock
     * with `HOLDLOCK` (serialisable range lock on the aggregate), so the number is
     * only safe while that lock is held; releasing it before the `INSERT` reopens
     * the race the lock exists to close.  A caller outside a transaction gets a
     * `RuntimeException` rather than a silently wrong number.
     */
    public static function nextNumber(): int
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'QueueTicket::nextNumber() must run inside a transaction: the '
                .'WITH (UPDLOCK, HOLDLOCK) lock is released when the surrounding '
                .'transaction ends, and the number would then be racy.',
            );
        }

        $row = DB::selectOne(
            'SELECT ISNULL(MAX(ticket_number), 0) + 1 AS next_number '
            .'FROM dbo.queue_tickets WITH (UPDLOCK, HOLDLOCK)',
        );

        return (int) $row->next_number;
    }

    /** Today's queue, in the order the display shows it. */
    public function scopeForDate(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('ticket_date', $date);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Still waiting to be called. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /** The service label, trimmed of padding. */
    public function serviceName(): string
    {
        return PersianText::trim($this->getAttribute('service'));
    }
}
