<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-48 · 2 — `D-118`: "the system setting `defaults.tax_percent` is removed".
 * `DB-01`: the stored row is archived, never deleted, and `down()` brings it back.
 */
final class DefaultTaxSettingRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_14_000200_retire_the_default_tax_setting.php';

    public function test_that_the_stored_row_is_archived_and_restored_on_rollback(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));

        DB::table('settings')->insert([
            'id' => Str::uuid7()->toString(),
            'key' => 'defaults.tax_percent',
            'value' => '15',
            'value_type' => 'decimal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertSame(0, DB::table('settings')->where('key', 'defaults.tax_percent')->whereNull('deleted_at')->count(), 'D-118: the setting is still live.');
        self::assertSame(1, DB::table('settings')->where('key', 'defaults.tax_percent')->count(), 'DB-01: the row was deleted, not archived.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        self::assertSame('15', DB::table('settings')->where('key', 'defaults.tax_percent')->whereNull('deleted_at')->value('value'), 'DEV-03: down() did not restore the row.');
    }
}
