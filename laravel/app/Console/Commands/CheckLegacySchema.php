<?php

namespace App\Console\Commands;

use App\Models\Concerns\GuardsDatabaseOwnedTable;
use App\Support\Legacy\LegacySchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Throwable;

/**
 * Prove that the Eloquent models match the live `userDB`.
 *
 * Phase 3 of the migration exists to answer one question honestly: *does the model
 * layer describe the real database?*  A wrong primary key, a `#[Fillable]` entry
 * naming a column that does not exist, or a model whose table was never created
 * would all fail at runtime, inside a request, in Persian, in front of a user —
 * which is exactly the failure mode the brief forbids ("never claim that something
 * works without testing it").  So the checks are mechanical and this command is the
 * evidence.
 *
 * Every check is read-only: catalogue queries and `COUNT(*)`.
 *
 *     php artisan hastama:schema-check
 *
 * Exit code 0 means every model matches its table.  Exit code 1 lists the problems.
 * Warnings (a table with no model, for instance) do not fail the run.
 */
final class CheckLegacySchema extends Command
{
    protected $signature = 'hastama:schema-check {--json : Emit machine-readable output}';

    protected $description = 'Verify every Eloquent model against the live SQL Server schema (read-only)';

    public function handle(): int
    {
        try {
            $inspector = new LegacySchemaInspector(DB::connection());
            $database = (string) DB::connection()->getDatabaseName();

            $tables = $inspector->applicationTables();
        } catch (Throwable $exception) {
            $this->components->error('Cannot reach the database: '.$exception->getMessage());

            return self::FAILURE;
        }

        $models = $this->discoverModels();

        $problems = [];
        $warnings = [];
        $rows = [];
        $mappedTables = [];

        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;
            $table = $model->getTable();
            $mappedTables[] = $table;

            $issues = [];
            $notes = [];

            if (! $inspector->tableExists($table)) {
                $issues[] = "table {$table} does not exist";
                $rows[] = [$this->shortName($class), $table, '0', 'MISSING TABLE'];

                continue;
            }

            $columns = $inspector->columnNames($table);
            $primaryKey = $inspector->primaryKeyColumns($table);
            $identity = $inspector->identityColumns($table);
            $triggers = $inspector->triggers($table);

            // 1. The declared key must be a real column.
            if (! in_array($model->getKeyName(), $columns, true)) {
                $issues[] = "key '{$model->getKeyName()}' is not a column";
            }

            // 2. A single-column primary key must be the declared key; a composite
            //    key is a documented limitation, not an error.
            if (count($primaryKey) === 1 && $primaryKey[0] !== $model->getKeyName()) {
                $issues[] = "table key is '{$primaryKey[0]}' but the model declares '{$model->getKeyName()}'";
            }

            if (count($primaryKey) > 1) {
                $notes[] = 'composite key ('.implode('+', $primaryKey).') — Eloquent addresses reads only';
            }

            // 3. Identity vs incrementing must agree, or inserts/reads break.
            if ($model->getIncrementing() && $identity === []) {
                $issues[] = 'model expects an identity key but the table has none';
            }

            if (! $model->getIncrementing() && in_array($model->getKeyName(), $identity, true)) {
                $issues[] = "key '{$model->getKeyName()}' IS an identity column but the model disables incrementing";
            }

            // 4. Every fillable column must exist. This catches typos that would
            //    otherwise silently discard submitted input.
            foreach ($model->getFillable() as $fillable) {
                if (! in_array($fillable, $columns, true)) {
                    $issues[] = "fillable '{$fillable}' is not a column";
                }
            }

            // 5. Managed timestamps must point at real columns.
            if ($model->usesTimestamps()) {
                foreach (['created_at' => $model->getCreatedAtColumn(), 'updated_at' => $model->getUpdatedAtColumn()] as $label => $column) {
                    if ($column !== null && ! in_array($column, $columns, true)) {
                        $issues[] = "{$label} '{$column}' is not a column";
                    }
                }
            }

            /*
             * 6. A write-guarded model must name triggers that actually exist.
             *
             * The trigger is attached to the *source* table (for example
             * `trg_UpdateLeaveReport` lives on `mrkhc_table` and writes
             * `leave_report`), so this looks the names up across the whole database
             * rather than on the guarded table — the first version of this check got
             * that wrong and reported two false failures.
             */
            if ($this->isDatabaseOwned($class)) {
                foreach ($class::ownerTriggerNames() as $trigger) {
                    if (! $inspector->triggerExists($trigger)) {
                        $issues[] = "guarded by missing trigger '{$trigger}'";
                    }
                }

                $notes[] = 'read-only';
            }

            if ($triggers !== []) {
                $notes[] = 'triggers: '.implode(', ', $triggers);
            }

            $count = $inspector->rowCount($table);

            if ($issues === []) {
                $rows[] = [
                    $this->shortName($class),
                    $table,
                    (string) $count,
                    $notes === [] ? 'ok' : implode('; ', $notes),
                ];
            } else {
                $problems[$class] = $issues;
                $rows[] = [$this->shortName($class), $table, (string) $count, 'FAIL'];
            }
        }

        // 7. Completeness: a real table with no model would be invisible to every
        //    later phase, which is the quietest possible way to lose functionality.
        foreach (array_diff($tables, array_unique($mappedTables)) as $unmapped) {
            $warnings[] = "table '{$unmapped}' has no model";
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'database' => $database,
                'models' => count($models),
                'tables' => count($tables),
                'problems' => $problems,
                'warnings' => $warnings,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line("Database: <options=bold>{$database}</>   Models: <options=bold>".count($models).'</>   Tables: <options=bold>'.count($tables).'</>');
        $this->newLine();
        $this->table(['Model', 'Table', 'Rows', 'Notes'], $rows);

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($problems === []) {
            $this->newLine();
            $this->components->info('Every model matches the live schema.');

            return self::SUCCESS;
        }

        foreach ($problems as $class => $issues) {
            $this->components->error($class.' — '.implode('; ', $issues));
        }

        return self::FAILURE;
    }

    /**
     * Every concrete model class in `app/Models`.
     *
     * @return array<int, class-string<Model>>
     */
    private function discoverModels(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    private function isDatabaseOwned(string $class): bool
    {
        return in_array(GuardsDatabaseOwnedTable::class, class_uses_recursive($class), true);
    }

    private function shortName(string $class): string
    {
        return substr($class, strrpos($class, '\\') + 1);
    }
}
