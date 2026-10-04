<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Overtime reads for the signed-in user.
 *
 * The admin endpoint (`GET /get_overtime_requests`) returns every request; this
 * one returns only the caller's own rows, which is what the user dashboard
 * needs.
 */
final class OvertimeController extends Controller
{
    /**
     * `GET /get_user_overtime_requests` — the signed-in user's own overtime requests.
     */
    public function userRequests(Request $request): JsonResponse
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '') {
            return response()->json(['detail' => 'نام کاربری پیدا نشد'], 400);
        }

        try {
            $rows = DB::connection()->select(
                'SELECT id, overtime_date, daily_overtime, description, username, status, from_time, to_time FROM ezafe_table WHERE username = ?',
                [$username]
            );

            $requests = [];

            foreach ($rows as $row) {
                $requests[] = [
                    'id' => (int) $row->id,
                    'overtime_date' => $this->toJalali($row->overtime_date, 'تاریخ ناموجود'),
                    'daily_overtime' => $row->daily_overtime,
                    'description' => $row->description,
                    'username' => $row->username,
                    'status' => $row->status === null || $row->status === '' ? 'انتظار تایید' : $row->status,
                    'from_time' => $row->from_time,
                    'to_time' => $row->to_time,
                ];
            }

            return response()->json($requests);
        } catch (Throwable $exception) {
            Log::error('get_user_overtime_requests failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'خطا در دریافت داده‌ها',
                'message' => 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.',
            ]);
        }
    }

    private function toJalali(mixed $value, string $fallback, string $pattern = '%Y/%m/%d'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return LegacyDate::fromGregorian(
                $value instanceof \DateTimeInterface ? $value : (string) $value
            )->format($pattern);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
