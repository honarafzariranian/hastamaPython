<?php

namespace App\Support\Ticketing;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publish a durable event from another subsystem into the notification inbox.
 *
 * Ported from `publish_system_notification` in `app/api/routes/notifications.py`.
 * Ticketing and attendance remain separate domains; they only call this small
 * delivery boundary when a user-facing event should appear in notifications.
 * The caller owns the transaction so the event is committed with its source
 * change whenever possible.
 */
final class NotificationPublisher
{
    /**
     * @param  array<int, string>  $targets
     */
    public static function publish(
        string $title,
        string $content,
        array $targets,
        string $notificationType = 'information',
        string $priority = 'normal',
        string $actionUrl = '/user_panel',
    ): int {
        $values = [];

        foreach ($targets as $value) {
            $trimmed = trim((string) $value);

            if ($trimmed !== '' && ! in_array($trimmed, $values, true)) {
                $values[] = $trimmed;
            }
        }

        if ($values === []) {
            return 0;
        }

        $allowedTypes = ['general', 'announcement', 'system', 'warning', 'information', 'success', 'reminder'];

        if (! in_array($notificationType, $allowedTypes, true)) {
            $notificationType = 'information';
        }

        $allowedPriorities = ['normal', 'important', 'high', 'critical'];

        if (! in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        $connection = DB::connection();

        $notificationId = $connection->table('notifications')->insertGetId([
            'title' => mb_substr(trim($title), 0, 180),
            'content' => mb_substr(trim($content), 0, 4000),
            'type' => $notificationType,
            'priority' => $priority,
            'status' => 'published',
            'target_type' => 'selected',
            'action_url' => $actionUrl,
            'created_by' => 'سامانه',
            'published_at' => DB::raw('SYSUTCDATETIME()'),
        ]);

        foreach ($values as $value) {
            $connection->table('notification_targets')->insert([
                'notification_id' => $notificationId,
                'target_value' => $value,
            ]);
        }

        self::fanout($connection, $notificationId);

        return $notificationId;
    }

    /**
     * Insert one `user_notifications` row per member of the audience who does
     * not already have one.
     *
     * @return int How many `user_notifications` rows the fan-out inserted.
     */
    private static function fanout($connection, int $notificationId): int
    {
        $row = $connection->table('notifications')
            ->where('id', $notificationId)
            ->value('target_type');

        $targetType = trim((string) $row);

        $condition = match ($targetType) {
            'all' => '1 = 1',
            'selected' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.username))',
            'role' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.role))',
            'department' => 'EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.department))',
            default => '1 = 1',
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
            $params,
        ));
    }
}
