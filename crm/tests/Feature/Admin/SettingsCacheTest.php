<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Infrastructure\SettingsCache;
use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Settings\SettingReader;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 4.2 — `PRF-08`, "Caching for catalog · suppliers ·
 * permissions · settings".
 *
 * **Scope is the last of those four.** Catalog and suppliers are modules that
 * do not exist, and permissions belong to Module 1 — `CLAUDE.md`'s module
 * isolation, not a preference. What is cached here is `settings` and
 * `system_limits`.
 *
 * ── Why this point is mostly about invalidation ────────────────────────────
 *
 * `D-75` promises the owner changes the lockout duration **without a
 * deployment**, and `AppServiceProvider` binds every Module 2 repository with
 * `bind` rather than `singleton` for that exact reason — its own comment reads
 * *"an instance memoised for the life of the process is a deployment wearing a
 * different name"*. A cache without invalidation is that same deployment,
 * wearing a third name and lasting longer. So every test below that proves a
 * cache hit is paired with one that proves a write is visible immediately.
 *
 * **The flush happens after the transaction commits, not inside it.** A flush
 * inside the transaction leaves a window in which a concurrent reader
 * repopulates the cache from data that has not committed — and if the
 * transaction then rolls back, the cache holds values that never existed. So
 * `UpdateSettings` and `UpdateSystemLimits` write inside the transaction and
 * flush after it returns.
 *
 * `CACHE_STORE=array` in `phpunit.xml`, so each test gets its own store and a
 * cache hit here is a real one rather than a leak from the previous test.
 */
