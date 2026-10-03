<?php

namespace App\Support\Legacy;

use App\Support\Ticketing\NotificationPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Deliver a system event to every administrator — a port of
 * `publish_system_notification_to_admins()` in `app/api/routes/notifications.py`.
 *
 * The leave, overtime and hourly-pass write handlers call this when an employee
 * submits a request, so the request appears in the administrators' notification
 * inbox.  The audience is resolved the way the Python resolved it: every
 * `user_table` row whose persisted `role` is `admin`, compared case-insensitively
 * after trimming — **not** the session flag, and not the master-admin config
 * list.  A user who is an admin by role but has not opened the control centre
 * still has to see the request.
 *
 * The delivery itself is delegated to {@see NotificationPublisher}, which is the
 * port of `publish_system_notification()` and owns the `notifications` /
 * `notification_targets` / `user_notifications` writes.  The caller owns the
 * transaction, so the notification row commits with the request row.
 *
 * One deliberate omission: the Python's `publish_system_notification()` called
 * `_ensure_schema()` first, creating the notification tables on first use.  The
 * Laravel migration must not create objects in `userDB` (the same rule
 * `SessionRegistry` records), and the tables exist, so a missing table is a loud
 * failure rather than a silent create.
 */
final class LegacyNotificationPublisher
{
    /**
     * Publish a notification to every account whose persisted role is admin.
     *
     * @return int The notification id, or 0 when there are no admins.
     */
    public static function publishToAdmins(
        string $title,
        string $content,
        string $notificationType = 'information',
        string $priority = 'normal',
        string $actionUrl = '/admin',
    ): int {
        $admins = DB::connection()->table('user_table')
            ->whereRaw("LOWER(RTRIM(COALESCE(role, ''))) = 'admin'")
            ->pluck('username')
            ->map(static fn ($username): string => trim((string) $username))
            ->values()
            ->all();

        return NotificationPublisher::publish(
            $title,
            $content,
            $admins,
            $notificationType,
            $priority,
            $actionUrl,
        );
    }
}
