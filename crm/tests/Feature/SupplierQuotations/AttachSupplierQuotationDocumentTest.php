<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\Exceptions\UploadRejected;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\UploadRejectionReason;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use App\Modules\SupplierQuotations\Application\Documents\AttachSupplierQuotationDocument;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 6, Point 5.2 — §7.2's `pdf_file` written, at the use case.
 *
 * The endpoint is Point 5.3, so everything here calls `handle()` directly.
 * That is the level the ordering questions live at: whether a file is stored
 * before the parent is checked, whether the audit row shares the transaction,
 * and whether a scanner outage can roll back a write that already succeeded.
 *
 * ── What is deliberately not re-tested ────────────────────────────────────
 *
 * §17's bytes-level rules — true MIME, `D-40`'s six types, the 30 MB ceiling,
 * the integrity checks — belong to `FinfoUploadValidator` and are covered by
 * `UploadValidationTest`'s 26 cases. Repeating them here would be a second
 * implementation's worth of tests for one implementation. What is asserted is
 * that this use case **calls** the validator and lets its refusal through
 * untouched, and that nothing is written when it refuses.
 *
 * ── No `RowScope`, unlike `AttachDealDocument` ────────────────────────────
 *
 * `AttachDealDocument` takes `array $heldScopes` and resolves a `DealRowScope`
 * because §3.4 gives deals five reaches. §3.6 gives this resource one, `All`,
 * so the parameter does not exist here — it would be the "parameter every
 * caller passes the same value for". The authorisation itself is the route's
 * middleware (Point 5.3) and `mayView` on the download side (Point 5.1).
 */
