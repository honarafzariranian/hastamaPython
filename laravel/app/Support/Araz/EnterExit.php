<?php

namespace App\Support\Araz;

/**
 * A single attendance punch record from the device — the port of the
 * `EnterExit` dataclass in `app/services/araz_connector.py`.
 *
 * The device reports Jalali dates as `YYMMDD` and times as `HHMM`; both are
 * stored exactly as received.  `datetime_jalali` is the human-readable form
 * the `/records` response publishes, and `is_entry`/`is_exit` the direction
 * the rest of the application branches on.
 */
final class EnterExit
{
    public function __construct(
        public readonly string $cardNo,
        public readonly string $date,
        public readonly string $time,
        public readonly int $inOutType,
        public readonly int $flag = 1,
    ) {}

    /** `InOutType == 0` — the device's enter code. */
    public function isEntry(): bool
    {
        return $this->inOutType === 0;
    }

    /** `InOutType == 1` — the device's exit code. */
    public function isExit(): bool
    {
        return $this->inOutType === 1;
    }

    /**
     * `13{yy}/{mm}/{dd} {hh}:{mi}` — the device's own rendering, with the
     * Jalali century prefixed by hand because the device only sends `YY`.
     */
    public function datetimeJalali(): string
    {
        return '13'.substr($this->date, 0, 2).'/'.substr($this->date, 2, 2).'/'.substr($this->date, 4, 2)
            .' '.substr($this->time, 0, 2).':'.substr($this->time, 2, 2);
    }
}
