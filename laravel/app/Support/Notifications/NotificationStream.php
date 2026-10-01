<?php

namespace App\Support\Notifications;

/**
 * The SSE frame contract of `/api/notifications/stream` and `/api/notifications/admin-stream`.
 *
 * The Python's `notification_stream` generator is the specification, and the front-end's
 * `EventSource` parses its output verbatim:
 *
 * ```
 * id: 10011
 * data: {"id":10011,"title":…,…}
 *
 * : heartbeat
 * ```
 *
 * * an event frame is `id: <notification id>\ndata: <json>\n\n` — the `id` is the
 *   notification id, which is what a reconnecting `EventSource` sends back as
 *   `Last-Event-ID`;
 * * the heartbeat is a **comment** frame (`: heartbeat`), which `EventSource` ignores —
 *   it exists only to keep the connection (and any proxy) alive;
 * * the first cycle emits nothing unless `Last-Event-ID` was sent, in which case it
 *   replays every newer row **oldest first**; later cycles emit the rows that are new
 *   since the previous one, also oldest first;
 * * `seen` is **replaced** each cycle, not unioned — a row that leaves the `TOP 100`
 *   and comes back is re-emitted.
 *
 * The frame builder and the diff are separated from the streaming loop so they can be
 * tested without opening a connection that never closes.
 */
final class NotificationStream
{
    /**
     * One event frame: `id: <notification id>\ndata: <json>\n\n`.
     *
     * The JSON is encoded the way the rest of the legacy surface encodes — unescaped
     * UTF-8 and slashes.  One deliberate byte-level difference from the Python, which
     * used `json.dumps(..., ensure_ascii=False)` and therefore `", "`/`: ` separators:
     * PHP's `json_encode` is compact.  `JSON.parse` reads both, and the front-end does
     * nothing else with the bytes.
     *
     * @param  array<string, mixed>  $item  A serialised notification row.
     */
    public static function frame(array $item): string
    {
        return 'id: '.$item['id']."\n"
            .'data: '.json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }

    /**
     * The heartbeat comment frame.
     */
    public static function heartbeat(): string
    {
        return ": heartbeat\n\n";
    }

    /**
     * The rows to emit for one poll cycle, in emission order.
     *
     * @param  array<int, array<string, mixed>>  $items  This cycle's rows, newest first.
     * @param  array<string, array<string, mixed>>|null  $seen  The previous cycle's rows keyed by id,
     *                                                          or null on the first cycle.
     * @param  int  $lastEventId  The `Last-Event-ID` header value (0 when absent).
     * @return array<int, array<string, mixed>>
     */
    public static function diff(array $items, ?array $seen, int $lastEventId): array
    {
        $current = self::seen($items);
        $emit = [];

        if ($seen === null) {
            // First cycle: a resume replays everything newer than the last id the
            // client saw, oldest first.  Without a header the cycle is silent — the
            // front-end loaded its initial list from `GET /api/notifications`.
            if ($lastEventId > 0) {
                foreach (array_reverse($items, true) as $item) {
                    if ((int) $item['id'] > $lastEventId) {
                        $emit[] = $item;
                    }
                }
            }

            return $emit;
        }

        foreach (array_reverse($current, true) as $key => $item) {
            if (! array_key_exists($key, $seen)) {
                $emit[] = $item;
            }
        }

        return $emit;
    }

    /**
     * The seen-set for the next cycle: this cycle's rows keyed by their string ids.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    public static function seen(array $items): array
    {
        $current = [];

        foreach ($items as $item) {
            $current[(string) $item['id']] = $item;
        }

        return $current;
    }
}
