<?php

namespace App\Http\Controllers\Araz;

use App\Http\Controllers\Controller;
use App\Support\Araz\ArazDevice;
use App\Support\Araz\DeviceConfig;
use App\Support\Araz\EnterExit;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The device-facing endpoints — `test`, `time`, `time/sync`, `records` and
 * `sync` — ported from `app/api/routes/araz_api.py`.
 *
 * Every one of them talks to the real device over TCP, so the interesting
 * part of the port is the **failure contract**, which is where the Python is
 * inconsistent and the front-end depends on the exact shape:
 *
 * * `/test` is the only device endpoint that *catches* the connection failure
 *   and answers `200` with `connected: false` and the error text — the
 *   connectivity screen renders that, so a 500 would surface as a network
 *   failure instead of "the device is off".
 * * `/time`, `/time/sync`, `/records` and `/sync` let the `ConnectionError`
 *   propagate, exactly as the Python does: an unhandled exception is a
 *   **500**, not a `connected: false` body.  Reproduced, not "fixed".
 * * `/time` answers **502** `Device did not return time` when the device
 *   connects but does not answer with a parsable clock.
 *
 * No endpoint fabricates records: when the device cannot be reached, the
 * connector's own error path answers.
 */
final class ArazDeviceController extends Controller
{
    /**
     * `GET /api/araz/test` — connectivity plus the device clock.
     *
     * The one endpoint that turns a connection failure into a `200` body.
     */
    public function test(Request $request): JsonResponse
    {
        $device = $this->makeDevice();

        try {
            $device->connect();

            $connected = $device->testConnection();
            $deviceTime = $device->getCurrentTime();

            return response()->json([
                'connected' => $connected,
                'ip' => $device->ip,
                'port' => $device->port,
                'device_time' => $deviceTime?->datetime()->format('Y-m-d\TH:i:s'),
                'error' => null,
            ]);
        } catch (ConnectionError $exception) {
            return response()->json([
                'connected' => false,
                'ip' => $device->ip,
                'port' => $device->port,
                'device_time' => null,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Device test failed: '.$exception->getMessage());

            return response()->json([
                'connected' => false,
                'ip' => $device->ip,
                'port' => $device->port,
                'device_time' => null,
                'error' => 'Unexpected error: '.$exception->getMessage(),
            ]);
        } finally {
            $device->disconnect();
        }
    }

    /**
     * `GET /api/araz/time` — the device clock against the server clock.
     *
     * A device that connects but does not answer is a **502**, and a device
     * that cannot be reached at all is an unhandled `ConnectionError` (a
     * 500), exactly as the Python.
     */
    public function time(): JsonResponse
    {
        $device = $this->makeDevice();

        try {
            $device->connect();

            $deviceTime = $device->getCurrentTime();

            if ($deviceTime === null) {
                throw LegacyHttpException::detail(502, 'Device did not return time');
            }

            return response()->json([
                'device_time' => $deviceTime->datetime()->format('Y-m-d\TH:i:s'),
                'server_time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.u'),
            ]);
        } finally {
            $device->disconnect();
        }
    }

    /**
     * `POST /api/araz/time/sync` — set the device clock to the server's now.
     */
    public function timeSync(): JsonResponse
    {
        $device = $this->makeDevice();

        try {
            $device->connect();

            $success = $device->setCurrentTime();

            return response()->json([
                'success' => $success,
                'server_time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.u'),
            ]);
        } finally {
            $device->disconnect();
        }
    }

