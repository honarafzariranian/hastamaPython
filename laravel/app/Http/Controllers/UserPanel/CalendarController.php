<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\JsonResponse;

/**
 * `GET /get_today_date` — today, as three Jalali integers.
 *
 * The shape is worth noting because it is the one endpoint in the application that
 * answers with a **bare object**: no `success` key, no `data` wrapper.
 *
 *     {"year": 1405, "month": 7, "day": 8}
 *
 * The front-end reads `.year` straight off the response, so adding an envelope would
 * break it.  It is also unauthenticated — the login page's date display uses it.
 *
 * The value comes from `LegacyDate::today()`, which reads the *deployment* wall
 * clock (`hastama.display_timezone`, default `Asia/Tehran`) rather than
 * `app.timezone`.  The Python server used `JalaliDate.today()`, i.e. the machine's
 * local date; using UTC here would answer with yesterday's Jalali date for the three
 * and a half hours around midnight.
 */
final class CalendarController extends Controller
{
    /** `GET /get_today_date` */
    public function today(): JsonResponse
    {
        return response()->json(LegacyDate::today()->toDayTriple());
    }
}
