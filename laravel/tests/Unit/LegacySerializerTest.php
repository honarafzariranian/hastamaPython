<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacySerializer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `_serialize()` parity, plus the type coercion the driver change forces.
 *
 * Every expected value here was read off the **running** implementation: the
 * reference output was produced by calling the Python `_serialize()` against the
 * live `userDB` with `pyodbc`, then comparing.  The samples are real rows — the
 * space-padded `'admin     '` role, the padded `'00055858  '` personnel number, the
 * `null` personnel number, the `'2026-09-30T08:53:41.864000'` timestamp shape.
 *
 * The tests pass an explicit type map rather than letting the serializer consult
 * `INFORMATION_SCHEMA`, so they run offline and — more importantly — they document
 * *which* declared type produces *which* JSON type.  That mapping is the whole
 * reason the class exists: `pdo_sqlsrv` returns `"0"` for a `bit` where `pyodbc`
 * returns `False`, and a client that sorts on `is_active` would see the difference.
 */
final class LegacySerializerTest extends TestCase
{
    /**
     * `[declared type, driver value, expected JSON value]`.
     *
     * @return array<string, array{0: string, 1: mixed, 2: mixed}>
     */
    public static function coercionCases(): array
    {
        return [
            // ── datetime2 → isoformat(), six digits, or none at all ──────────
            'datetime2 with milliseconds' => ['datetime2', '2026-09-30 08:53:41.864', '2026-09-30T08:53:41.864000'],
            'datetime2 whole second' => ['datetime2', '2026-09-30 06:00:23', '2026-09-30T06:00:23'],
            'datetime2 zero fraction' => ['datetime2', '2026-09-30 06:00:23.000', '2026-09-30T06:00:23'],
            'datetime2 seven digits' => ['datetime2', '2026-09-30 06:00:23.1234567', '2026-09-30T06:00:23.123456'],
            'datetime' => ['datetime', '2026-09-30 06:00:23.257', '2026-09-30T06:00:23.257000'],

            // ── date / time ─────────────────────────────────────────────────
            'date' => ['date', '2026-09-30', '2026-09-30'],
            'date with stray time' => ['date', '2026-09-30 00:00:00', '2026-09-30'],
            'time' => ['time', '08:00:00', '08:00:00'],
            'time with fraction' => ['time', '08:00:00.123', '08:00:00.123000'],

            // ── bit → bool.  The one case where the JSON *type* changes ─────
            'bit false' => ['bit', '0', false],
            'bit true' => ['bit', '1', true],

            // ── integers ────────────────────────────────────────────────────
            'bigint' => ['bigint', '319', 319],
            'int' => ['int', '1', 1],
            'smallint' => ['smallint', '7', 7],
            'tinyint' => ['tinyint', '3', 3],

            // ── numeric ─────────────────────────────────────────────────────
            'decimal' => ['decimal', '199.00', 199.0],
            'money' => ['money', '1500.5000', 1500.5],
            'float' => ['float', '0.5', 0.5],

            // ── strings are never trimmed ───────────────────────────────────
            'nchar role keeps its padding' => ['nchar', 'admin     ', 'admin     '],
            'nchar personnel number keeps its padding' => ['nchar', '00055858  ', '00055858  '],
            'nvarchar username with ya' => ['nvarchar', 'آی تی', 'آی تی'],
            'varchar shift window' => ['varchar', '12:00 - 07:00', '12:00 - 07:00'],

            // ── binary → lowercase hex, empty → null ───────────────────────
            'varbinary' => ['varbinary', hex2bin('deadbeef'), 'deadbeef'],
            'varbinary empty' => ['varbinary', '', null],
            'image' => ['image', hex2bin('00ff10'), '00ff10'],

            // ── nulls survive every type ────────────────────────────────────
            'null timestamp' => ['datetime2', null, null],
            'null string' => ['nvarchar', null, null],
            'null integer' => ['int', null, null],

            // ── an unmapped column (an expression alias) is untouched ───────
            'unknown alias' => ['', '58', '58'],
        ];
    }

    #[DataProvider('coercionCases')]
    public function test_values_are_coerced_to_the_json_type_pyodbc_produced(
        string $type,
        mixed $value,
        mixed $expected,
    ): void {
        $this->assertSame($expected, LegacySerializer::value($type, $value));
    }

    /**
     * The column-keyed entry point, and the fact that it needs a type map to do
     * anything specific.
     */
    public function test_a_row_is_serialised_by_its_column_types(): void
    {
        $row = [
            'id' => '319',
            'username' => 'admin',
            'role' => 'admin     ',
            'is_active' => '0',
            'login_at' => '2026-09-30 06:00:23.257',
            'terminated_by' => null,
        ];

        $serialised = LegacySerializer::row('user_sessions', $row, [
            'id' => 'bigint',
            'username' => 'nvarchar',
            'role' => 'nchar',
            'is_active' => 'bit',
            'login_at' => 'datetime2',
            'terminated_by' => 'nvarchar',
        ]);

        $this->assertSame([
            'id' => 319,
            'username' => 'admin',
            'role' => 'admin     ',
            'is_active' => false,
            'login_at' => '2026-09-30T06:00:23.257000',
            'terminated_by' => null,
        ], $serialised);

        // Key order is the SELECT's column order and must not be normalised: the
        // UI's table columns are rendered from it.
        $this->assertSame(
            ['id', 'username', 'role', 'is_active', 'login_at', 'terminated_by'],
            array_keys($serialised),
        );
    }

    /** `stdClass` rows (what `DB::select()` returns) serialise the same way. */
    public function test_stdclass_rows_are_accepted(): void
    {
        $row = (object) ['id' => '2', 'is_active' => '1'];

        $this->assertSame(
            ['id' => 2, 'is_active' => true],
            LegacySerializer::row('user_sessions', $row, ['id' => 'int', 'is_active' => 'bit']),
        );
    }

    /** A null row stays null, so a "not found" needs no second query. */
    public function test_a_null_row_serialises_to_null(): void
    {
        $this->assertNull(LegacySerializer::row('user_table', null, []));
    }

    /** A list of rows, all coerced. */
    public function test_rows_serialises_a_list(): void
    {
        $rows = [(object) ['id' => '1'], (object) ['id' => '2']];

        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            LegacySerializer::rows('shiftha', $rows, ['id' => 'int']),
        );
    }

    /**
     * A `DateTimeInterface` (which a different driver or an Eloquent model would
     * supply) produces the same string as the SQL Server text form.
     */
    public function test_datetime_objects_produce_the_same_shape_as_driver_strings(): void
    {
        // `create()` has no microsecond argument, so the fraction is set explicitly.
        $moment = CarbonImmutable::create(2026, 9, 30, 8, 53, 41, 'UTC')->setMicrosecond(864000);

        $this->assertSame(
            '2026-09-30T08:53:41.864000',
            LegacySerializer::value('datetime2', $moment),
        );
    }

    /** A malformed stored value is passed through, not silently blanked. */
    public function test_a_malformed_date_is_returned_unchanged(): void
    {
        $this->assertSame('not-a-date', LegacySerializer::value('date', 'not-a-date'));
        $this->assertSame('not-a-timestamp', LegacySerializer::value('datetime2', 'not-a-timestamp'));
    }
}
