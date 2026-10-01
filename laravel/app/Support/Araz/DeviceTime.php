<?php

namespace App\Support\Araz;

use DateTimeImmutable;

/**
 * The device's current clock — the port of the `DeviceTime` dataclass in
 * `app/services/araz_connector.py`.
 *
 * The device answers `get_current_time` with a `yyyy MM dd HH mm ss` string;
 * this is the parsed form of it.
 */
final class DeviceTime
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $second,
    ) {}

    /** The device clock as a DateTime, for the `isoformat()` the endpoints publish. */
    public function datetime(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $this->year, $this->month, $this->day, $this->hour, $this->minute, $this->second)
        );
    }
}
