<?php

namespace App\Events;

use App\Broadcasting\DisplayChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One message for every TV display — the port of `DisplayManager.broadcast()`.
 *
 * The Python payload was a plain object that the display read as-is:
 *
 * ```json
 * {"type": "reception_call", "data": {"number": "۱۲۳", "voice": "…"}}
 * ```
 *
 * That object is carried **verbatim** in `$message`, so the receiving client still
 * switches on `type` and reads `data` exactly as the legacy one did; only the envelope
 * around it changed, because the Pusher protocol wraps every event.  The envelope is
 * not something this side can hide — the display client changes from a raw
 * `WebSocket` to an Echo subscription regardless — so the payload is kept identical and
 * the difference is documented rather than smeared across the handlers.
 *
 * `ShouldBroadcastNow` rather than `ShouldBroadcast`: the call must reach the screens
 * *now*.  `ShouldBroadcast` only queues when a queue connection is configured, and
 * `QUEUE_CONNECTION=sync` today would make the distinction invisible — until Phase 12
 * turns on a real queue and every call is suddenly delivered late.  A broadcast must
 * never depend on a worker being alive.
 *
 * Sent to **both** display channels, which is what `broadcast()` did to every socket in
 * its list — the preview iframe saw the same traffic as the wall screens.
 */
final class DisplayMessage implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array{type: string, data: array<string, mixed>}  $message
     *                                                                    The legacy event object,
     *                                                                    unchanged.
     */
    public function __construct(public readonly array $message) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $name): Channel => new Channel($name), DisplayChannel::all());
    }

    public function broadcastAs(): string
    {
        return DisplayChannel::EVENT;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
