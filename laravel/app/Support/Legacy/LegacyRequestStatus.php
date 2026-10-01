<?php

namespace App\Support\Legacy;

/**
 * The Persian status vocabulary shared by the three approval queues.
 *
 * `mrkhc_table` (leave), `totalpass_table` (hourly pass) and `ezafe_table`
 * (overtime) all drive the same admin approve/reject workflow, and all three use
 * the *same* status strings.  They live here once so a typo cannot make one queue
 * quietly stop matching the filter the admin panel uses.
 *
 * Sources, all in the running application:
 *
 * * inserted as `N'انتظار تایید'` — `app/main.py` (hourly pass) and
 *   `app/core/password_utils.py` (`mrkhc_table.status` default);
 * * approved as `'تایید شده'` — `app/static/js/admin.js` (`changeStatus`,
 *   `changeEzafeStatusForApproval`, `changeHourlyPassStatus`, …);
 * * rejected as `'رد شده'` — same handlers;
 * * cancelled as `'انصراف'` — `admin.js` treats it as a terminal state next to
 *   approved/rejected;
 * * aggregated by SQL that filters on `status = N'تایید شده'` — `app/main.py`
 *   (hourly-pass total, overtime total, leave report).
 *
 * Note the spelling: the application writes `تایید` (without the hamza) while
 * prose in templates sometimes shows `تأیید`.  The **data** uses `تایید`, so that
 * is the string that matters.
 */
final class LegacyRequestStatus
{
    /** Awaiting admin action. */
    public const PENDING = 'انتظار تایید';

    /** Approved — the value every report aggregates on. */
    public const APPROVED = 'تایید شده';

    /** Rejected by an admin. */
    public const REJECTED = 'رد شده';

    /** Withdrawn by the requester. */
    public const CANCELLED = 'انصراف';

    /**
     * Terminal states, i.e. the request no longer needs an admin decision.
     *
     * `admin.js` uses exactly this triple to decide whether to render the
     * approve/reject buttons.
     *
     * @return array<int, string>
     */
    public static function terminal(): array
    {
        return [self::APPROVED, self::REJECTED, self::CANCELLED];
    }

    /** Whether a status means "done, do not show the decision buttons". */
    public static function isTerminal(?string $status): bool
    {
        return in_array(PersianText::strip($status), self::terminal(), true);
    }
}
