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
        // Order is load-bearing, not cosmetic: users.role_id is NOT NULL with a
        // foreign key, so the roles have to exist first. UserSeeder says so
        // itself rather than trusting this line.
        //
        // RolePermissionSeeder is configuration (SEC-07) and runs everywhere.
        // UserSeeder is test data and refuses to run in production — the guard
        // is on the seeder, so calling it from here cannot weaken it.
        $this->call([
            RolePermissionSeeder::class,
            CurrencySeeder::class,
            UserSeeder::class,
        ]);

        // Module 2 still owes: sectors, units, service types and delivery terms (DB-05).
        // No FX rate is seeded — see CurrencySeeder for why the one rate the
        // system can assert on its own is a tautology the schema refuses.
    }
}
