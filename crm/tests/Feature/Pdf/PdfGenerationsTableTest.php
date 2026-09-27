<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 9, Point 3.3 — `pdf_generations`, the record behind `OpenAPI §4.3`'s
 * `job_id`: one row per request to render a customer quotation, `queued` until
 * the job (3.4) finishes it `completed` with its file or `failed` with a reason.
 *
 * Every rule a row must obey is the database's, so each is proven by an insert
 * the database refuses, by its SQLSTATE: `23514` for a CHECK, `23503` for a key.
 */
final class PdfGenerationsTableTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_27_100000_create_pdf_generations.php';

    public function test_that_a_generation_starts_queued_with_no_file_no_attempt_and_no_end(): void
    {
        $id = $this->insertGeneration([]);

        $row = DB::table('pdf_generations')->where('id', $id)->first();

        self::assertNotNull($row);
        self::assertSame('queued', $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->file_id);
        self::assertNull($row->failure_reason);
        self::assertNull($row->finished_at);
    }

    public function test_that_a_completed_generation_with_its_file_and_end_is_accepted(): void
    {
        $id = $this->insertGeneration(['status' => 'completed', 'file_id' => $this->insertFile(), 'finished_at' => now()]);

        self::assertSame('completed', DB::table('pdf_generations')->where('id', $id)->value('status'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedRows(): iterable
    {
        // Each row breaks exactly one rule, or Postgres may name another one.
        yield 'a status no state machine knows' => [['status' => 'running', 'finished_at' => '2026-09-27 10:00:00+00'], 'pdf_generations_known_status'];
        yield 'a language the template has no labels for' => [['locale' => 'fr'], 'pdf_generations_known_locale'];
        yield 'a completed generation without its file' => [['status' => 'completed', 'finished_at' => '2026-09-27 10:00:00+00'], 'pdf_generations_completed_has_file'];
        yield 'a failed generation without its reason' => [['status' => 'failed', 'finished_at' => '2026-09-27 10:00:00+00'], 'pdf_generations_failed_has_reason'];
        yield 'a finished generation with no end time' => [['status' => 'failed', 'failure_reason' => 'renderer timed out'], 'pdf_generations_finished_iff_final'];
        yield 'a queued generation with an end time' => [['finished_at' => '2026-09-27 10:00:00+00'], 'pdf_generations_finished_iff_final'];
        yield 'a negative attempt count' => [['attempts' => -1], 'pdf_generations_attempts_non_negative'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('refusedRows')]
    public function test_that_the_database_refuses(array $overrides, string $constraint): void
    {
        try {
            $this->insertGeneration($overrides);
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode(), 'The insert failed, but not on a CHECK.');
            self::assertStringContainsString($constraint, $e->getMessage());

            return;
        }

        self::fail("pdf_generations accepted a row {$constraint} exists to refuse.");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function orphanKeys(): iterable
    {
        yield 'a quotation that does not exist' => ['quotation_id'];
        yield 'a file that does not exist' => ['file_id'];
        yield 'a requester who does not exist' => ['created_by'];
    }

    #[DataProvider('orphanKeys')]
    public function test_that_the_key_refuses(string $column): void
    {
        try {
            $this->insertGeneration([$column => Uuid::uuid7()->toString()]);
        } catch (QueryException $e) {
            self::assertSame('23503', $e->getCode(), 'The insert failed, but not on a foreign key.');
            self::assertStringContainsString($column, $e->getMessage());

            return;
        }

        self::fail("pdf_generations accepted a {$column} that references nothing (DB-04).");
    }

    public function test_that_the_latest_generation_of_a_quotation_is_indexed(): void
    {
        // 4.2 reads "the newest live row for one quotation" on every poll.
        $definition = DB::table('pg_indexes')
            ->where(['tablename' => 'pdf_generations', 'indexname' => 'pdf_generations_latest'])
            ->value('indexdef');

        self::assertIsString($definition, 'pdf_generations_latest does not exist.');
        self::assertStringContainsString('(quotation_id, created_at DESC)', $definition);
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition);
    }

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasTable('pdf_generations'), 'down() left the table behind (DEV-03).');
        self::assertTrue(Schema::hasTable('quotations'), 'down() dropped a table it does not own.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('pdf_generations'), 'The table did not come back.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertGeneration(array $overrides): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('pdf_generations')->insert($overrides + [
            'id' => $id,
            'quotation_id' => $this->insertQuotation(),
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
