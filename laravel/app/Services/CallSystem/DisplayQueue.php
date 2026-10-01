<?php

namespace App\Services\CallSystem;

use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\PersianText;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * `dbo.display_queue` — the five slots the TV screens show, and what survives a refresh.
 *
 * This is **persistent state, not a message log**.  The WebSocket broadcast tells a
 * display what just happened; this table is what the display asks for when it boots or
 * reloads, so a screen that was switched off during a call still shows the right numbers
 * when it comes back.  That is why every broadcast path here also writes a row, and why
 * clearing the screens (`/api/calls/reset-display`) has to empty the table *and* tell the
 * screens.
 *
 * The slot arithmetic is the Python's, unreformed: five slots (position 0 is the hero
 * number, 1–4 are the recent history), a new call shifts everything up by one, and the
 * number is removed from wherever it was before being inserted at 0 — so a repeated call
 * moves to the front instead of appearing twice.
 *
 * The shift runs **descending** over the positions.  Ascending would overwrite each slot
 * with the value it had just been given, collapsing the history into five copies of one
 * number.
 */
final class DisplayQueue
{
    /** `MAX_DISPLAY_SLOTS` — position 0 is the hero, 1–4 the history. */
    public const SLOTS = 5;

    /** The connection, injectable so a test can drive it inside its own transaction. */
    public function __construct(private readonly ?Connection $connection = null) {}

    /**
     * Add a number at the front, shifting the older ones up and dropping the oldest.
     *
     * Not a transaction in the Python and not one here: the three statements are the
     * whole operation, and a failure between them leaves the queue shorter rather than
     * corrupt — the next call compacts it by insertion.  Wrapping it would change what
     * happens under a deadlock for no gain a display could observe.
     */
    public function add(string $number, string $department, string $username): void
    {
        $connection = $this->connection();

        for ($position = self::SLOTS - 1; $position > 0; $position--) {
            $connection->update(
                'UPDATE display_queue SET slot_position = ? WHERE slot_position = ?',
                [$position, $position - 1],
            );
        }

        // The entry that was pushed past the last slot is gone, not kept off-screen.
        $connection->delete('DELETE FROM display_queue WHERE slot_position = ?', [self::SLOTS - 1]);

        $connection->insert(
            'INSERT INTO display_queue (reception_number, department, called_by, slot_position) VALUES (?, ?, ?, 0)',
            [$number, $department, $username],
        );
    }

    /**
     * Remove every row for a number, then close the gaps in the positions.
     *
     * `DELETE … WHERE reception_number = ?` deletes **all** matching rows, which is how
     * the Python deduplicated: a number that had drifted into the history and was then
     * removed left no fragment behind for the compaction to trip over.
     */
    public function remove(string $number): void
    {
        $connection = $this->connection();

        $connection->delete('DELETE FROM display_queue WHERE reception_number = ?', [$number]);

        // `ORDER BY slot_position ASC`, then renumber 0..n by `id`.  Ordering by id would
        // be wrong the moment two slots share a position after a partial failure.
        $rows = $connection->select('SELECT id FROM display_queue ORDER BY slot_position ASC');

        foreach ($rows as $index => $row) {
            $connection->update(
                'UPDATE display_queue SET slot_position = ? WHERE id = ?',
                [$index, $row->id],
            );
        }
    }

    /** `/api/calls/reset-display` — the persistent half of clearing the wall. */
    public function clear(): void
    {
        $this->connection()->delete('DELETE FROM display_queue');
    }

    /**
     * The queue as the display reads it: position order, Persian digits, `Z` timestamps.
     *
     * `persian_number` is *added* and `created_at` is *renamed* to `called_at` — the
     * display client reads `called_at`, and renaming it here is the only place that name
     * exists.  Note the rename happens with `pop`, so the field order is
     * `reception_number, department, called_by, slot_position, called_at, persian_number`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->connection()->select(
            'SELECT reception_number, department, called_by, slot_position, created_at
             FROM display_queue ORDER BY slot_position ASC'
        );

        $queue = [];

        foreach ($rows as $row) {
            // Through the serializer rather than `(array) $row`, because `pdo_sqlsrv`
            // returns **every** scalar as a string: without it `slot_position` goes out as
            // `"0"` where the running server sends `0`, and the display sorts its slots by
            // that number.  The declared types are what `LegacySchema` is for.
            $entry = LegacySerializer::row('display_queue', $row);

            // Order matters: the Python appended `persian_number` **before** renaming
            // `created_at`, so the published object is
            // `reception_number, department, called_by, slot_position, persian_number, called_at`.
            // Renaming first would put `called_at` before `persian_number` and change the
            // key order of every response.
            $entry['persian_number'] = PersianText::toPersianDigits((string) $entry['reception_number']);
            $calledAt = LegacySerializer::isoUtcZ($entry['created_at'] ?? null);
            unset($entry['created_at']);
            $entry['called_at'] = $calledAt;

            $queue[] = $entry;
        }

        return $queue;
    }

    /**
     * The connection to run on.
     *
     * `DB::connection()` rather than a concrete name, so a test that repoints the default
     * connection at the live database (as `AuthDatabaseTest` and `LegacyReadDatabaseTest`
     * do) drives this class inside its own transaction and rolls it back.
     */
    private function connection(): Connection
    {
        return $this->connection ?? DB::connection();
    }
}
