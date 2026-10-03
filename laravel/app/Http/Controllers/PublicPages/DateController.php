<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/date` — today's Jalali date, ported from `get_date` in
 * `app/main.py`.
 *
 * The Python read `jdatetime.datetime.now()`, which is the machine's wall
 * clock (Asia/Tehran on this deployment); {@see LegacyDate} resolves "today"
 * against `hastama.display_timezone` for the same reason — the database stores
 * UTC, and between 20:30 and 24:00 UTC the two disagree by a day.
 *
 * The body is the bare triple — no `success` key, no envelope — because that
 * is what the login page reads before anyone has a session.
 */
final class DateController extends Controller
{
    public function today(): JsonResponse
    {
        return response()->json(LegacyDate::today()->toDayTriple());
    }
}
