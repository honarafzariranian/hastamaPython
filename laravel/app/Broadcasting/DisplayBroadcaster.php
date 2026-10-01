<?php

namespace App\Broadcasting;

use App\Events\DisplayMessage;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

/**
 * `DisplayManager` — the display half of the call system, on Reverb.
 *
 * Every handler in the Python that touched a screen did two things: `broadcast(event)`
 * and then reported how many displays got it.  Those two are what this class provides,
 * and the count is a **real** measurement rather than a hopeful constant: Reverb
 * implements the Pusher HTTP API, so the number of live subscribers is read back from
 * the server that is actually holding the sockets.
 *
 * The three numbers the management page displays map directly:
 *
 * | legacy                          | here                                        |
 * |---------------------------------|---------------------------------------------|
 * | `count` (all sockets)           | subscribers of {@see DisplayChannel::TV} **+** `PREVIEW` |
 * | `display_count` (tag `display`) | subscribers of `TV`                          |
 * | `preview_count` (tag `preview`) | subscribers of `PREVIEW`                     |
 *
 * A display that has closed its lid is gone from the count on the next read, because
 * Reverb drops the subscription when the socket closes — the legacy had to notice a dead
 * socket by *failing to write to it*, which only happened on the next broadcast and left
 * `display_manager.count` overstating reality in between.  The port is therefore more
 * accurate than what it replaces; the shape and the meaning are unchanged.
 */
final class DisplayBroadcaster
{
    /**
     * Publish a message to every display, and report how many are listening.
     *
     * @param  array{type: string, data: array<string, mixed>}  $message
     * @return int the number of subscribed displays
     */
    public function send(array $message): int
    {
        broadcast(new DisplayMessage($message));

        // Read *after* publishing, so the number describes the audience of this
        // message rather than the audience a moment earlier.
        return $this->counts()['connected'];
    }

    /**
     * The live subscription counts.
     *
     * @return array{connected: int, real: int, preview: int}
     */
    public function counts(): array
    {
        $real = $this->subscribers(DisplayChannel::TV);
        $preview = $this->subscribers(DisplayChannel::PREVIEW);

        return ['connected' => $real + $preview, 'real' => $real, 'preview' => $preview];
    }

    /**
     * Subscribers on one channel, or `0` when Reverb cannot be asked.
     *
     * `0` is the honest answer to a failed lookup here: the displays connect *through*
     * Reverb, so if Reverb is unreachable there is no connected display to report.  The
     * failure is logged because it also means broadcasts are not being delivered — which
     * is the thing an operator needs to know, and is exactly what `HastamaWatchdog` was
     * written to surface once Phase 17 reinstates supervision.
     */
    private function subscribers(string $channel): int
    {
        try {
            $response = $this->pusher()->get('/channels/'.$channel);

            if (! is_array($response) || ($response['occupied'] ?? false) !== true) {
                // `{"occupied": false}` is what Reverb answers for a channel nobody is
                // on — and it omits `subscription_count` entirely in that case.
                return 0;
            }

            return max(0, (int) ($response['subscription_count'] ?? 0));
        } catch (Throwable $exception) {
            Log::warning('display subscription count unavailable', [
                'channel' => $channel,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * A Pusher-protocol client pointed at Reverb.
     *
     * The credentials are the ones `config/broadcasting.php` already uses, so there is no
     * second place to keep them in step with `REVERB_APP_*` — and the read path cannot
     * drift from the write path and start reporting the audience of a different app.
     */
    private function pusher(): Pusher
    {
        $connection = config('broadcasting.connections.reverb');
        $options = $connection['options'] ?? [];

        return new Pusher(
            (string) $connection['key'],
            (string) $connection['secret'],
            (string) $connection['app_id'],
            [
                'host' => (string) ($options['host'] ?? '127.0.0.1'),
                'port' => (int) ($options['port'] ?? 8080),
                'scheme' => (string) ($options['scheme'] ?? 'http'),
                'useTLS' => (bool) ($options['useTLS'] ?? false),
                'timeout' => 5,
            ],
        );
    }
}
