<?php

namespace App\Support\Notifications;

use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyWhitespace;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The notification module's data operations, ported from the module-level functions of
 * `app/api/routes/notifications.py`.
 *
 * Four of them are the Python's own helpers, and their behaviour is the contract:
 *
 * * `_publish_due(cursor)` — the scheduled sweep.  It runs **inside** the admin list,
 *   the user list and the unread-count handler, so a due notification is published by
 *   the next request that happens to arrive even though the background scheduler is
 *   down.  The scheduler itself is a separate question — see `routes/console.php` in
 *   the report.
 * * `_publish(cursor, id, actor)` — fan out, then mark published.  The `actor` argument
 *   is accepted by the Python and never used; it is not reproduced.
 * * `_validate_target(payload)` — the handler-level audience and schedule checks,
 *   which run **after** the pydantic model and **before** the row is read.
 * * `_parse_schedule(value)` — an ISO timestamp to a naive UTC `Y-m-d H:i:s`, with the
 *   fraction dropped and a naive value read as UTC.
 *
 * Every write runs inside a transaction, because the Python opened a connection per
 * handler and committed at the end — `conn.rollback()` on the way out of an exception
 * is what kept an insert and its fan-out atomic.
 */
final class NotificationService
{
    /**
     * `_publish_due(cursor)` — publish every scheduled notification whose time has come.
     *
     * The Python selected the due ids and published each one; a row that vanished
     * between the two statements would raise the 404 from `_publish`, which the
     * surrounding handler turned into a 500.  That cannot happen here either — the
     * `archived` check inside `_publish` is the only refusal a due row can meet, and a
     * due row is `scheduled`, never `archived`.
     */
    public function publishDue(): void
    {
        $rows = DB::connection()->select(
            "SELECT id FROM notifications WHERE status = 'scheduled' AND scheduled_at <= SYSUTCDATETIME()"
        );

        foreach ($rows as $row) {
            $this->publish((int) $row->id);
        }
    }

    /**
     * `_publish(cursor, notification_id, actor)` — fan out to the audience, then publish.
     *
     * @return int How many `user_notifications` rows the fan-out inserted.
     *
     * @throws LegacyHttpException 404 when the notification is gone, 409 when it is
     *                             archived ("اعلان بایگانی‌شده قابل انتشار نیست.").
     */
    public function publish(int $notificationId): int
    {
        $connection = DB::connection();

        $row = $connection->selectOne('SELECT status FROM notifications WHERE id = ?', [$notificationId]);

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'اعلان پیدا نشد.');
        }

        if (trim((string) $row->status) === 'archived') {
            throw LegacyHttpException::detail(409, 'اعلان بایگانی‌شده قابل انتشار نیست.');
        }

        $delivered = $this->fanout($connection, $notificationId);

        $connection->update(
            "UPDATE notifications SET status = 'published',
             published_at = COALESCE(published_at, SYSUTCDATETIME()),
             scheduled_at = NULL, updated_at = SYSUTCDATETIME() WHERE id = ?",
            [$notificationId]
        );

        return $delivered;
    }

    /**
     * `_fanout(cursor, notification_id)` — insert one `user_notifications` row per
     * member of the audience who does not already have one.
     *
     * The four audience kinds are the Python's four SQL fragments, verbatim; the
     * parameter list is `(notification_id, *params, notification_id)` — the fan-out id,
     * then the condition's id for a targeted audience, then the `NOT EXISTS` id.
     *
     * @return int `max(0, cursor.rowcount)`, the Python's own guard.
     */
    private function fanout(Connection $connection, int $notificationId): int
    {
        $row = $connection->selectOne('SELECT target_type FROM notifications WHERE id = ?', [$notificationId]);

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'اعلان پیدا نشد.');
        }

        $targetType = trim((string) $row->target_type);

        // The Python's `condition = {...}[target_type]` — a dict lookup that raises
        // `KeyError` (a 500) on a value outside the four.  The column's CHECK
        // constraint makes that unreachable; `match` without a default is the same
        // refusal in PHP.
        $condition = match ($targetType) {
            'all' => '1 = 1',
            'selected' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.username))',
            'role' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.role))',
            'department' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.department))',
        };

        $params = [$notificationId];

        if ($targetType !== 'all') {
            $params[] = $notificationId;
        }

        $params[] = $notificationId;

        return max(0, $connection->affectingStatement(
            "INSERT INTO user_notifications (notification_id, username)
             SELECT ?, RTRIM(u.username) FROM user_table u
             WHERE {$condition}
               AND NOT EXISTS (SELECT 1 FROM user_notifications un
                   WHERE un.notification_id = ? AND RTRIM(un.username) = RTRIM(u.username))",
            $params
        ));
    }

    /**
     * `_validate_target(payload)` — the audience and schedule checks a valid model still
     * has to pass.
     *
     * The targets are deduplicated after stripping, keeping first-occurrence order —
     * `list(dict.fromkeys(...))`.  The two refusals are the module's own 422 bodies,
     * raised as `HTTPException(422, detail=…)`, so they are `{"detail": "…"}` and not
     * the pydantic list.
     *
     * @param  array<string, mixed>  $input  The validated `NotificationInput` values.
     * @return array<int, string>
     *
     * @throws LegacyHttpException 422 "حداقل یک مخاطب انتخاب کنید." when a non-`all`
     *                             audience has no targets, or "زمان انتشار باید در آینده
     *                             باشد." when a scheduled notification has no future time.
     */
    public function validateTarget(array $input): array
    {
        $targets = [];

        foreach ($input['targets'] as $value) {
            $value = LegacyWhitespace::strip((string) $value);

            if ($value !== '' && ! in_array($value, $targets, true)) {
                $targets[] = $value;
            }
        }

        if ($input['target_type'] !== 'all' && $targets === []) {
            throw LegacyHttpException::detail(422, 'حداقل یک مخاطب انتخاب کنید.');
        }

        if ($input['status'] === 'scheduled') {
            $scheduled = $this->parseSchedule($input['scheduled_at']);

            if ($scheduled === null || $this->utcNow() >= $scheduled) {
                throw LegacyHttpException::detail(422, 'زمان انتشار باید در آینده باشد.');
            }
        }

        return $targets;
    }

    /**
     * `_parse_schedule(value)` — an ISO timestamp to a naive UTC `Y-m-d H:i:s`.
     *
     * `datetime.fromisoformat(value.replace("Z", "+00:00"))`, then a naive value is
     * read as UTC, converted to UTC, and stripped of both the tzinfo and the fraction
     * — which is why the front-end's `…08:53:41.864Z` is stored as `08:53:41`.
     *
     * @throws LegacyHttpException 422 "زمان‌بندی نامعتبر است." on an unparseable value.
     */
    public function parseSchedule(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Python's `str.replace("Z", "+00:00")` — every occurrence, case-sensitive.
        $normalized = str_replace('Z', '+00:00', $value);

        try {
            $parsed = new DateTimeImmutable($normalized, new DateTimeZone('UTC'));
        } catch (Exception) {
            throw LegacyHttpException::detail(422, 'زمان‌بندی نامعتبر است.');
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The current UTC time as a whole-second `Y-m-d H:i:s`, for the schedule comparison.
     *
     * The Python compared `scheduled <= datetime.utcnow()`, where `utcnow()` carries
     * microseconds and the parsed schedule does not — so a schedule of the current
     * second is already "due".  Comparing the formatted strings reproduces that.
     */
    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
