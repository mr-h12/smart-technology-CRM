<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Support\Settings\SettingReader;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 2.3 — `D-75`'s move, and the two tables that receive it.
 *
 * **One row is seeded, and that is the honest number.** `D-75` gives
 * `lockout_minutes` a value — 30 minutes, interim, flagged for the owner — and
 * §13 screen 6's other four limits have names and no values: `D-17` says the
 * stale threshold is *"configurable in settings"* without saying what it is,
 * and §11 says deadlines and SLAs *"come from settings"* the same way. §13
 * screen 4's ten settings fields are the same case. Seeding a plausible number
 * would be a business rule nobody wrote, arriving as configuration and read as
 * fact — the rule `ManagedLists` applies to delivery terms and `Currencies` to
 * exchange rates.
 *
 * **The table overrides configuration; it does not replace it.** With no row,
 * the reader answers from `config/identity.php`, so behaviour before Module 2
 * is unchanged and every Module 1 test still describes what it did. With a row,
 * the row wins — which is what `D-75` means by *"an administrator changes it
 * without a deployment"*, and what the last test here proves end to end.
 */
final class SystemSettingsSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = AuthenticateUser::LOCKOUT_MINUTES_KEY;

    public function test_that_the_lockout_duration_d_75_names_is_seeded_as_a_limit(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        $row = DB::table('system_limits')->where('key', self::KEY)->first();

        self::assertNotNull($row, 'D-75 moves the lockout duration into system_limits.');
        self::assertSame('30', $row->value);
        self::assertSame('integer', $row->value_type);
        self::assertSame('minutes', $row->unit);
    }

    /**
     * `OD-08`'s threshold, seeded on the owner's ruling of 2026-08-30.
     *
     * Left deliberately unseeded when the case was declared, because §13 gives
     * it no value and `SystemLimit`'s own docblock refuses to invent one. The
     * owner supplied it after the gap was **measured** rather than guessed: a
     * folded Arabic duplicate scores `1.00`, `Alpha Trading` against
     * `Alpha Trading Co` scores `0.82`, and two plainly different firms sharing
     * a `شركة`/`مؤسسة` prefix score `0.50`–`0.57`.
     *
     * ⚠️ **0.60 does not separate the cases cleanly, and nothing can.**
     * `أحمد للتجارة` against `محمد للتجارة` — different companies — scores
     * `0.62`, *above* `شركة النور` against `شركة النور للتجارة`, which is one
     * company with a trade suffix at `0.58`. The overlap is a property of
     * trigram similarity, not of the number. `D-35` is warning-only, so the
     * chosen point favours firing over silence.
     */
    public function test_that_od_08s_similarity_threshold_is_seeded(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        $row = DB::table('system_limits')
            ->where('key', SystemLimit::CustomerSimilarityThreshold->value)
            ->first();

        self::assertNotNull($row, 'D-35 cannot warn while the threshold is unseeded.');
        // A decimal string, never a float (`DB-07`).
        self::assertSame('0.60', $row->value);
        self::assertSame('decimal', $row->value_type);
        self::assertNull($row->unit, 'A trigram ratio has no unit.');
    }

    /**
     * Every other limit §13 screen 6 names still has no documented value.
     *
     * ⚠️ **This list grew from one key to two, and that is a widening — so the
     * reason is written here rather than the array being quietly edited.** The
     * guard exists to stop the seeder inventing numbers the documentation does
     * not give. The threshold is not invented: `OD-08` is an open question the
     * owner answered explicitly on 2026-08-30, against measured scores. Any
     * *third* key must clear the same bar.
     */
    public function test_that_no_undocumented_limit_is_invented(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        self::assertSame(
            [self::KEY, SystemLimit::CustomerSimilarityThreshold->value],
            DB::table('system_limits')->orderBy('key')->pluck('key')->all(),
        );
    }

    /** §13 screen 4 names ten fields and the documentation gives none of them a value. */
    public function test_that_settings_is_seeded_empty_and_that_is_deliberate(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        self::assertSame(0, DB::table('settings')->count());
    }

    public function test_that_a_second_run_changes_nothing(): void
    {
        $this->seed(SystemSettingsSeeder::class);
        $first = DB::table('system_limits')->orderBy('key')->get(['id', 'created_at'])->toArray();

        $this->seed(SystemSettingsSeeder::class);

        self::assertEquals($first, DB::table('system_limits')->orderBy('key')->get(['id', 'created_at'])->toArray());
    }

    /** The value is the owner's to change; a deployment may not change it back. */
    public function test_that_a_second_run_keeps_an_administrators_edit(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        DB::table('system_limits')->where('key', self::KEY)->update(['value' => '45']);

        $this->seed(SystemSettingsSeeder::class);

        self::assertSame('45', DB::scalar('select value from system_limits where key = ?', [self::KEY]));
    }

    public function test_that_the_seeder_is_not_test_data(): void
    {
        self::assertFalse(app(SystemSettingsSeeder::class)->seedsTestData());
    }

    // ───────────────────────────────────────────────────────────── the reader

    public function test_that_the_reader_returns_the_stored_limit(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        DB::table('system_limits')->where('key', self::KEY)->update(['value' => '45']);

        self::assertSame(45, app(SettingReader::class)->integer(self::KEY));
    }

    /** No row is not an error: `config/identity.php` is still the documented default. */
    public function test_that_the_reader_falls_back_to_configuration_when_no_row_exists(): void
    {
        Config::set(self::KEY, 30);

        self::assertSame(0, DB::table('system_limits')->count());
        self::assertSame(30, app(SettingReader::class)->integer(self::KEY));
    }

    /** An archived limit is not a limit — the configured default returns. */
    public function test_that_an_archived_limit_falls_back_to_configuration(): void
    {
        $this->seed(SystemSettingsSeeder::class);
        Config::set(self::KEY, 30);

        DB::table('system_limits')->where('key', self::KEY)->update(['value' => '45', 'deleted_at' => now()]);

        self::assertSame(30, app(SettingReader::class)->integer(self::KEY));
    }

    /** A row somebody typed a word into must not silently become zero. */
    public function test_that_a_non_numeric_stored_value_falls_back_to_configuration(): void
    {
        $this->seed(SystemSettingsSeeder::class);
        Config::set(self::KEY, 30);

        DB::table('system_limits')->where('key', self::KEY)->update(['value' => 'half an hour']);

        self::assertSame(30, app(SettingReader::class)->integer(self::KEY));
    }
}
