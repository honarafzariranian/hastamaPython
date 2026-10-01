<?php

namespace App\Models\Concerns;

use App\Support\Legacy\PersianText;

/**
 * Return legacy string columns without their trailing space padding.
 *
 * The database is the source of truth, and the database pads: `user_table.role`
 * is `nchar(10)`, so the value stored as `admin` is read back as `'admin     '`.
 * The Python application strips at nearly every read site — `str(row[0]).strip()`
 * appears in dozens of places and `core/session.py` compares
 * `str(row[0] or "").strip().lower()` — so trimming on read is the faithful
 * behaviour, not a convenience.
 *
 * Trimming happens in `getAttributeValue()`, which covers `$model->column`,
 * `toArray()`, `only()`, JSON serialisation and `fill()` round-trips.  It does
 * *not* alter what is written: SQL Server pads `nchar` values on storage by
 * itself, exactly as it does for the Python application.
 *
 * A model may opt individual columns out of trimming by listing them in
 * `$rawAttributes` when whitespace is meaningful there.  Nothing currently does.
 */
trait TrimsLegacyStrings
{
    /**
     * Attribute names whose value must be returned exactly as stored.
     *
     * @return array<int, string>
     */
    public function rawAttributeNames(): array
    {
        return property_exists($this, 'rawAttributes') ? $this->rawAttributes : [];
    }

    /**
     * Get a plain attribute value, stripped of legacy padding.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (is_string($value) && $value !== '' && ! in_array($key, $this->rawAttributeNames(), true)) {
            return PersianText::trim($value);
        }

        return $value;
    }
}
