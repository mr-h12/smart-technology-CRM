<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 9, Point 1.3 — `quotation_files` and `AttachmentParent::Quotation`,
 * the place a generated customer PDF is stored against its quotation (§14.6,
 * `D-71`).
 *
 * `FilesMigrationTest` already runs every pivot through the shape `D-71`
 * requires — two columns, the composite key, the `file_id` index and cascade —
 * and `quotation_files` is appended to its list. What is proven here is what
 * only this pivot has: a parent key from the day it was created, the write
 * path through Storage's own contract, and the permission path refusing it
 * until Step 4 gives the quotation a rule of its own.
 */
final class QuotationFilesTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_22_100000_create_quotation_files.php';

    public function test_that_the_case_names_the_pivot_and_the_path_segment(): void
    {
        self::assertSame('quotation', AttachmentParent::Quotation->value);
        self::assertSame('quotation_files', AttachmentParent::Quotation->pivotTable());
    }

    public function test_that_the_pivot_refuses_a_quotation_that_does_not_exist(): void
    {
        // The parent key the other four pivots had to wait for. A fabricated
        // quotation id must fail on the foreign key, not on a missing table.
        self::assertTrue(Schema::hasTable('quotation_files'), 'quotation_files does not exist yet.');

        try {
            DB::table('quotation_files')->insert([
                'quotation_id' => Uuid::uuid7()->toString(),
                'file_id' => $this->insertFile(),
            ]);
        } catch (QueryException $e) {
            self::assertSame('23503', $e->getCode(), 'The insert failed, but not on the foreign key.');
            self::assertStringContainsString('quotation_id', $e->getMessage());

            return;
        }

        self::fail('quotation_files accepted a quotation_id that references nothing (D-71).');
    }

    public function test_that_attaching_through_storage_writes_the_row_and_reads_back(): void
    {
        $quotationId = $this->insertQuotation();
        $fileId = $this->insertFile();

        $this->app->make(FileWriterInterface::class)->attach(AttachmentParent::Quotation, $quotationId, $fileId);

        self::assertSame(1, DB::table('quotation_files')->where(['quotation_id' => $quotationId, 'file_id' => $fileId])->count());
        self::assertEquals(
            [new AttachmentLink(AttachmentParent::Quotation, $quotationId)],
            $this->app->make(FileRepositoryInterface::class)->parentsOf($fileId),
        );
    }

    public function test_that_a_duplicate_attach_is_refused_by_the_primary_key(): void
    {
        // No pre-check in the writer: two concurrent requests can both pass an
        // "is it attached?" query, and only one can win the key.
        $quotationId = $this->insertQuotation();
        $fileId = $this->insertFile();
        $writer = $this->app->make(FileWriterInterface::class);

        $writer->attach(AttachmentParent::Quotation, $quotationId, $fileId);

        try {
            $writer->attach(AttachmentParent::Quotation, $quotationId, $fileId);
        } catch (QueryException $e) {
            self::assertSame('23505', $e->getCode(), 'The second attach failed, but not on the primary key.');

            return;
        }

        self::fail('The same file was attached to the same quotation twice.');
    }

    public function test_that_the_permission_path_refuses_a_quotation_file_to_a_stranger(): void
    {
        // D-38 checks a file through its parent. Until Step 4 the composite
        // refused every quotation file for want of a rule; Point 4.1 registered
        // §3.5's export/download row, proven role by role in
        // `QuotationPdfDownloadTest`. A caller who is nobody still gets nothing.
        $quotationId = $this->insertQuotation();

        self::assertFalse(
            $this->app->make(AttachmentPermissionInterface::class)
                ->mayView(new AttachmentLink(AttachmentParent::Quotation, $quotationId), Uuid::uuid7()->toString()),
        );
    }

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasTable('quotation_files'), 'down() left the table behind (DEV-03).');
        self::assertTrue(Schema::hasTable('quotations'), 'down() dropped a table it does not own.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('quotation_files'), 'The table did not come back.');
    }
}