final class AttachSupplierQuotationDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $scratch = [];

    private string $actorId;

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actorId = (string) Str::uuid7();
    }

    // ───────────────────────────────────────────────────────── the happy path

    public function test_that_it_stores_the_file_and_links_it_to_the_offer(): void
    {
        $offerId = $this->offer();

        $document = $this->attach()->handle($offerId, $this->file(self::pdf()), 'offer.pdf', $this->actorId);

        self::assertSame('offer.pdf', $document->originalName);
        self::assertSame('application/pdf', $document->mimeType);
        self::assertSame(ScanStatus::Clean->value, $document->scanStatus);
        self::assertGreaterThan(0, $document->sizeBytes);

        self::assertSame(1, DB::table('files')->where('id', $document->id)->count());
        self::assertSame(1, DB::table('supplier_quotation_files')
            ->where('supplier_quotation_id', $offerId)
            ->where('file_id', $document->id)
            ->count());
    }

    /** The bytes actually reach the disk at §17's path, not only the row. */
    public function test_that_the_stored_bytes_are_readable_back(): void
    {
        $offerId = $this->offer();
        $contents = self::pdf(2048);

        $document = $this->attach()->handle($offerId, $this->file($contents), 'offer.pdf', $this->actorId);

        $storedPath = DB::table('files')->where('id', $document->id)->value('storage_path');
        self::assertIsString($storedPath);

        $storage = $this->app->make(StorageServiceInterface::class);
        self::assertInstanceOf(StorageServiceInterface::class, $storage);

        self::assertSame($contents, $storage->read(StoragePath::fromStored($storedPath)));
    }

    /** `AUD-01` — one row, its own event name, filed under this module's entity. */
    public function test_that_the_attachment_is_written_to_the_audit_log(): void
    {
        $offerId = $this->offer();

        $document = $this->attach()->handle($offerId, $this->file(self::pdf()), 'offer.pdf', $this->actorId);

        $recorded = DB::table('audit_log')
            ->where('entity_type', 'supplier_quotation')
            ->where('entity_id', $offerId)
            ->where('event', 'SUPPLIER_QUOTATION_DOCUMENT_ATTACHED')
            ->pluck('new_values')
            ->all();

        self::assertCount(1, $recorded);
        self::assertIsString($recorded[0]);
        self::assertStringContainsString($document->id, $recorded[0]);
    }

    // ─────────────────────────────────────────────── the parent is checked first

    public function test_that_an_unknown_offer_is_not_found(): void
    {
        $this->expectException(SupplierQuotationNotFound::class);

        $this->attach()->handle((string) Str::uuid7(), $this->file(self::pdf()), 'offer.pdf', $this->actorId);
    }

    /** `DB-01` — a soft-deleted offer is absent, and `find()` already says so. */
    public function test_that_a_soft_deleted_offer_is_not_found(): void
    {
        $offerId = $this->offer();
        DB::table('supplier_quotations')->where('id', $offerId)->update(['deleted_at' => now()]);

        $this->expectException(SupplierQuotationNotFound::class);

        $this->attach()->handle($offerId, $this->file(self::pdf()), 'offer.pdf', $this->actorId);
    }

    /**
     * The ordering, pinned: an unacceptable file sent to an offer that is not
     * there answers **404, not 422**. Validating first would tell a caller that
     * their file was wrong about an offer they may not even learn exists, and
     * it would run §17's work for a request that was never going to be written.
     */
    public function test_that_an_unknown_offer_is_reported_before_the_file_is_even_looked_at(): void
    {
        $this->expectException(SupplierQuotationNotFound::class);

        $this->attach()->handle(
            (string) Str::uuid7(),
            $this->file(self::executable()),
            'payload.pdf',
            $this->actorId,
        );
    }

    public function test_that_nothing_is_written_when_the_offer_is_unknown(): void
    {
        try {
            $this->attach()->handle((string) Str::uuid7(), $this->file(self::pdf()), 'offer.pdf', $this->actorId);
        } catch (SupplierQuotationNotFound) {
            // The assertion is what did not happen.
        }

        self::assertSame(0, DB::table('files')->count());
        self::assertSame(0, DB::table('supplier_quotation_files')->count());
    }

    // ────────────────────────────────────────────── §17's refusals pass through

    public function test_that_an_unsupported_type_is_refused_and_nothing_is_written(): void
    {
        $offerId = $this->offer();

        try {
            $this->attach()->handle($offerId, $this->file(self::executable()), 'offer.pdf', $this->actorId);
            self::fail('Expected the upload to be refused.');
        } catch (UploadRejected $rejected) {
            self::assertSame(UploadRejectionReason::UnsupportedType, $rejected->reason);
        }

        self::assertSame(0, DB::table('files')->count());
        self::assertSame(0, DB::table('supplier_quotation_files')->count());
    }

    public function test_that_an_empty_file_is_refused(): void
    {
        $offerId = $this->offer();

        try {
            $this->attach()->handle($offerId, $this->file(''), 'offer.pdf', $this->actorId);
            self::fail('Expected the upload to be refused.');
        } catch (UploadRejected $rejected) {
            self::assertSame(UploadRejectionReason::EmptyFile, $rejected->reason);
        }
    }

    // ───────────────────────────────────────────────────── the scan is separate

    /**
     * An infected file still uploads. §17 makes scanning mandatory, not
     * blocking: the verdict is recorded and `DownloadFile` refuses to serve it
     * (`ScanStatus::isServable()`), which is where an infected file is actually
     * stopped.
     */
    public function test_that_an_infected_file_still_attaches_and_is_reported_infected(): void
    {
        $offerId = $this->offer();

        $document = $this->attach()->handle(
            $offerId,
            $this->file(self::pdfBytesWithEicarSignature()),
            'offer.pdf',
            $this->actorId,
        );

        self::assertSame(ScanStatus::Infected->value, $document->scanStatus);
        self::assertSame(1, DB::table('supplier_quotation_files')->where('file_id', $document->id)->count());
    }

    /**
     * A scanner outage must not undo an upload that already committed.
     *
     * The scan runs **after** the transaction for exactly this: rolling the
     * write back on an outage would turn "not yet checked" into "never
     * happened", and `pending` already means "not servable".
     */
    public function test_that_a_scanner_outage_leaves_the_upload_committed_and_pending(): void
    {
        $offerId = $this->offer();

        $this->app->bind(VirusScannerInterface::class, fn (): VirusScannerInterface => new class implements VirusScannerInterface
        {
            public function scan($contents): ScanStatus
            {
                throw new ScannerUnavailable('The scanner is down for this test.');
            }
        });

        $document = $this->attach()->handle($offerId, $this->file(self::pdf()), 'offer.pdf', $this->actorId);

        self::assertSame(ScanStatus::Pending->value, $document->scanStatus);
        self::assertSame(1, DB::table('files')->where('id', $document->id)->count());
        self::assertSame(1, DB::table('supplier_quotation_files')->where('file_id', $document->id)->count());
    }

    // ───────────────────────────────────────────────────────────────  helpers

    private function attach(): AttachSupplierQuotationDocument
    {
        $attach = $this->app->make(AttachSupplierQuotationDocument::class);
        self::assertInstanceOf(AttachSupplierQuotationDocument::class, $attach);

        return $attach;
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-sq-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return $path;
    }

    /** A whole, if minimal, PDF — optionally padded to an exact byte count. */
    private static function pdf(int $padTo = 0): string
    {
        $head = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n";
        $tail = "%%EOF\n";
        $pad = max(0, $padTo - strlen($head) - strlen($tail));

        return $head.str_repeat(' ', $pad).$tail;
    }

    /**
     * A whole PDF that also carries the EICAR test signature — assembled from
     * parts at run time and never written whole, because a source file
     * containing that literal can trip a scanner reading this repository.
     */
    private static function pdfBytesWithEicarSignature(): string
    {
        $signature = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'.'-STANDARD-ANTIVIRUS-TEST-'.'FILE!$H+H*';

        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$signature."\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** A real ELF header — not one of `D-40`'s six types, whatever it is named. */
    private static function executable(): string
    {
        return "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 8).str_repeat("\x00", 200);
    }

    private function offer(): string
    {
        $supplierId = (string) Str::uuid7();
        $currencyId = (string) Str::uuid7();
        $offerId = (string) Str::uuid7();

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-9001',
            'supplier_id' => $supplierId,
            'total_price' => '4500.000000',
            'currency_id' => $currencyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $offerId;
    }
}
