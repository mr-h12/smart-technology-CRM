<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeding\GuardedSeeder;

/**
 * The entry point `php artisan db:seed` runs, and — today — the whole of it.
 *
 * It calls nothing, because there is nothing yet to call. Every table `DEV-08`
 * names belongs to a later module: `roles`, `permissions`, `role_permissions`
 * and `users` to Module 1, and `enum_lists`, `currencies` and `fx_rates` to
 * Module 2 (`design/DATABASE.md` §4, §5). Module 0's share of Step 7 is the
 * mechanism those seeders will extend — the contract, the production guard and
 * the idempotency check — not rows.
 *
 * What used to be here was Laravel's scaffold: `User::factory()->create()` with
 * `test@example.com`. It seeded a `users` table that `design/DATABASE.md` §4
 * says is *replaced* in Module 1 rather than extended — bigint key against
 * `D-61`, no `deleted_at` against `DB-01`, no `created_by` against `DB-02` —
 * and it did so unconditionally, in any environment.
 *
 * Each seeder added below guards itself: `GuardedSeeder::run()` refuses test
 * data in production whether it was reached through here or through
 * `db:seed --class=`.
 */
final class DatabaseSeeder extends GuardedSeeder
{
    public function seedsTestData(): bool
    {
        return false;
    }

    protected function seed(): void
    {
        // Module 1: roles, permissions, the resource.action.scope matrix (SEC-07), test users.
        // Module 2: sectors, units, service types, delivery terms (DB-05), currencies (D-52), FX rates.
    }
}