final class SettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const LOCKOUT = AuthenticateUser::LOCKOUT_MINUTES_KEY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    /** @return array<string, string> */
    private function bearer(): array
    {
        $row = Role::query()->where('slug', RoleName::SuperAdmin->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test Super Admin',
            'email' => 'super.admin@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => RoleName::SuperAdmin->isHidden(),
        ]);
        $user->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }

    /** How many statements touched $table since the log was enabled. */
    private static function queriesAgainst(string $table): int
    {
        $count = 0;

        // Narrowed rather than cast: the log is `mixed` all the way down, and
        // a cast on a missing key would silently count every statement as a
        // miss — which is the direction that makes this test pass wrongly.
        foreach (DB::getRawQueryLog() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sql = $entry['raw_query'] ?? null;

            if (is_string($sql) && str_contains($sql, $table)) {
                $count++;
            }
        }

        return $count;
    }

    // ── the cache exists ────────────────────────────────────────────────────

    public function test_that_a_second_reading_of_the_settings_does_not_query_the_table(): void
    {
        $bearer = $this->bearer();

        $this->getJson('/api/v1/settings', $bearer)->assertStatus(200);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/settings', $bearer)->assertStatus(200);

        self::assertSame(0, self::queriesAgainst('settings'),
            'PRF-08: the second reading is served from the cache.');
    }

    public function test_that_a_second_reading_of_the_limits_does_not_query_the_table(): void
    {
        $bearer = $this->bearer();

        $this->getJson('/api/v1/system-limits', $bearer)->assertStatus(200);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/system-limits', $bearer)->assertStatus(200);

        self::assertSame(0, self::queriesAgainst('system_limits'));
    }

    /**
     * **The reading `D-75` actually made hot.** `AuthenticateUser` asks for the
     * lockout duration on every failed login; before this point that was a
     * query per attempt.
     */
    public function test_that_the_limit_reader_queries_the_table_once_for_repeated_reads(): void
    {
        $reader = $this->app->make(SettingReader::class);

        self::assertSame(30, $reader->integer(self::LOCKOUT));

        DB::flushQueryLog();
        DB::enableQueryLog();

        self::assertSame(30, $reader->integer(self::LOCKOUT));
        self::assertSame(30, $reader->integer(self::LOCKOUT));

        self::assertSame(0, self::queriesAgainst('system_limits'));
    }

    // ── and it is invalidated ───────────────────────────────────────────────

    /** `AP-08`: a settings change takes effect now, not after a deployment. */
    public function test_that_a_written_setting_is_visible_to_the_next_reading(): void
    {
        $bearer = $this->bearer();

        $this->getJson('/api/v1/settings', $bearer)->assertStatus(200);

        $this->patchJson('/api/v1/settings', ['settings' => ['company.name' => 'Smart Technology']], $bearer)
            ->assertStatus(200);

        $settings = $this->getJson('/api/v1/settings', $bearer)->assertStatus(200)->json('data.settings');

        self::assertIsArray($settings);
        self::assertSame('Smart Technology', $settings['company.name']);
    }

    public function test_that_the_write_response_itself_carries_the_new_value(): void
    {
        $bearer = $this->bearer();

        $this->getJson('/api/v1/settings', $bearer)->assertStatus(200);

        $settings = $this->patchJson(
            '/api/v1/settings',
            ['settings' => ['company.name' => 'Smart Technology']],
            $bearer,
        )->assertStatus(200)->json('data.settings');

        self::assertIsArray($settings);
        self::assertSame('Smart Technology', $settings['company.name'],
            'The response is read back after the flush, not from the warm cache.');
    }

    public function test_that_a_written_limit_is_visible_to_the_next_reading(): void
    {
        $bearer = $this->bearer();

        $this->getJson('/api/v1/system-limits', $bearer)->assertStatus(200);

        $this->patchJson('/api/v1/system-limits', ['limits' => [self::LOCKOUT => '45']], $bearer)
            ->assertStatus(200);

        $limits = $this->getJson('/api/v1/system-limits', $bearer)->assertStatus(200)->json('data.limits');

        self::assertIsArray($limits);
        $lockout = $limits[self::LOCKOUT];
        self::assertIsArray($lockout);
        self::assertSame('45', $lockout['value']);
    }

    /**
     * **`D-75` end to end, through the cache.** This is the test that says the
     * cache did not quietly undo the decision: what `AuthenticateUser` reads
     * changes the moment the Super Admin saves, with no deployment and no
     * process restart.
     */
    public function test_that_changing_a_limit_changes_what_the_login_flow_reads(): void
    {
        $bearer = $this->bearer();

        // Warm it first — an unwarmed cache would pass this test without any
        // invalidation existing at all.
        self::assertSame(30, $this->app->make(SettingReader::class)->integer(self::LOCKOUT));

        $this->patchJson('/api/v1/system-limits', ['limits' => [self::LOCKOUT => '45']], $bearer)
            ->assertStatus(200);

        self::assertSame(45, $this->app->make(SettingReader::class)->integer(self::LOCKOUT));
    }

    // ── the cache must not invent answers ───────────────────────────────────

    /**
     * A warm cache holding no row for a key must still fall back to
     * configuration, not to zero. `SettingReader`'s own docblock: *"a lock of
     * zero minutes is an account that never locks"*.
     */
    public function test_that_a_cached_absence_still_falls_back_to_configuration(): void
    {
        config()->set(self::LOCKOUT, 17);

        DB::table('system_limits')->where('key', self::LOCKOUT)->delete();

        $reader = $this->app->make(SettingReader::class);

        self::assertSame(17, $reader->integer(self::LOCKOUT));
        self::assertSame(17, $reader->integer(self::LOCKOUT), 'The second read is cached and still falls back.');
    }

    /**
     * A cache entry of the wrong shape is reloaded whole rather than used in
     * part. Half a settings map is worse than none: the screen would render
     * fields as empty that are not.
     */
    public function test_that_a_cache_entry_of_the_wrong_shape_is_reloaded(): void
    {
        $bearer = $this->bearer();

        $this->patchJson('/api/v1/settings', ['settings' => ['company.name' => 'Smart Technology']], $bearer)
            ->assertStatus(200);

        cache()->forever(SettingsCache::SETTINGS, ['company.name' => ['not', 'a', 'string']]);

        $settings = $this->getJson('/api/v1/settings', $bearer)->assertStatus(200)->json('data.settings');

        self::assertIsArray($settings);
        self::assertSame('Smart Technology', $settings['company.name']);
    }

    /**
     * **The hole this point would otherwise open.** The seeder writes straight
     * to `system_limits`, so a cache warmed before it runs would hide `D-75`'s
     * row until something wrote through the API. In production that is a
     * deployment leaving the lockout invisible.
     */
    public function test_that_seeding_a_limit_invalidates_a_warm_cache(): void
    {
        config()->set(self::LOCKOUT, 17);

        DB::table('system_limits')->where('key', self::LOCKOUT)->delete();

        // Warm the cache on a table that does not hold the row.
        self::assertSame(17, $this->app->make(SettingReader::class)->integer(self::LOCKOUT));

        $this->seed(SystemSettingsSeeder::class);

        self::assertSame(30, $this->app->make(SettingReader::class)->integer(self::LOCKOUT),
            'The seeder writes outside the API and must flush what it invalidated.');
    }
}
