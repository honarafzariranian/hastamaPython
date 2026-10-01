<?php

namespace App\Broadcasting;

/**
 * The channels the TV displays subscribe to.
 *
 * The Python kept one WebSocket path (`/api/ws/call-display`) and told the *connections*
 * apart with an in-memory tag: a socket identified itself as `display` (a real TV) or
 * `preview` (the iframe inside the management page), and `DisplayManager` reported three
 * numbers — the total, the real screens, and the previews.
 *
 * Reverb speaks the Pusher protocol and has no notion of a per-connection tag, so the
 * distinction is carried by the **channel name** instead: a real TV joins
 * {@see TV}, the management iframe joins {@see PREVIEW}.  Every broadcast still goes to
 * both, exactly as `DisplayManager.broadcast()` sent to every socket in its list, and
 * the three numbers are read back from the two channels' subscription counts.
 *
 * Both channels are **public** and stay public: a TV has no user session, and the
 * legacy socket was deliberately unauthenticated.  The boundary is the LAN plus the
 * `Origin` allow-list Reverb enforces on the handshake — see `config/reverb.php`.
 */
final class DisplayChannel
{
    /** Real TV screens. */
    public const TV = 'call-display';

    /** The management page's embedded preview. */
    public const PREVIEW = 'call-display.preview';

    /** The name the display client listens for. */
    public const EVENT = 'display.message';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::TV, self::PREVIEW];
    }
}
