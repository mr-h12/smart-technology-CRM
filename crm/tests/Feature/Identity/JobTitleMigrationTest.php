<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `D-107` ruling (2) (F-40 · 1.3): the signer's job title is a new optional
 * field on the user, in Arabic and English — the field `D-89` found "not
 * stored anywhere".
 */
final class JobTitleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_10_000000_add_job_title_to_users.php';

    /** Optional: nullable, so every existing account stays valid. */
    public function test_that_users_gains_two_nullable_job_title_columns(): void
    {
        foreach (['job_title_en', 'job_title_ar'] as $name) {
            $column = (array) DB::selectOne(
                "select data_type, is_nullable, character_maximum_length from information_schema.columns
                 where table_name = 'users' and column_name = ?",
                [$name],
            );

            self::assertSame(
                ['data_type' => 'character varying', 'is_nullable' => 'YES', 'character_maximum_length' => 255],
                $column,
                $name,
            );
        }
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        self::assertFalse(Schema::hasColumn('users', 'job_title_en'), 'down() left job_title_en behind (DEV-03).');
        self::assertFalse(Schema::hasColumn('users', 'job_title_ar'), 'down() left job_title_ar behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));

        self::assertTrue(Schema::hasColumn('users', 'job_title_en'), 'job_title_en did not come back.');
        self::assertTrue(Schema::hasColumn('users', 'job_title_ar'), 'job_title_ar did not come back.');
    }
}
