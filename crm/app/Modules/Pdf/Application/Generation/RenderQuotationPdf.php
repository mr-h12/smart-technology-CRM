<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Generation;

use App\Modules\Pdf\Application\CustomerQuotationHtml;
use App\Modules\Pdf\Domain\Contracts\PdfGenerationsInterface;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Generation\PdfGeneration;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use Illuminate\Database\ConnectionInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Module 9, Point 3.4 — one attempt at one generation: render the view it was
 * handed (Q10), `store()` it under `AttachmentParent::Quotation`, write the
 * `files` row, the pivot and the generation's `file_id` in one transaction,
 * scan it (Q13), mark it `completed`.
 *
 * **Idempotent (§15.1).** An attempt that finds `file_id` set only re-scans,
 * so a scanner outage costs a retry, never a second file; one that finds the
 * generation final — or withdrawn (`DB-01`) — changes nothing. The retry
 * bound is the worker's `--tries=3`; `giveUp()` is Q3's failure record.
 */
final readonly class RenderQuotationPdf
{
    public function __construct(
        private PdfGenerationsInterface $generations,
        private CustomerQuotationHtml $html,
        private PdfRendererInterface $renderer,
        private StorageServiceInterface $storage,
        private FileWriterInterface $files,
        private ScanStoredFile $scan,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws PdfRenderingFailed when Chromium does not answer — the worker retries
     * @throws ScannerUnavailable when the scan cannot run — the worker retries, and the next attempt only re-scans
     */
    public function handle(string $generationId, CustomerQuotationView $view, int $attempt): void
    {
        $generation = $this->generations->find($generationId);

        if ($generation === null || $generation->status !== PdfGenerationStatus::Queued) {
            return;
        }

        $this->generations->recordAttempt($generationId, $attempt);

        $fileId = $generation->fileId ?? $this->store($generation, $view);

        if ($this->scan->scan($fileId) !== ScanStatus::Clean) {
            // Our own render refused by the scanner is not something a retry fixes.
            $this->generations->fail($generationId, 'The generated PDF did not pass the virus scan.');

            return;
        }

        $this->generations->complete($generationId);
    }

    /** Q3's failure record, once the worker has spent its tries — in words the panel (5.1) can show. */
    public function giveUp(string $generationId, Throwable $cause): void
    {
        $this->generations->fail($generationId, match (true) {
            $cause instanceof PdfRenderingFailed => 'The PDF could not be rendered.',
            $cause instanceof ScannerUnavailable => 'The virus scanner could not be reached.',
            default => 'The PDF could not be generated.',
        });
    }

    private function store(PdfGeneration $generation, CustomerQuotationView $view): string
    {
        $requester = $generation->requestedBy
            ?? throw new UnexpectedValueException("Generation {$generation->id} names nobody who asked for it.");

        $bytes = $this->renderer->render(
            $this->html->render($view, $generation->locale),
            $this->html->footer($generation->locale),
        );

        // `store()` copies from a readable source and leaves it alone. Handing
        // it the bytes as a `data:` URL keeps this module off the filesystem
        // (§14.2) — no temporary file to write, and none to `unlink()`.
        $path = $this->storage->store(
            AttachmentParent::Quotation,
            $generation->quotationId,
            AllowedFileType::Pdf,
            'data://application/pdf;base64,'.base64_encode($bytes),
        );

        return $this->connection->transaction(function () use ($generation, $view, $path, $bytes, $requester): string {
            $fileId = $this->files->create($path, $view->code.'.pdf', AllowedFileType::Pdf->mimeType(), strlen($bytes), $requester);

            $this->files->attach(AttachmentParent::Quotation, $generation->quotationId, $fileId);
            $this->generations->attachFile($generation->id, $fileId);

            return $fileId;
        });
    }
}
