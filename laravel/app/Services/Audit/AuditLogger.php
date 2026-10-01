<?php

namespace App\Services\Audit;

use App\Models\AdminAction;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Services\Auth\SessionRegistry;
use App\Support\Legacy\LegacyIds;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail, the security-event feed and the admin activity feed.
 *
 * A faithful port of the parts of `app/services/audit.py` the authentication and
 * authorisation paths need.  Two behaviours of the original are load-bearing:
 *
 * 1. **Auditing never breaks the application.**  The Python functions caught every
 *    exception and returned the generated id, so a full disk or a locked table
 *    could not take a login down.  That is preserved — but a failure is *logged*
 *    here, because a silently lost audit trail is itself an incident the operator
 *    should see.
 * 2. **The generated id is returned even when the insert failed**, so callers can
 *    still correlate their response with the log line.
 *
 * The remaining audit helpers (`create_password_reset_request`, `approve_…`,
 * `verify_recovery_code`, `log_system_error`) belong to the password-recovery and
 * error-reporting phases and will be added to a dedicated service there.
 */
final class AuditLogger
{
    /** Event types the application writes. */
    public const TYPE_AUTHENTICATION = 'AUTHENTICATION';

    public const TYPE_CAPTCHA = 'CAPTCHA';

    public const TYPE_SECURITY = 'SECURITY';

    public const TYPE_SUPPORT = 'SUPPORT';

    /** Severities the control centre filters on. */
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Insert an `audit_logs` row and return the generated event id.
     *
     * @param  array<string, mixed>  $context
     */
    public function logEvent(
        string $eventType,
        string $action,
        array $context = [],
    ): string {
        $eventId = $context['event_id'] ?? LegacyIds::eventId();

        try {
            AuditLog::query()->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'action' => $action,
                'username' => $this->trim($context['username'] ?? null, 255),
                'role' => $this->trim($context['role'] ?? null, 16),
                'module' => $this->trim($context['module'] ?? null, 64),
                'resource_type' => $this->trim($context['resource_type'] ?? null, 64),
                'resource_id' => $this->trim($context['resource_id'] ?? null, 128),
                'request_id' => $this->trim($context['request_id'] ?? null, 32),
                'session_id' => $this->trim($context['session_id'] ?? null, 255),
                'ip_address' => $this->trim($context['ip_address'] ?? null, 45),
                'user_agent' => $this->trim($context['user_agent'] ?? null, 500),
                'status' => $this->trim($context['status'] ?? 'success', 16),
                'severity' => $this->trim($context['severity'] ?? self::SEVERITY_INFO, 16),
                'before_data' => $this->json($context['before_data'] ?? null),
                'after_data' => $this->json($context['after_data'] ?? null),
                'metadata' => $this->json($context['metadata'] ?? null),
                'error_id' => $this->trim($context['error_id'] ?? null, 32),
            ]);
        } catch (Throwable $exception) {
            $this->report('audit log write failed', $eventId, $exception);
        }

        return $eventId;
    }

    /**
     * Insert a `security_events` row — something that needs looking at, as opposed
     * to {@see logEvent}'s record of something that happened.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function securityEvent(
        string $eventType,
        string $description,
        array $context = [],
    ): string {
        $eventId = $context['event_id'] ?? LegacyIds::eventId();

        try {
            SecurityEvent::query()->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'severity' => $this->trim($context['severity'] ?? self::SEVERITY_MEDIUM, 16),
                'username' => $this->trim($context['username'] ?? null, 255),
                'ip_address' => $this->trim($context['ip_address'] ?? null, 45),
                'description' => mb_substr($description, 0, 1000),
                'metadata' => $this->json($context['metadata'] ?? null),
                'status' => SecurityEvent::STATUS_OPEN,
            ]);
        } catch (Throwable $exception) {
            $this->report('security event write failed', $eventId, $exception);
        }

        return $eventId;
    }

    /**
     * Write the operator-facing admin action feed entry.
     *
     * Written alongside the machine-readable audit row for the same operation,
     * because the control centre renders this one.
     *
     * @param  array<string, mixed>  $context
     */
    public function adminAction(string $adminUsername, string $action, array $context = []): string
    {
        $actionId = $context['action_id'] ?? LegacyIds::actionId();

        try {
            AdminAction::query()->insert([
                'action_id' => $actionId,
                'admin_username' => $adminUsername,
                'action' => $this->trim($action, 64),
                'target_username' => $this->trim($context['target_username'] ?? null, 255),
                'target_type' => $this->trim($context['target_type'] ?? null, 64),
                'target_id' => $this->trim($context['target_id'] ?? null, 128),
                'description' => $this->trim($context['description'] ?? null, 1000),
                'before_data' => $this->json($context['before_data'] ?? null),
                'after_data' => $this->json($context['after_data'] ?? null),
                'ip_address' => $this->trim($context['ip_address'] ?? null, 45),
                'request_id' => $this->trim($context['request_id'] ?? null, 32),
            ]);
        } catch (Throwable $exception) {
            $this->report('admin action write failed', $actionId, $exception);
        }

        return $actionId;
    }

    /**
     * Record a successful login in the registry.
     *
     * The Python `track_session_login` wrote the session row; the registry service
     * already does that on {@see SessionRegistry::register()},
     * so this method only emits the audit pair the dashboard correlates on.
     */
    public function trackSessionLogin(string $sessionKey, string $username, array $context = []): void
    {
        $this->logEvent(self::TYPE_AUTHENTICATION, 'session_started', array_merge($context, [
            'username' => $username,
            'session_id' => $sessionKey,
        ]));
    }

    /** Advance a session's `last_activity` (the registry does the write). */
    public function trackSessionActivity(string $sessionKey, array $context = []): void
    {
        $this->logEvent(self::TYPE_AUTHENTICATION, 'session_activity', array_merge($context, [
            'session_id' => $sessionKey,
            'severity' => self::SEVERITY_LOW,
        ]));
    }

    /** Record a logout. */
    public function trackSessionLogout(string $sessionKey, array $context = []): void
    {
        $this->logEvent(self::TYPE_AUTHENTICATION, 'session_ended', array_merge($context, [
            'session_id' => $sessionKey,
        ]));
    }

    private function trim(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function report(string $message, string $eventId, Throwable $exception): void
    {
        // Rule 1: auditing never breaks the caller. Rule 2: it is never silent.
        Log::warning($message, [
            'event_id' => $eventId,
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);
    }
}
