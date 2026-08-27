<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `D-75`'s move: the lockout duration stops being configuration-only and
 * becomes a row an administrator can edit.
 *
 * **Exactly one row, and the emptiness around it is the point.** §13 screen 6
 * names five limits and §13 screen 4 names ten settings; the documentation
 * gives a value to **one** of them. `D-17` says the stale-deal threshold is
 * "configurable in settings" and stops. §11 says deadlines and SLAs "come from
 * settings" and stops. A seeded default for any of those would be a business
 * rule nobody wrote, arriving as configuration and read as fact — the same
 * refusal `ManagedLists` makes for delivery terms and `Currencies` for exchange
 * rates. The tables exist so the real numbers have somewhere to go.
 *
 * **Creates, never overwrites** (`IdempotentSeeder`), and here the reason is
 * sharper than usual: the seeded 30 is explicitly an interim awaiting the
 * owner's answer, so the first thing that will happen to this row is somebody
 * changing it. A deployment that reset it would undo the decision it exists to
 * capture.
 *
 * **Not test data** — `AP-08` puts limits in the database, and production needs
 * a lockout duration on day one.
 */
final class SystemSettingsSeeder extends GuardedSeeder
{
    public function seedsTestData(): bool
    {
        return false;
    }

    protected function seed(): void
    {
        // firstOrCreate through the query builder rather than a model: there is
        // no Eloquent model for system_limits yet and this point does not need
        // one — the read path is DatabaseSettingReader, which uses the
        // connection directly for the same reason.
        $exists = DB::table('system_limits')
            ->where('key', AuthenticateUser::LOCKOUT_MINUTES_KEY)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('system_limits')->insert([
            'id' => Str::uuid7()->toString(),
            'key' => AuthenticateUser::LOCKOUT_MINUTES_KEY,

            // D-75's interim value, and the reason it is a setting rather than
            // a constant: nothing in the documentation gives this number, so
            // the owner must be able to change it without a deployment.
            'value' => '30',
            'value_type' => 'integer',
            'unit' => 'minutes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
