<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.notification_targets` — the audience list of a notification (2 rows).
 *
 * One row per listed identifier; what the value *means* depends on the parent
 * notification's `target_type`:
 *
 * | `target_type` | `target_value` holds |
 * |---|---|
 * | `selected` | a username |
 * | `role` | a role, e.g. `admin` |
 * | `department` | a department name |
 *
 * `all` never uses this table.  The live fan-out SQL compares with
 * `RTRIM(t.target_value) = RTRIM(u.username)` (or `u.role`, `u.department`), so
 * the padding is stripped on **both** sides at match time — that is why the
 * values here are stored as typed and not normalised on write.
 *
 * Composite primary key `(notification_id, target_value)`: Eloquent has no
 * composite-key support, so `notification_id` is declared as the key for reads and
 * the unique index remains the real guarantee.  This model is not the place to
 * update individual rows.
 */
#[Table(name: 'notification_targets', key: 'notification_id', keyType: 'int', incrementing: false, timestamps: false)]
#[Fillable(['notification_id', 'target_value'])]
class NotificationTarget extends LegacyModel
{
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id', 'id');
    }
}
