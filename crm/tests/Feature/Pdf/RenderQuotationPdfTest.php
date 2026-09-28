<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Pdf\Application\Generation\RenderQuotationPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Pdf\Presentation\RenderQuotationPdfJob;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Storage\RemovesOnlyFilesItStored;
use Tests\Fixtures\CustomerQuotationViewFixture;
use Tests\TestCase;
use Throwable;

/**
 * Module 9, Point 3.4 — the job on the `pdf` queue: render the view it was
 * handed (Q10), `store()` it under `AttachmentParent::Quotation`, write the
 * `files` row, the pivot and the generation's `file_id` in one transaction,
 * scan (Q13), mark `completed`. Idempotent (§15.1): an attempt that finds
 * `file_id` set only re-scans. The worker's `--tries=3` is the retry bound;
 * `failed()` is Q3's failure record.
 *
 * Every attempt below is one `handle()` — what the worker does between
 * retries — so the retry bound is the worker's, measured once in
 * `RealQueueExecutionTest`, and not re-proved here.
 */
final class RenderQuotationPdfTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use RemovesOnlyFilesItStored;
    use SignsInByRole;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->rememberStoredFiles(AttachmentParent::Quotation);

        $this->renderer = new FakeRenderer(self::PDF);
        $this->app->instance(PdfRendererInterface::class, $this->renderer);
    }

    protected function tearDown(): void
    {
        $this->removeFilesStoredSince(AttachmentParent::Quotation);

        parent::tearDown();
    }

    public function test_that_a_render_is_stored_attached_scanned_and_completed(): void
    {
        [$generationId, $quotationId, $requester] = $this->queuedGeneration('ar');

        $this->attempt($generationId, 1);

        $generation = DB::table('pdf_generations')->where('id', $generationId)->first();
        self::assertNotNull($generation);
        self::assertSame('completed', $generation->status);
        self::assertSame(1, $generation->attempts);
        self::assertNotNull($generation->finished_at);
        self::assertSame($requester, $generation->updated_by, 'A queued job acts for whoever asked.');

        $file = DB::table('files')->where('id', $generation->file_id)->first();
        self::assertNotNull($file);
        self::assertSame('QT-2026-0001.pdf', $file->original_name);
        self::assertSame('application/pdf', $file->mime_type);
        self::assertSame(strlen(self::PDF), $file->size_bytes);
        self::assertSame('clean', $file->scan_status, 'Q13: scanned like any other file, not inserted as clean.');
        self::assertSame($requester, $file->created_by);
        self::assertSame(1, DB::table('quotation_files')->where('quotation_id', $quotationId)->where('file_id', $file->id)->count());

        // The bytes went through Storage's own `store()` whole.
        self::assertIsString($file->storage_path);
        self::assertSame(self::PDF, $this->storedBytes($file->storage_path));
        self::assertSame(['ar'], $this->renderer->locales);
    }

    public function test_that_a_renderer_failing_twice_then_succeeding_leaves_exactly_one_file(): void
    {
        [$generationId, $quotationId] = $this->queuedGeneration();
        $this->renderer->failTimes(2);

        $this->attemptExpectingFailure($generationId, 1, PdfRenderingFailed::class);
        $this->attemptExpectingFailure($generationId, 2, PdfRenderingFailed::class);
        $this->attempt($generationId, 3);

        self::assertSame('completed', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertSame(3, DB::table('pdf_generations')->where('id', $generationId)->value('attempts'));
        self::assertSame(1, DB::table('quotation_files')->where('quotation_id', $quotationId)->count());
        self::assertSame(1, DB::table('files')->count());
    }

    public function test_that_a_renderer_that_always_fails_ends_failed_with_no_file(): void
    {
        [$generationId, $quotationId] = $this->queuedGeneration();
        $this->renderer->failTimes(3);

        $last = null;
        foreach ([1, 2, 3] as $attempt) {
            $last = $this->attemptExpectingFailure($generationId, $attempt, PdfRenderingFailed::class);
        }

        // What the worker does once `--tries=3` is spent.
        (new RenderQuotationPdfJob($generationId, CustomerQuotationViewFixture::make()))->failed($last);

        $generation = DB::table('pdf_generations')->where('id', $generationId)->first();
        self::assertNotNull($generation);
        self::assertSame('failed', $generation->status);
        self::assertSame('The PDF could not be rendered.', $generation->failure_reason);
        self::assertNotNull($generation->finished_at);
        self::assertNull($generation->file_id);
        self::assertSame(0, DB::table('quotation_files')->where('quotation_id', $quotationId)->count());
        self::assertSame(0, DB::table('files')->count());
    }

    public function test_that_a_scanner_outage_is_retried_to_completed_without_a_second_render(): void
    {
        [$generationId, $quotationId] = $this->queuedGeneration();
        $this->app->instance(VirusScannerInterface::class, new FakeScanner([null, ScanStatus::Clean]));

        $this->attemptExpectingFailure($generationId, 1, ScannerUnavailable::class);

        // Stored and attached, not yet scanned — the next attempt only re-scans.
        self::assertSame('queued', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertNotNull(DB::table('pdf_generations')->where('id', $generationId)->value('file_id'));
        self::assertSame('pending', DB::table('files')->value('scan_status'));

        $this->attempt($generationId, 2);

        self::assertSame('completed', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertSame('clean', DB::table('files')->value('scan_status'));
        self::assertSame(1, $this->renderer->calls, 'A retry that finds the file stored must not render again.');
        self::assertSame(1, DB::table('quotation_files')->where('quotation_id', $quotationId)->count());
    }

    public function test_that_a_scanner_outage_on_every_attempt_is_recorded_by_its_reason(): void
    {
        [$generationId] = $this->queuedGeneration();
        $this->app->instance(VirusScannerInterface::class, new FakeScanner([null, null, null]));

        $last = null;
        foreach ([1, 2, 3] as $attempt) {
            $last = $this->attemptExpectingFailure($generationId, $attempt, ScannerUnavailable::class);
        }
        (new RenderQuotationPdfJob($generationId, CustomerQuotationViewFixture::make()))->failed($last);

        self::assertSame('failed', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertSame('The virus scanner could not be reached.', DB::table('pdf_generations')->where('id', $generationId)->value('failure_reason'));
        self::assertSame(1, $this->renderer->calls);
    }

    public function test_that_an_infected_verdict_fails_the_generation_and_nothing_is_served(): void
    {
        [$generationId] = $this->queuedGeneration();
        $this->app->instance(VirusScannerInterface::class, new FakeScanner([ScanStatus::Infected]));

        $this->attempt($generationId, 1);

        self::assertSame('failed', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertSame('The generated PDF did not pass the virus scan.', DB::table('pdf_generations')->where('id', $generationId)->value('failure_reason'));
        self::assertSame('infected', DB::table('files')->value('scan_status'));
    }

    public function test_that_a_final_or_withdrawn_generation_is_left_alone(): void
    {
        [$completed] = $this->queuedGeneration();
        $this->attempt($completed, 1);
        $this->attempt($completed, 2);

        [$withdrawn] = $this->queuedGeneration();
        DB::table('pdf_generations')->where('id', $withdrawn)->update(['deleted_at' => now()]);
        $this->attempt($withdrawn, 1);

        // Failed with no file: a render here would store a file nothing points at.
        [$failed] = $this->queuedGeneration();
        DB::table('pdf_generations')->where('id', $failed)->update(['status' => 'failed', 'failure_reason' => 'Given up.', 'finished_at' => now()]);
        $this->attempt($failed, 4);

        self::assertSame(1, $this->renderer->calls, 'A redelivered job for a final or soft-deleted generation renders nothing.');
        self::assertSame(1, DB::table('pdf_generations')->where('id', $completed)->value('attempts'));
        self::assertSame('queued', DB::table('pdf_generations')->where('id', $withdrawn)->value('status'));
        self::assertSame(1, DB::table('files')->count());
    }

    public function test_that_the_job_rides_the_pdf_queue_and_carries_its_snapshot(): void
    {
        $view = CustomerQuotationViewFixture::make();
        $job = new RenderQuotationPdfJob('0192a1b2-0000-7000-8000-000000000001', $view);

        self::assertSame('pdf', $job->queue);

        // Q10: the view pressed is the view printed, through the queue's own serialisation.
        $revived = unserialize(serialize($job));
        self::assertInstanceOf(RenderQuotationPdfJob::class, $revived);
        self::assertEquals($view, $revived->view);
        self::assertGreaterThan(60, $job->timeout, 'The job must outlast the renderer’s own 60 s limit, or the worker kills it first.');
    }

    /**
     * @return array{string, string, string} the generation, its quotation and the requester
     */
    private function queuedGeneration(string $locale = 'en'): array
    {
        $requester = $this->userWith(RoleName::Manager)->id;
        $quotationId = $this->insertQuotation($requester);

        return [$this->insertGeneration(['quotation_id' => $quotationId, 'locale' => $locale, 'created_by' => $requester]), $quotationId, $requester];
    }

    private function attempt(string $generationId, int $attempt): void
    {
        $this->app->make(RenderQuotationPdf::class)->handle($generationId, CustomerQuotationViewFixture::make(), $attempt);
    }

    /**
     * @param  class-string<Throwable>  $expected
     */
    private function attemptExpectingFailure(string $generationId, int $attempt, string $expected): Throwable
    {
        try {
            $this->attempt($generationId, $attempt);
        } catch (Throwable $thrown) {
            self::assertInstanceOf($expected, $thrown);

            return $thrown;
        }

        self::fail("Attempt {$attempt} was expected to throw {$expected}, so the worker would retry it.");
    }

    private function storedBytes(string $storagePath): string
    {
        $stream = $this->app->make(StorageServiceInterface::class)->readStream(StoragePath::fromStored($storagePath));

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}

/** Renders fixed bytes, after failing the first `$failures` calls the way Chromium does. */
final class FakeRenderer implements PdfRendererInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $locales = [];

    private int $failures = 0;

    public function __construct(private readonly string $bytes) {}

    public function failTimes(int $failures): void
    {
        $this->failures = $failures;
    }

    public function render(string $html, ?string $footerHtml = null): string
    {
        $this->calls++;
        $this->locales[] = str_contains($html, 'dir="rtl"') ? 'ar' : 'en';

        if ($this->calls <= $this->failures) {
            throw PdfRenderingFailed::because(new RuntimeException('Chromium did not answer.'));
        }

        return $this->bytes;
    }
}

/** Answers each scan in turn; a `null` is the scanner being unreachable. */
final class FakeScanner implements VirusScannerInterface
{
    /** @param  list<ScanStatus|null>  $answers */
    public function __construct(private array $answers) {}

    public function scan($contents): ScanStatus
    {
        $answer = array_shift($this->answers);

        return $answer ?? throw new ScannerUnavailable('The scanner is down.');
    }
}
