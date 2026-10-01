<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * There is nothing to seed.
 *
 * **This class is intentionally empty, and must stay that way.**  Laravel's
 * scaffold seeder created a fake account through `User::factory()->create()`.
 * During Phase 3 the `App\Models\User` model was remapped to the live
 * `dbo.user_table`, which means that call would have inserted a bogus row —
 * `name`/`email` are not even columns there — into the **production** database the
 * moment anyone ran `php artisan db:seed`.
 *
 * The application's reference data already exists: `user_table` holds the real
 * accounts, `system_config` its 12 settings, `ticket_categories` its 4 categories
 * and `slides` its 4 slides.  Todo lists, "Test User" rows and the like are not part
 * of the system, so nothing here writes anything.
 *
 * If seeded reference data is ever genuinely needed, add it as an explicitly named
 * seeder and document the exact rows it creates — never as a factory default.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Deliberately empty — see the class docblock.
    }
}
