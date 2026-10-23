<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 16, Point 5 — `backup_runs`, `J-10`'s record (`D-153`, `BK-04`: "each
 * backup carries a checksum + success/failure record").
 *
 * A run is `running`, then `succeeded` or `failed`. A succeeded run names both
 * artefacts with their SHA-256 and size; a failed one says why. The database
 * holds those rules, not only the code that writes them.
 */
final class BackupRunsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_that_backup_runs_has_its_columns(): void
    {
        self::assertTrue(Schema::hasTable('backup_runs'), 'D-153: backup_runs does not exist.');
        foreach (['id', 'status', 'started_at', 'finished_at', 'database_path', 'database_sha256', 'database_bytes', 'files_path', 'files_sha256', 'files_bytes', 'error', 'created_at', 'updated_at'] as $column) {
            self::assertTrue(Schema::hasColumn('backup_runs', $column), "backup_runs has no {$column}.");
        }
    }

    public function test_that_a_complete_succeeded_run_a_failed_run_with_its_reason_and_a_running_one_are_accepted(): void
    {
        $this->run_(['status' => 'running']);
        $this->run_($this->succeeded());
        $this->run_(['status' => 'failed', 'finished_at' => now(), 'error' => 'pg_dump: connection refused']);

        self::assertSame(3, DB::table('backup_runs')->count());
    }

    public function test_that_an_unknown_status_is_refused(): void
    {
        $this->refused(['status' => 'partial']);
    }

    /** BK-04: a success carries both artefacts, each with its checksum and size. */
    public function test_that_a_succeeded_run_missing_an_artefact_or_a_checksum_is_refused(): void
    {
        foreach (['database_path', 'database_sha256', 'database_bytes', 'files_path', 'files_sha256', 'files_bytes', 'finished_at'] as $missing) {
            $this->refused([...$this->succeeded(), $missing => null]);
        }
        $this->refused([...$this->succeeded(), 'database_sha256' => 'not-a-checksum']);
    }

    public function test_that_a_failed_run_without_its_reason_is_refused(): void
    {
        $this->refused(['status' => 'failed', 'finished_at' => now(), 'error' => null]);
        $this->refused(['status' => 'failed', 'finished_at' => now(), 'error' => '  ']);
    }

    /** DEV-03: this point's migration, found by the table it creates, rolled back by its path. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        $found = array_values(array_filter(
            glob(base_path('database/migrations/*.php')) ?: [],
            static fn (string $file): bool => str_contains((string) file_get_contents($file), "Schema::create('backup_runs'"),
        ));
        self::assertCount(1, $found, 'Exactly one migration should create backup_runs.');

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => 'database/migrations/'.basename($found[0])]));
        self::assertFalse(Schema::hasTable('backup_runs'), 'down() left backup_runs behind (DEV-03).');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('backup_runs'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function succeeded(): array
    {
        return [
            'status' => 'succeeded', 'finished_at' => now(),
            'database_path' => '20261022-020000/database.dump', 'database_sha256' => self::SHA, 'database_bytes' => 1024,
            'files_path' => '20261022-020000/files.tar.gz', 'files_sha256' => self::SHA, 'files_bytes' => 2048,
        ];
    }

    /** @param array<string, mixed> $values */
    private function run_(array $values): void
    {
        DB::table('backup_runs')->insert([
            'id' => (string) Str::uuid(), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function refused(array $values): void
    {
        try {
            DB::transaction(fn () => $this->run_($values));
            self::fail('A run breaking BK-04 was stored: '.json_encode($values));
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode(), $e->getMessage());
        }
    }
}
