<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Support\Legacy\LegacyQuery;
use App\Support\PrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The master-admin printer discovery surface.
 *
 * Ported from `get_printers` in `app/api/routes/master_admin.py`.
 *
 * The label studio cannot use WebUSB/WebSerial here: the LAN build is served
 * over plain HTTP (no secure context) and the label printer is usually a
 * network queue, which those APIs never expose.  The spooler of the machine
 * running the server is the source of truth.
 */
final class PrinterController extends MasterAdminController
{
    /**
     * `GET /master-admin/api/printers`
     *
     * The `refresh` query parameter forces a fresh enumeration, bypassing the
     * short-lived cache.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'refresh' => LegacyQuery::bool(default: false),
        ]);

        try {
            $data = PrinterService::describePrinters($params['refresh']);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'خواندن فهرست چاپگرهای سرور ناموفق بود.',
            ], 500);
        }

        return $this->ok(['success' => true, 'data' => $data]);
    }
}
