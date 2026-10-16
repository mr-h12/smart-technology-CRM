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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 13, slice 4 · 4.1 red — `report_pdf_generations` (`D-132`, spec §4.2, §5):
 * one row per submitted version of a report, `queued` until the job finishes it
 * `completed` with its file or `failed` with a reason. The shape is
 * `pdf_generations`' (`PdfGenerationsTableTest`), keyed on `(report_id, version)`.
 *
 * Every rule is the database's, so each is proven by an insert it refuses, by
 * SQLSTATE: `23514` for a CHECK, `23503` for a key, `23505` for the unique key.
 */
final class ReportPdfMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_that_the_table_has_its_columns_and_audit_columns(): void
    {
        foreach ([
            'id', 'report_id', 'version', 'status', 'file_id', 'attempts', 'failure_reason', 'finished_at',
            'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_at',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('report_pdf_generations', $column), "report_pdf_generations has no {$column}.");
        }
    }

    public function test_that_a_generation_starts_queued_with_no_file_no_attempt_and_no_end(): void
    {
        $id = $this->insertGeneration();

        $row = DB::table('report_pdf_generations')->where('id', $id)->first();

        self::assertNotNull($row);
        self::assertSame('queued', $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->file_id);
        self::assertNull($row->failure_reason);
        self::assertNull($row->finished_at);
    }

    public function test_that_the_keys_point_at_reports_files_and_users(): void
    {
        self::assertSame('reports', $this->foreignTable('report_pdf_generations', 'report_id'));
        self::assertSame('files', $this->foreignTable('report_pdf_generations', 'file_id'));
        self::assertSame('users', $this->foreignTable('report_pdf_generations', 'created_by'));
        self::assertSame('users', $this->foreignTable('report_pdf_generations', 'updated_by'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function orphanKeys(): iterable
    {
        yield 'a report that does not exist' => ['report_id'];
        yield 'a file that does not exist' => ['file_id'];
    }

    #[DataProvider('orphanKeys')]
    public function test_that_the_key_refuses(string $column): void
    {
        $this->expectDatabaseError('23503', fn () => $this->insertGeneration([$column => (string) Str::uuid7()]), $column);
    }

    public function test_that_a_version_has_one_generation(): void
    {
        $report = $this->report();
        $this->insertGeneration(['report_id' => $report, 'version' => 1]);

        // v2 of the same report is a different row; v1 twice is refused.
        $this->insertGeneration(['report_id' => $report, 'version' => 2]);
        $this->expectDatabaseError('23505', fn () => $this->insertGeneration(['report_id' => $report, 'version' => 1]), 'report_pdf_generations');
    }

    public function test_that_file_id_is_indexed(): void
    {
        $definitions = DB::table('pg_indexes')->where('tablename', 'report_pdf_generations')->pluck('indexdef')->all();

        self::assertNotSame([], $definitions, 'report_pdf_generations has no indexes.');
        self::assertTrue(
            collect($definitions)->contains(fn (mixed $definition): bool => is_string($definition) && str_contains($definition, '(file_id)')),
            'No index covers file_id (§4.2).',
        );
    }

    public function test_that_a_completed_generation_with_its_file_and_end_is_accepted(): void
    {
        $id = $this->insertGeneration(['status' => 'completed', 'file_id' => $this->file(), 'finished_at' => now()]);

        self::assertSame('completed', DB::table('report_pdf_generations')->where('id', $id)->value('status'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedRows(): iterable
    {
        // Each row breaks exactly one rule, or Postgres may name another one.
        yield 'a completed generation without its file' => [['status' => 'completed', 'finished_at' => '2026-10-16 10:00:00+00'], 'report_pdf_generations_completed_has_file'];
        yield 'a failed generation without its reason' => [['status' => 'failed', 'finished_at' => '2026-10-16 10:00:00+00'], 'report_pdf_generations_failed_has_reason'];
        yield 'a finished generation with no end time' => [['status' => 'failed', 'failure_reason' => 'renderer timed out'], 'report_pdf_generations_finished_iff_final'];
        yield 'a queued generation with an end time' => [['finished_at' => '2026-10-16 10:00:00+00'], 'report_pdf_generations_finished_iff_final'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('refusedRows')]
    public function test_that_the_database_refuses(array $overrides, string $constraint): void
    {
        $this->expectDatabaseError('23514', fn () => $this->insertGeneration($overrides), $constraint);
    }

    /** `DEV-03`, inside `RefreshDatabase`'s transaction and never on the dev database. */
    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        $files = glob(base_path('database/migrations/*_create_report_pdf_generations.php'));
        self::assertIsArray($files);
        self::assertCount(1, $files, 'There is no create_report_pdf_generations migration.');
        $path = 'database/migrations/'.basename($files[0]);

        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path]));
        self::assertFalse(Schema::hasTable('report_pdf_generations'), 'down() left the table behind (DEV-03).');
        self::assertTrue(Schema::hasTable('reports'), 'down() dropped a table it does not own.');
        self::assertTrue(Schema::hasTable('files'), 'down() dropped a table it does not own.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('report_pdf_generations'), 'The table did not come back.');
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

    /** A missing table (42P01) must not pass for the rule being tested. */
    private function expectDatabaseError(string $sqlState, callable $write, string $mentions): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            self::assertSame($sqlState, $e->getCode(), 'The insert failed, but not on the expected rule: '.$e->getMessage());
            self::assertStringContainsString($mentions, $e->getMessage());

            return;
        }

        self::fail("The write succeeded; SQLSTATE {$sqlState} ({$mentions}) was expected.");
    }

    /** @param array<string, mixed> $overrides */
    private function insertGeneration(array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('report_pdf_generations')->insert($overrides + [
            'id' => $id,
            'report_id' => $this->report(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
            'id' => $id, 'code' => 'RPT-DY-2026-'.substr($id, -4), 'type' => 'daily', 'status' => 'submitted',
            'author_id' => $author, 'period_start' => '2026-10-15', 'period_end' => '2026-10-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function file(): string
    {
        $id = (string) Str::uuid7();
        DB::table('files')->insert([
            'id' => $id, 'original_name' => 'RPT-DY-2026-0001.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
            'storage_path' => '2026/10/report/'.Str::uuid7().'/'.$id.'.pdf', 'scan_status' => 'clean',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
