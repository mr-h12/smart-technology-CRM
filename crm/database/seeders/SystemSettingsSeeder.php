<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `D-75`'s move: the lockout duration stops being configuration-only and
 * becomes a row an administrator can edit.
 *
 * **Three rows, and the emptiness around them is the point.** §13 screen 6
 * names five limits and §13 screen 4 names ten settings; the documentation
 * gives a value to **one** of the limits and to none of the settings
 * (`D-105`'s seeded tax rate left with the setting, `D-118`). `D-17` says the stale-deal threshold is
 * "configurable in settings" and stops. §11 says deadlines and SLAs "come from
 * settings" and stops. A seeded default for any of those would be a business
 * rule nobody wrote, arriving as configuration and read as fact — the same
 * refusal `ManagedLists` makes for delivery terms and `Currencies` for exchange
 * rates. The tables exist so the real numbers have somewhere to go.
 *
 * The second row is `OD-08`'s similarity threshold, added 2026-08-30. It is the
 * exception that proves the rule above rather than a hole in it: the value is
 * not inferred from a document that declines to give one, it is the owner's
 * explicit answer to an open question, taken against measured scores. The
 * third, `D-127`'s daily report deadline (17:00), is the same kind: the owner's
 * answer of 2026-10-15, not a guess at §11.5's "deadline from settings".
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
        // connection directly for the same reason. A row that exists is never
        // overwritten: the first thing that happens to one is somebody changing it.
        foreach (self::limits() as $key => [$value, $type, $unit]) {
            if (! DB::table('system_limits')->where('key', $key)->exists()) {
                DB::table('system_limits')->insert([
                    'id' => Str::uuid7()->toString(),
                    'key' => $key,
                    'value' => $value,
                    'value_type' => $type,
                    'unit' => $unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ⚠️ **Unconditional, and after the write** (Point 4.2). `PRF-08`'s
        // cache is invalidated by the two use cases that write through the API;
        // a seeder writes underneath them, so a cache warmed before a
        // deployment would hide `D-75`'s row until somebody happened to save
        // the settings screen — a lockout duration that is configured and not
        // in force. It flushes on the "already exists" path too, because the
        // row existing does not mean the cache knows it does.
        //
        // Not `defer()`: that fires after a response is sent, and a seeder run
        // from `artisan` or from a test has no response to be after. Measured —
        // the test read the configured fallback instead of the seeded row.
        app(SettingsCacheInterface::class)->forgetLimits();
        app(SettingsCacheInterface::class)->forgetSettings();
    }

    /**
     * The limits the documentation or the owner gives a value, and why.
     *
     * - `D-75`'s lockout: an interim 30 minutes, a setting rather than a
     *   constant because nothing in the documentation gives this number.
     * - `OD-08`'s similarity threshold (the owner, 2026-08-30), measured before
     *   it was chosen: `similarity()` over `translate()`-folded names scores the
     *   `D-35` pair at **1.00**, `Alpha Trading` / `Alpha Trading Co` at
     *   **0.82**, two different firms sharing a `شركة`/`مؤسسة` prefix at
     *   **0.50**–**0.57**. ⚠️ No threshold separates the cases cleanly —
     *   `أحمد للتجارة` against `محمد للتجارة` scores **0.62**, above one company
     *   with a trade suffix at **0.58** — and `D-35` only warns, so `0.60`
     *   favours firing over silence. A decimal string (`DB-07`): the comparison
     *   happens in PostgreSQL against `?::real`; a PHP float never touches it.
     * - `D-127`'s daily report deadline: 17:00 company time (the owner,
     *   2026-10-15). A time of day, so no unit.
     *
     * @return array<string, array{string, string, ?string}> key → [value, value_type, unit]
     */
    private static function limits(): array
    {
        return [
            AuthenticateUser::LOCKOUT_MINUTES_KEY => ['30', 'integer', 'minutes'],
            SystemLimit::CustomerSimilarityThreshold->value => ['0.60', 'decimal', null],
        ];
    }
}
