<?php

namespace Tests\Feature;

use App\Models\Concerns\GuardsDatabaseOwnedTable;
use App\Models\HourlyPass;
use App\Models\LeaveReport;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\OvertimeTotal;
use App\Models\QueueTicket;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * The model layer's behaviour, without touching the database.
 *
 * Everything asserted here is either a rule taken from the running Python
 * application or a property of the live schema recorded during the audit.  None of
 * it needs a connection: an Eloquent model can be populated in memory, which keeps
 * these tests runnable in CI while the production database is in use.
 */
class LegacyModelTest extends TestCase
{
    /** @return array<int, class-string<Model>> */
    private function models(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->isSubclassOf(Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function test_all_forty_three_application_tables_have_a_model(): void
    {
        // 43 of the database's 44 tables are modelled; `sysdiagrams` is SQL Server's
        // own diagram store and has no application meaning.
        $this->assertCount(43, $this->models());
    }

    public function test_every_model_declares_its_table_explicitly(): void
    {
        foreach ($this->models() as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertNotSame(
                [],
                $reflection->getAttributes(Table::class),
                "{$class} must declare #[Table(...)]: implicit pluralisation would point at a table that does not exist",
            );

            $model = new $class;

            /*
             * The declared name must be a plain identifier.
             *
             * It deliberately cannot be compared against the class name: these are
             * legacy tables, so `Attendance` is `hozoor`, `LeaveRequest` is
             * `mrkhc_table`, `LegacyTicket` is `ticket_table` and `Shift` is
             * `shiftha`.  What matters is that the value is explicit (asserted above)
             * and is a name SQL Server will accept unquoted — a name with a space or a
             * bracket in it would be a silent injection surface in
             * `hastama:schema-check`, which interpolates it into `COUNT(*)`.
             */
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $model->getTable(),
                "{$class} declares a suspicious table name",
            );

            $this->assertNotSame('', $model->getKeyName(), "{$class} must declare a key for reads");
        }
    }

    public function test_mass_assignment_stays_closed_except_where_a_model_opens_it(): void
    {
        foreach ($this->models() as $class) {
            $model = new $class;

            // The base `$guarded = ['*']` must survive, so a column has to be
            // whitelisted deliberately rather than being writable by accident.
            $this->assertContains('*', $model->getGuarded(), "{$class} must keep the default guard");

            if (! in_array(GuardsDatabaseOwnedTable::class, class_uses_recursive($class), true)) {
                $this->assertNotSame(
                    [],
                    $model->getFillable(),
                    "{$class} is writable but declares no #[Fillable] columns",
                );
            }
        }
    }

    public function test_an_unfillable_attribute_throws_instead_of_being_dropped(): void
    {
        // This is what `Model::preventSilentlyDiscardingAttributes()` buys: a typo in
        // a controller produces an exception rather than a silent no-op.
        $this->expectException(MassAssignmentException::class);

        (new LeaveRequest)->fill(['start_date' => now(), 'not_a_column' => 'x']);
    }

    // ── User ─────────────────────────────────────────────────────────────────

    private function user(array $attributes): User
    {
        return (new User)->forceFill($attributes);
    }

    public function test_user_role_is_read_without_its_nchar_padding(): void
    {
        // Verbatim what the live database returns for `role nchar(10)`.
        $this->assertSame('admin', $this->user(['role' => 'admin     '])->role());
        $this->assertSame('user', $this->user(['role' => 'user      '])->role());
        $this->assertTrue($this->user(['role' => 'admin     '])->isAdmin());
        $this->assertFalse($this->user(['role' => 'user      '])->isAdmin());
    }

    public function test_user_username_and_card_number_lose_their_padding(): void
    {
        $user = $this->user(['username' => 'paknafs   ', 'hozoor_num' => '1001      ']);

        $this->assertSame('paknafs', $user->username());
        $this->assertSame('1001', $user->hozoorNumber());
    }

    public function test_password_and_hash_are_hidden_from_serialisation(): void
    {
        $array = $this->user([
            'username' => 'admin',
            'password' => 'secret    ',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]),
        ])->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('password_hash', $array);
        $this->assertSame('admin', $array['username']);
    }

    /**
     * The login rule, transcribed from `app/api/routes/auth.py`: everything is
     * allowed except a specific set of blocking values.
     */
    public function test_login_allowed_matches_the_existing_status_rule(): void
    {
        $blocked = ['disabled', 'inactive', 'locked', '0', 'false'];

        foreach ($blocked as $status) {
            $this->assertFalse(
                $this->user(['is_active' => $status])->isLoginAllowed(),
                "'{$status}' must block the login",
            );

            // The column is nvarchar, so padding can appear here too.
            $this->assertFalse($this->user(['is_active' => str_pad($status, 10)])->isLoginAllowed());
            $this->assertFalse($this->user(['is_active' => strtoupper($status)])->isLoginAllowed());
        }

        foreach (['active', 'ACTIVE  ', '', null, 'unexpected-value'] as $status) {
            $this->assertTrue(
                $this->user(['is_active' => $status])->isLoginAllowed(),
                var_export($status, true).' must still permit the login',
            );
        }
    }

    public function test_user_verifies_the_padded_plaintext_of_the_live_accounts(): void
    {
        $user = $this->user([
            'username' => 'admin',
            'password' => str_pad('a1b2c3', 10), // nchar(10), as stored today
        ]);

        $this->assertTrue($user->verifyPassword('a1b2c3'));
        $this->assertFalse($user->verifyPassword('nope'));
        $this->assertTrue($user->needsPasswordUpgrade(), 'a plaintext credential must be upgraded on first login');
    }

    public function test_user_with_a_bcrypt_hash_does_not_need_an_upgrade(): void
    {
        $user = $this->user([
            'username' => 'admin',
            'password' => str_pad('old', 10),
            'password_hash' => password_hash('a1b2c3', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $this->assertTrue($user->verifyPassword('a1b2c3'));
        $this->assertFalse($user->needsPasswordUpgrade());
    }

    public function test_user_implements_the_auth_contract_without_inventing_a_remember_token(): void
    {
        $user = $this->user(['id' => 7, 'username' => 'admin']);

        $this->assertSame('id', $user->getAuthIdentifierName());
        $this->assertSame(7, $user->getAuthIdentifier());
        $this->assertSame('password', $user->getAuthPasswordName());
        $this->assertNull($user->getRememberToken());
        $this->assertNull($user->getRememberTokenName());
    }

    // ── Trigger-owned tables ─────────────────────────────────────────────────

    public function test_trigger_maintained_tables_refuse_every_write(): void
    {
        foreach ([new LeaveReport, new OvertimeTotal] as $model) {
            foreach (['save', 'delete'] as $operation) {
                try {
                    $model->{$operation}();
                    $this->fail($model::class."::{$operation}() must refuse to write");
                } catch (LogicException $exception) {
                    $this->assertStringContainsString($model::ownerTriggerNames()[0], $exception->getMessage());
                }
            }
        }
    }

    public function test_the_hourly_pass_queue_is_writable_and_knows_its_statuses(): void
    {
        /*
         * Deliberate counter-test.  `totalpass_table` *looks* trigger-owned — six
         * triggers feed it — but the application also inserts into it and approves
         * rows through it.  It must stay writable, or the pass queue breaks.
         */
        $this->assertNotContains(GuardsDatabaseOwnedTable::class, class_uses_recursive(HourlyPass::class));
        $this->assertNotSame([], (new HourlyPass)->getFillable());

        $sql = HourlyPass::query()->approved()->toSql();
        $this->assertStringContainsString('status', $sql);
    }

    // ── Queue numbering ──────────────────────────────────────────────────────

    public function test_queue_number_allocation_refuses_to_run_outside_a_transaction(): void
    {
        /*
         * The allocator takes `WITH (UPDLOCK, HOLDLOCK)` on the aggregate.  Laravel's
         * SQL Server grammar compiles `lockForUpdate()` to an empty string, so the
         * hint has to be in the raw statement and the lock only lives as long as the
         * surrounding transaction — hence the refusal rather than a silently racy
         * number.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must run inside a transaction');

        QueueTicket::nextNumber();
    }

    public function test_queue_statuses_and_service_defaults(): void
    {
        $this->assertSame('waiting', QueueTicket::STATUS_WAITING);
        $this->assertSame('called', QueueTicket::STATUS_CALLED);
        $this->assertSame('completed', QueueTicket::STATUS_COMPLETED);
        $this->assertSame('پذیرش', QueueTicket::DEFAULT_SERVICE);
        $this->assertSame('نمونه‌گیری', QueueTicket::SERVICE_SAMPLING);

        // 2000 is an input bound, not a wrap-around point.
        $this->assertSame(2000, QueueTicket::MAX_NUMBER);
    }

    // ── Ticket state machine ─────────────────────────────────────────────────

    public function test_ticket_transitions_match_the_existing_state_machine(): void
    {
        // Taken verbatim from ALLOWED_TRANSITIONS in app/services/ticketing.py.
        $this->assertTrue(Ticket::transitionAllowed('new', 'open'));
        $this->assertTrue(Ticket::transitionAllowed('closed', 'open'));
        $this->assertTrue(Ticket::transitionAllowed('resolved', 'closed'));

        // A closed ticket may only be reopened, never quietly used as active.
        $this->assertFalse(Ticket::transitionAllowed('closed', 'in_progress'));
        $this->assertFalse(Ticket::transitionAllowed('new', 'waiting_for_user'));
        $this->assertFalse(Ticket::transitionAllowed('resolved', 'new'));

        $this->assertSame(['low', 'normal', 'high', 'urgent'], Ticket::PRIORITIES);
        $this->assertSame('حل‌شده', Ticket::STATUS_LABELS['resolved']);
    }

    // ── Vocabularies that must not drift ─────────────────────────────────────

    public function test_the_master_admin_config_whitelist_matches_the_existing_endpoint(): void
    {
        $this->assertSame([
            'captcha_enabled',
            'idle_timeout_enabled',
            'idle_timeout_seconds',
            'label_target_printer',
            'label_print_settings',
        ], SystemConfig::MASTER_ADMIN_WRITABLE_KEYS);
    }

    public function test_notification_vocabularies_match_the_existing_validator(): void
    {
        $this->assertSame(
            ['general', 'announcement', 'system', 'warning', 'information', 'success', 'reminder'],
            Notification::TYPES,
        );
        $this->assertSame(['normal', 'important', 'high', 'critical'], Notification::PRIORITIES);
        $this->assertSame(['all', 'selected', 'role', 'department'], Notification::TARGET_TYPES);
        $this->assertSame(['published', 'archived'], Notification::VISIBLE_STATUSES);
    }

    public function test_only_internal_action_links_are_accepted(): void
    {
        $this->assertTrue((new Notification)->forceFill(['action_url' => '/tickets'])->hasInternalAction());
        $this->assertFalse((new Notification)->forceFill(['action_url' => '//evil.example'])->hasInternalAction());
        $this->assertFalse((new Notification)->forceFill(['action_url' => 'https://evil.example'])->hasInternalAction());
        $this->assertFalse((new Notification)->forceFill(['action_url' => null])->hasInternalAction());
    }
}
