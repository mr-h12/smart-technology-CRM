<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Module 13, slice 2 — the receiving facts, kept per version on the version
 * row (the owner, 2026-10-16): who viewed, acknowledged and returned it, when,
 * and why it was returned (§11.4, `D-127`). All nullable: a version starts with none.
 */
final class ReportReceivingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'viewed_by', 'viewed_at', 'acknowledged_by', 'acknowledged_at', 'returned_by', 'returned_at', 'return_reason',
    ];

    public function test_that_report_versions_has_the_receiving_columns_all_nullable(): void
    {
        foreach (self::COLUMNS as $column) {
            self::assertTrue(Schema::hasColumn('report_versions', $column), "report_versions has no {$column}.");
            self::assertTrue($this->nullable('report_versions', $column), "{$column} must be nullable: a version starts unseen.");
        }
    }

    public function test_that_each_actor_column_points_at_users(): void
    {
        foreach (['viewed_by', 'acknowledged_by', 'returned_by'] as $column) {
            self::assertSame('users', $this->foreignTable('report_versions', $column), "{$column} has no key to users.");
        }
    }

    /**
     * DEV-03. The newest migration is this slice's, so one step back is it —
     * inside RefreshDatabase's transaction, never on the dev database.
     */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        foreach (self::COLUMNS as $column) {
            self::assertTrue(Schema::hasColumn('report_versions', $column), "report_versions has no {$column} to roll back.");
        }

        self::assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1]));
        self::assertTrue(Schema::hasTable('report_versions'), 'down() must not drop slice 1\'s table.');
        foreach (self::COLUMNS as $column) {
            self::assertFalse(Schema::hasColumn('report_versions', $column), "down() left {$column} behind (DEV-03).");
        }

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasColumn('report_versions', 'return_reason'), 'The columns did not come back.');
    }

    private function nullable(string $table, string $column): bool
    {
        return DB::selectOne(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        )?->is_nullable === 'YES';
    }

    private function foreignTable(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "select ccu.table_name from information_schema.table_constraints tc
             join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name
             join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
             where tc.table_name = ? and tc.constraint_type = 'FOREIGN KEY' and kcu.column_name = ?",
            [$table, $column],
        );

        return is_object($row) && is_string($row->table_name ?? null) ? $row->table_name : null;
    }
}
