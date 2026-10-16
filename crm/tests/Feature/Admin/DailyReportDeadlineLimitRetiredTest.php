<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 13 · 5.1 — `D-135`: `limits.daily_report_deadline` is removed.
 * `DB-01`: the stored row is archived, never deleted, and `down()` brings it back.
 */
final class DailyReportDeadlineLimitRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_19_000100_retire_the_daily_report_deadline_limit.php';

    private const KEY = 'limits.daily_report_deadline';

    public function test_that_the_stored_row_is_archived_and_restored_on_rollback(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));

        DB::table('system_limits')->insert([
            'id' => Str::uuid7()->toString(),
            'key' => self::KEY,
            'value' => '17:00',
            'value_type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertSame(0, DB::table('system_limits')->where('key', self::KEY)->whereNull('deleted_at')->count(), 'D-135: the limit is still live.');
        self::assertSame(1, DB::table('system_limits')->where('key', self::KEY)->count(), 'DB-01: the row was deleted, not archived.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertSame('17:00', DB::table('system_limits')->where('key', self::KEY)->whereNull('deleted_at')->value('value'), 'DEV-03: down() did not restore the row.');
    }
}