    /**
     * `GET /api/araz/records` — the device's attendance records, as a bare
     * JSON array (the Python's `response_model=list[…]`).
     *
     * The optional `from_date`/`to_date` are Gregorian `yyyy/MM/dd`; they are
     * rewritten to the device's `yyyy MM dd HH mm ss` filter format.
     */
    public function records(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'from_date' => LegacyQuery::nullableString(),
            'to_date' => LegacyQuery::nullableString(),
        ]);

        $fromTime = $this->deviceWindow($params['from_date'], '00 00 00');
        $toTime = $this->deviceWindow($params['to_date'], '23 59 59');

        $device = $this->makeDevice();

        try {
            $device->connect();

            $records = $device->getRecords($fromTime, $toTime);

            $data = array_map(
                static fn (EnterExit $record): array => [
                    'card_no' => $record->cardNo,
                    'date' => $record->date,
                    'time' => $record->time,
                    'in_out_type' => $record->inOutType,
                    'direction' => $record->isEntry() ? 'ورود' : 'خروج',
                    'datetime_jalali' => $record->datetimeJalali(),
                ],
                $records
            );

            return response()->json($data);
        } finally {
            $device->disconnect();
        }
    }

    /**
     * `POST /api/araz/sync` — push the device's records into `hozoor`.
     *
     * Two behaviours are the Python's and are kept:
     *
     * * the card-number mapping is `user_table.hozoor_num` ↔ the device's
     *   `CardNo`, looked up with `SELECT username FROM users WHERE hozoor_num
     *   = ?` — the `users` table does not exist in this database (the real
     *   table is `user_table`), so the lookup always fails and the endpoint
     *   answers `success: false` with the database error.  Reproduced rather
     *   than silently corrected; see the migration report.
     * * a device with no records in range is a successful no-op, not an
     *   error.
     */
    public function sync(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'from_date' => LegacyQuery::nullableString(),
            'to_date' => LegacyQuery::nullableString(),
        ]);

        $fromDate = $params['from_date'] !== null && $params['from_date'] !== ''
            ? $params['from_date']
            : now()->format('Y/m/d');
        $toDate = $params['to_date'] !== null && $params['to_date'] !== ''
            ? $params['to_date']
            : now()->format('Y/m/d');

        $fromTime = str_replace('/', ' ', $fromDate).' 00 00 00';
        $toTime = str_replace('/', ' ', $toDate).' 23 59 59';

        $device = $this->makeDevice();

        try {
            $device->connect();

            $records = $device->getRecords($fromTime, $toTime);

            if ($records === []) {
                return response()->json([
                    'success' => true,
                    'records_count' => 0,
                    'message' => 'No records to sync from device',
                ]);
            }

            $synced = 0;

            try {
                foreach ($records as $record) {
                    $timeStr = substr($record->time, 0, 2).':'.substr($record->time, 2, 4);

                    $userRow = DB::selectOne(
                        'SELECT username FROM users WHERE hozoor_num = ?',
                        [$record->cardNo]
                    );

                    if (! $userRow) {
                        continue;
                    }

                    $username = $userRow->username;
                    $today = now()->format('Y-m-d');

                    $existing = DB::selectOne(
                        'SELECT id FROM hozoor WHERE username = ? AND date = ?',
                        [$username, $today]
                    );

                    if ($existing) {
                        if ($record->isEntry()) {
                            DB::update('UPDATE hozoor SET vrood = ? WHERE id = ?', [$timeStr, $existing->id]);
                        } else {
                            DB::update('UPDATE hozoor SET khoroj = ? WHERE id = ?', [$timeStr, $existing->id]);
                        }
                    } elseif ($record->isEntry()) {
                        DB::insert('INSERT INTO hozoor (username, date, vrood) VALUES (?, ?, ?)', [$username, $today, $timeStr]);
                    } else {
                        DB::insert('INSERT INTO hozoor (username, date, khoroj) VALUES (?, ?, ?)', [$username, $today, $timeStr]);
                    }

                    $synced++;
                }
            } catch (Throwable $exception) {
                Log::error('Database sync failed: '.$exception->getMessage());

                return response()->json([
                    'success' => false,
                    'records_count' => $synced,
                    'message' => "Database error: {$exception->getMessage()}",
                ]);
            }

            return response()->json([
                'success' => true,
                'records_count' => $synced,
                'message' => "Successfully synced {$synced} records from device",
            ]);
        } finally {
            $device->disconnect();
        }
    }

    /**
     * Build a device connector from the in-memory configuration.
     */
    private function makeDevice(): ArazDevice
    {
        $config = DeviceConfig::all();

        return new ArazDevice(
            ip: (string) $config['ip'],
            port: (int) $config['port'],
            deviceNumber: (int) $config['device_number'],
            timeout: (float) $config['timeout'],
        );
    }

    /**
     * `from_date.replace("/", " ") + " 00 00 00"` — the device's filter format.
     */
    private function deviceWindow(?string $date, string $clock): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return str_replace('/', ' ', $date).' '.$clock;
    }
}
