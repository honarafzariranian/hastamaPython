<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * The client address used for throttling and for the audit trail.
 *
 * `app/core/net.py::client_ip` exists because the original code trusted the first
 * entry of `X-Forwarded-For`, which the client supplies — making every IP-based
 * control bypassable by adding a header.  The rule that replaced it is reproduced
 * here: **a forwarded value is only honoured when it parses as an address.**
 *
 * Laravel's own behaviour is stricter still, and is used first: `Request::ip()`
 * returns the socket address unless a trusted proxy is configured, so a forged
 * header cannot influence anything until the deployment phase deliberately trusts
 * the Cloudflare Tunnel hop.  Phase 17 configures that trust; this helper makes the
 * current behaviour explicit instead of implicit.
 */
final class ClientAddress
{
    /** The address to log and to throttle on. */
    public static function for(Request $request): string
    {
        $address = $request->ip();

        if (is_string($address) && self::isValid($address)) {
            return $address;
        }

        // Fall back to the socket address; never to an unvalidated header.
        return (string) ($request->server('REMOTE_ADDR') ?: 'unknown');
    }

    /** Whether a candidate is a syntactically valid IPv4/IPv6 address. */
    public static function isValid(string $candidate): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return false;
        }

        // Strip a port suffix such as "10.0.0.5:44321" (IPv6 uses brackets).
        if (preg_match('/^\[(?<v6>[0-9a-fA-F:]+)\](?::\d+)?$/', $candidate, $matches) === 1) {
            $candidate = $matches['v6'];
        } elseif (substr_count($candidate, ':') === 1 && preg_match('/^[0-9.]+:\d+$/', $candidate) === 1) {
            $candidate = strstr($candidate, ':', true);
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The user agent, truncated to the column width (`nvarchar(500)`).
     *
     * A header can be arbitrarily long; the column cannot, and truncating here keeps
     * a 4 KB User-Agent from turning an audit insert into a silent failure.
     */
    public static function userAgent(Request $request, int $max = 500): string
    {
        return mb_substr((string) $request->userAgent(), 0, $max);
    }
}
