<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 13, slice 6 · 6.1 — `D-136`: whether J-06 submitted a weekly report
 * without its author's review. NOT NULL, false for every report already there.
 * The migration file must end in `_add_not_reviewed_to_reports.php`.
 */
final class WeeklyReportsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** `D-136`: NOT NULL, false for every report already there; `down()` takes it away (DEV-03). */
    public function test_that_not_reviewed_defaults_to_false_and_is_gone_after_down(): void
    {
        self::assertTrue(Schema::hasColumn('reports', 'not_reviewed'), 'D-136: reports has no not_reviewed.');

        $id = $this->report();
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));

        $files = glob(base_path('database/migrations/*_add_not_reviewed_to_reports.php'));
        self::assertIsArray($files);
        self::assertCount(1, $files, 'Expected one database/migrations/*_add_not_reviewed_to_reports.php.');
        $path = 'database/migrations/'.basename($files[0]);

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]));
        self::assertFalse(Schema::hasColumn('reports', 'not_reviewed'), 'DEV-03: down() left not_reviewed behind.');

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
        self::assertTrue(Schema::hasColumn('reports', 'not_reviewed'));

        // Last: a failed write aborts the test's transaction (SQLSTATE 25P02) for anything after it.
        try {
            DB::table('reports')->where('id', $id)->update(['not_reviewed' => null]);
            self::fail('The write succeeded; SQLSTATE 23502 was expected.');
        } catch (QueryException $e) {
            self::assertSame('23502', $e->getCode());
        }
    }

    private function report(): string
    {
        $author = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $author, 'name' => 'Author '.$author, 'email' => $author.'@example.test', 'password' => 'x',
            'role_id' => DB::table('roles')->value('id'),
            'is_active' => true, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-WK-2026-'.substr($id, -4), 'type' => 'weekly', 'status' => 'draft',
            'author_id' => $author, 'period_start' => '2026-10-11', 'period_end' => '2026-10-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
