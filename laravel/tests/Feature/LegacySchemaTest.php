<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Support\Legacy\LegacySchemaInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Verify every model against the **real** `userDB`.
 *
 * This is the Phase 3 acceptance test, and it is the only test in the suite that
 * touches the production database.  It is therefore opt-in: unless `HASTAMA_DB_TESTS`
 * is set, it skips, so a normal `php artisan test` run stays offline and cannot
 * disturb the live system.  Everything it does is read-only — a `COUNT(*)` and
 * catalogue queries.
 *
 * Run it deliberately, with the SQL Server connection selected:
 *     *     HASTAMA_DB_TESTS=1 php artisan test --filter=LegacySchemaTest
 *
 * The same checks are available interactively as `php artisan hastama:schema-check`.
 */
class LegacySchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Live-schema verification is opt-in: set HASTAMA_DB_TESTS=1 to run it.');
        }

        /*
         * Point the suite at the real server explicitly instead of relying on the
         * caller's environment.  `phpunit.xml` redirects `DB_DATABASE` for the offline
         * tests, and inheriting that here made SQL Server reject the login with
         * "database :memory:" — an error that looks like a credential problem and is
         * not.  The values below are the deployed ones; nothing here writes.
         */
        config([
            'database.default' => 'sqlsrv',
            'database.connections.sqlsrv.host' => '127.0.0.1',
            'database.connections.sqlsrv.port' => '1433',
            'database.connections.sqlsrv.database' => 'userDB',
            // Empty credentials are how the driver selects Windows authentication.
            'database.connections.sqlsrv.username' => null,
            'database.connections.sqlsrv.password' => null,
        ]);

        DB::purge('sqlsrv');
        DB::setDefaultConnection('sqlsrv');
    }

    public function test_every_model_matches_the_live_schema(): void
    {
        $this->artisan('hastama:schema-check')->assertExitCode(0);
    }

    public function test_the_database_is_the_one_the_migration_was_written_against(): void
    {
        $this->assertSame('userDB', DB::connection()->getDatabaseName());

        // 44 base tables, and `sysdiagrams` is the one that is not application schema.
        $tables = (new LegacySchemaInspector(DB::connection()))->applicationTables();
        $this->assertCount(43, $tables);

        $this->assertSame(
            (int) DB::selectOne('SELECT COUNT(*) AS total FROM dbo.[user_table]')->total,
            DB::table('user_table')->count(),
            'the ORM must see exactly the rows the raw query sees',
        );
    }

    public function test_persian_text_round_trips_through_the_driver(): void
    {
        /*
         * The reason the ODBC driver was rejected.  Windows' ANSI ODBC layer returned
         * `??????` for «مدیریت»; the Unicode-native sqlsrv driver must return the real
         * characters, because the whole interface is Persian.  A regression here is
         * invisible in a health check and catastrophic in the UI, so it is asserted
         * directly on a value that exists in the live data.
         */
        $role = DB::selectOne(
            "SELECT TOP 1 role FROM dbo.[user_table] WHERE LTRIM(RTRIM(role)) = 'admin'",
        );

        $this->assertNotNull($role, 'the live data must still contain an admin row');
        $this->assertSame('admin', trim((string) $role->role));

        $department = DB::selectOne(
            "SELECT TOP 1 department FROM dbo.[user_table] WHERE department IS NOT NULL AND LTRIM(RTRIM(department)) <> ''",
        );

        $value = trim((string) ($department->department ?? ''));

        if ($value !== '') {
            $this->assertTrue(
                mb_check_encoding($value, 'UTF-8') && preg_match('/\p{Arabic}/u', $value) === 1,
                "the driver must return real Persian text, got: {$value}",
            );
        }
    }

    public function test_the_trigger_owned_tables_have_no_workable_write_path_from_eloquent(): void
    {
        /*
         * Documented in docs/migration/DATABASE_TRIGGERS.md, enforced in code by
         * GuardsDatabaseOwnedTable.  Verified against the live server here because the
         * triggers live on the *source* tables: `leave_report` itself has none, which
         * the first version of the schema check got wrong.
         */
        $inspector = new LegacySchemaInspector(DB::connection());

        $this->assertSame([], $inspector->triggers('leave_report'));
        $this->assertTrue($inspector->triggerExists('trg_UpdateLeaveReport'));
        $this->assertTrue($inspector->triggerExists('trg_CalculateDailyOvertime'));
        $this->assertTrue($inspector->triggerExists('trg_UpdateTotalOvertime'));

        // The sources do carry their triggers.
        $this->assertContains('trg_UpdateLeaveReport', $inspector->triggers('mrkhc_table'));
        $this->assertContains('trg_CalculateDailyOvertime', $inspector->triggers('ezafe_table'));
    }

    public function test_the_queue_number_allocator_returns_a_continuous_value(): void
    {
        /*
         * The allocator's SQL is the existing one, and this proves it runs against the
         * real table inside a transaction — where the `WITH (UPDLOCK, HOLDLOCK)` hint
         * is actually held — without writing anything: the transaction is rolled back.
         */
        DB::beginTransaction();

        try {
            $next = QueueTicket::nextNumber();
        } finally {
            // The lock is taken with `WITH (UPDLOCK, HOLDLOCK)`; rolling back releases
            // it without inserting anything.
            DB::rollBack();
        }

        $maximum = (int) DB::selectOne('SELECT ISNULL(MAX(ticket_number), 0) AS m FROM dbo.[queue_tickets]')->m;

        $this->assertSame($maximum + 1, $next);
    }

    public function test_no_model_is_left_unmapped(): void
    {
        $inspector = new LegacySchemaInspector(DB::connection());

        $modelled = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->isSubclassOf(Model::class)) {
                $modelled[] = (new $class)->getTable();
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($inspector->applicationTables(), $modelled)),
            'every application table must have a model, or a later phase cannot even see it',
        );
    }
}
