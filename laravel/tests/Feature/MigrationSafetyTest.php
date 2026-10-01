<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * Guards that stop the new application from altering the production database.
 *
 * The database at `E:\Hastama` is live and holds the real data of a working
 * laboratory.  The brief is explicit — "do not destroy existing data", "do not
 * automatically rename tables" — so the migration does not use Laravel's migration
 * runner against it at all: the schema *is* the source of truth and the models were
 * written to match it, not the other way round.
 *
 * These tests make that decision mechanical rather than a matter of discipline.
 */
class MigrationSafetyTest extends TestCase
{
    public function test_there_are_no_pending_migrations_that_could_alter_the_live_schema(): void
    {
        $migrations = glob(database_path('migrations/*.php')) ?: [];

        $this->assertSame(
            [],
            $migrations,
            'database/migrations must stay empty: `php artisan migrate` runs against the live userDB '
            .'and any migration in there would alter or drop real tables. Found: '.implode(', ', $migrations),
        );
    }

    public function test_the_scaffold_migrations_are_kept_where_the_migrator_cannot_see_them(): void
    {
        /*
         * Laravel ships three migrations that create `users`, `cache` and `jobs`.
         * Left in database/migrations they would have run inside `userDB` on the
         * first `artisan migrate` and created tables that do not belong to this
         * application.  They were moved, not deleted, so the decision is visible.
         */
        $disabled = glob(database_path('migrations-disabled/*.php')) ?: [];

        $this->assertCount(3, $disabled);
        $this->assertContains(
            '0001_01_01_000000_create_users_table.php',
            array_map('basename', $disabled),
        );
    }

    public function test_the_seeder_writes_nothing(): void
    {
        /*
         * The scaffold seeder created a fake account through `User::factory()`.
         * `App\Models\User` now points at the live `dbo.user_table`, so that call
         * would have inserted a bogus row — with columns that do not even exist — into
         * production data.
         *
         * The guard is a source reading rather than a behavioural test on purpose:
         * there is no test database to run it against.  The suite is deliberately
         * offline, because every table in `userDB` holds real data and the brief
         * forbids a test run from touching it.  So the check is "the seeder contains no
         * statement that writes", which is exactly the invariant we need.
         */
        $this->artisan('db:seed')->assertExitCode(0);

        $source = $this->codeWithoutComments(database_path('seeders/DatabaseSeeder.php'));

        foreach (['->create(', '->insert(', '->update(', '::query(', '::factory(', 'factory(', 'DB::', 'Model::'] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $source,
                "DatabaseSeeder must not contain '{$write}': it runs against the live database",
            );
        }
    }

    public function test_no_model_factory_exists_for_the_live_tables(): void
    {
        /*
         * A factory is a machine for creating rows.  Every legacy table is live data,
         * so a factory pointing at one of them is a foot-gun: any `Model::factory()`
         * call in a test, a tinker session or a seeder writes to production.  There is
         * deliberately no way to fabricate these rows.
         */
        $factories = glob(database_path('factories/*.php')) ?: [];

        $this->assertSame([], $factories, 'no factory may exist for a live table: '.implode(', ', $factories));
    }

    public function test_the_deployed_configuration_points_at_the_legacy_database(): void
    {
        /*
         * The application must reach SQL Server with Windows authentication, and it
         * must reach the *existing* `userDB` — never a scaffold database.  The `.env`
         * file is checked directly because the test environment deliberately sets a
         * different connection (see TESTING.md); the deployed values are what matter.
         */
        $env = (string) file_get_contents(base_path('.env'));

        $this->assertMatchesRegularExpression('/^DB_CONNECTION=sqlsrv$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=userDB$/m', $env);

        // No SQL login exists in this project: Windows auth means empty credentials.
        $this->assertMatchesRegularExpression('/^DB_USERNAME=$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=$/m', $env);

        // The connection block itself must be the SQL Server driver, and it must not
        // carry a SQL login: this deployment authenticates as the Windows account.
        $this->assertSame('sqlsrv', config('database.connections.sqlsrv.driver'));
        $this->assertEmpty(config('database.connections.sqlsrv.username'));
        $this->assertEmpty(config('database.connections.sqlsrv.password'));

        // The connection's database name is intentionally NOT asserted at runtime:
        // phpunit.xml redirects DB_DATABASE for the whole suite, so the value there is
        // the test placeholder rather than the deployed `userDB`.  The `.env`
        // assertions above are the deployed truth.
    }

    /**
     * Source text with comments removed.
     *
     * Needed because the obvious way to test "this file contains no write" is a
     * substring search — and the docblock on {@see DatabaseSeeder}
     * quotes the very call it removed.  Comments are not code.
     */
    private function codeWithoutComments(string $path): string
    {
        $stripped = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }
}
