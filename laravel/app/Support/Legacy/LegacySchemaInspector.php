<?php

namespace App\Support\Legacy;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Read-only introspection of the live `userDB` schema.
 *
 * The migration may not touch existing database objects, so every statement here
 * is a catalogue query (`INFORMATION_SCHEMA`, `sys.*`) or a `COUNT(*)`.  Nothing in
 * this class writes, and it is safe to run against production.
 *
 * The results are what make `php artisan hastama:schema-check` able to prove that
 * each Eloquent model matches the real table — including the two things that are
 * easiest to get wrong in a legacy database and hardest to notice: a model that
 * claims a primary key the table does not have, and a `#[Fillable]` entry that
 * names a column that does not exist (which would silently discard user input).
 */
final class LegacySchemaInspector
{
    /**
     * Tables that are not part of the application and are deliberately unmodelled.
     *
     * `sysdiagrams` is SQL Server's own diagram store, created by SSMS when someone
     * opened the database diagram designer.  It has no application meaning, so
     * there is no model for it, and the completeness check would otherwise report
     * it forever.
     *
     * @var array<int, string>
     */
    public const NON_APPLICATION_TABLES = ['sysdiagrams'];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Every base table in the connected database, excluding the non-application
     * ones above.
     *
     * @return array<int, string>
     */
    public function applicationTables(): array
    {
        $rows = $this->connection->select(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
            ."WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ("
            .implode(', ', array_fill(0, count(self::NON_APPLICATION_TABLES), '?'))
            .') ORDER BY TABLE_NAME',
            self::NON_APPLICATION_TABLES,
        );

        return array_map(static fn ($row): string => (string) $row->TABLE_NAME, $rows);
    }

    public function tableExists(string $table): bool
    {
        $rows = $this->connection->select(
            'SELECT 1 AS present FROM INFORMATION_SCHEMA.TABLES '
            .'WHERE TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\'',
            [$table],
        );

        return $rows !== [];
    }

    /**
     * Column names in ordinal order.
     *
     * @return array<int, string>
     */
    public function columnNames(string $table): array
    {
        $this->assertSafeIdentifier($table);

        $rows = $this->connection->select(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_NAME = ? AND TABLE_SCHEMA = SCHEMA_NAME() '
            .'ORDER BY ORDINAL_POSITION',
            [$table],
        );

        return array_map(static fn ($row): string => (string) $row->COLUMN_NAME, $rows);
    }

    /**
     * Primary key column names, in key order.
     *
     * @return array<int, string>
     */
    public function primaryKeyColumns(string $table): array
    {
        $this->assertSafeIdentifier($table);

        $rows = $this->connection->select(
            'SELECT c.name AS column_name FROM sys.indexes i '
            .'JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id '
            .'JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id '
            .'WHERE i.object_id = OBJECT_ID(?) AND i.is_primary_key = 1 '
            .'ORDER BY ic.key_ordinal',
            ['dbo.'.$table],
        );

        return array_map(static fn ($row): string => (string) $row->column_name, $rows);
    }

    /**
     * Identity column names (there is at most one per table).
     *
     * @return array<int, string>
     */
    public function identityColumns(string $table): array
    {
        $this->assertSafeIdentifier($table);

        $rows = $this->connection->select(
            'SELECT name AS column_name FROM sys.columns '
            .'WHERE object_id = OBJECT_ID(?) AND is_identity = 1',
            ['dbo.'.$table],
        );

        return array_map(static fn ($row): string => (string) $row->column_name, $rows);
    }

    /**
     * Foreign keys declared on this table.
     *
     * The generated inventory lists a global FK set under every table, so this
     * method exists to give the truth for the table that is asked about.
     *
     * @return array<int, array{name: string, column: string, references: string}>
     */
    public function foreignKeys(string $table): array
    {
        $this->assertSafeIdentifier($table);

        $rows = $this->connection->select(
            'SELECT fk.name AS fk_name, cp.name AS parent_column, '
            .'tr.name AS ref_table, cr.name AS ref_column '
            .'FROM sys.foreign_keys fk '
            .'JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id '
            .'JOIN sys.columns cp ON cp.object_id = fkc.parent_object_id AND cp.column_id = fkc.parent_column_id '
            .'JOIN sys.tables tr ON tr.object_id = fk.referenced_object_id '
            .'JOIN sys.columns cr ON cr.object_id = fkc.referenced_object_id AND cr.column_id = fkc.referenced_column_id '
            .'WHERE fk.parent_object_id = OBJECT_ID(?) '
            .'ORDER BY fk.name, fkc.constraint_column_id',
            ['dbo.'.$table],
        );

        return array_map(static fn ($row): array => [
            'name' => (string) $row->fk_name,
            'column' => (string) $row->parent_column,
            'references' => ((string) $row->ref_table).'.'.((string) $row->ref_column),
        ], $rows);
    }

    /**
     * Triggers declared on this table.
     *
     * @return array<int, string>
     */
    public function triggers(string $table): array
    {
        $this->assertSafeIdentifier($table);

        $rows = $this->connection->select(
            'SELECT name FROM sys.triggers WHERE parent_id = OBJECT_ID(?) ORDER BY name',
            ['dbo.'.$table],
        );

        return array_map(static fn ($row): string => (string) $row->name, $rows);
    }

    /**
     * Whether a trigger with this exact name exists anywhere in the database.
     *
     * Deliberately not scoped to one table: `trg_UpdateLeaveReport` lives on
     * `mrkhc_table` but its job is to write `leave_report`, and the model that
     * guards `leave_report` names it.  Scoping the lookup to the guarded table
     * would report a false failure — which is precisely what the first run of
     * `hastama:schema-check` did before this method existed.
     */
    public function triggerExists(string $trigger): bool
    {
        $rows = $this->connection->select(
            'SELECT 1 AS present FROM sys.triggers WHERE name = ?',
            [$trigger],
        );

        return $rows !== [];
    }

    /** Row count. A `COUNT(*)`, not `sys.partitions`, so it is exact. */
    public function rowCount(string $table): int
    {
        $this->assertSafeIdentifier($table);

        $row = $this->connection->selectOne("SELECT COUNT(*) AS total FROM dbo.[{$table}]");

        return (int) ($row->total ?? 0);
    }

    /**
     * Refuse anything that is not a plain identifier before it reaches a statement.
     *
     * Table names come from model `#[Table]` attributes — i.e. from code, not from
     * a request — but a schema tool that interpolates an unchecked string is a bad
     * habit to leave in a codebase, and `rowCount()` has to interpolate a name
     * because SQL Server will not accept a table name as a bound parameter.
     */
    private function assertSafeIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $table) !== 1) {
            throw new InvalidArgumentException("Refusing to introspect: {$table}");
        }
    }
}
