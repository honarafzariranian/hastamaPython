<?php

namespace App\Support\Araz;

/**
 * The shared secret that authenticates the Araz bridge agent.
 *
 * The Python reads `ARAZ_BRIDGE_SECRET` from the environment once at module
 * load and **fails closed** while it is empty: `bridge-sync` answers 503
 * rather than accepting unauthenticated records.  This class is the single
 * place that read happens, so the fail-closed behaviour cannot drift between
 * the check and the comparison.
 *
 * Read from the environment (not from `config/`) because the deployment
 * procedure keeps it in `.env`, exactly as the Python's `os.environ.get`
 * did.  It is a machine credential: it must never be logged or returned.
 */
final class BridgeSecret
{
    public static function get(): string
    {
        return (string) env('ARAZ_BRIDGE_SECRET', '');
    }
}
