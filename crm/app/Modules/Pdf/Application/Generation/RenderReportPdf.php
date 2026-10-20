<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Generation;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use App\Modules\Pdf\Domain\Generation\ReportPdfGeneration;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\ConnectionInterface;
use IntlDateFormatter;
use Throwable;
use UnexpectedValueException;

/**
 * `D-132`, spec §4.2 — one attempt at one report version's stored PDF, on
 * `RenderQuotationPdf`'s shape: read the frozen version through Reports'
 * contract, render `pdf.report` in Arabic, store it under
 * `AttachmentParent::Report`, write the `files` row, the pivot and the
 * generation's `file_id` in one transaction, scan it, mark it `completed`.
 *
 * Idempotent: an attempt that finds `file_id` set only re-scans; one that finds
 * the generation final changes nothing. The retry bound is the worker's
 * `--tries=3`; `giveUp()` is the failure record.
 */
final readonly class RenderReportPdf
{
    public function __construct(
        private ReportPdfGenerationsInterface $generations,
        private SubmittedReportsInterface $reports,
        private ViewFactory $views,
        private Translator $translator,
        private PdfAssetsInterface $assets,
        private CompanyToday $company,
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
    public function handle(string $generationId, int $attempt): void
    {
        $generation = $this->generations->find($generationId);

        if ($generation === null || $generation->status !== PdfGenerationStatus::Queued) {
            return;
        }

        $this->generations->recordAttempt($generationId, $attempt);

        $fileId = $generation->fileId ?? $this->store($generation);

        if ($this->scan->scan($fileId) !== ScanStatus::Clean) {
            // Our own render refused by the scanner is not something a retry fixes.
            $this->generations->fail($generationId, 'The generated PDF did not pass the virus scan.');

            return;
        }

        $this->generations->complete($generationId);
    }

    public function giveUp(string $generationId, Throwable $cause): void
    {
        $this->generations->fail($generationId, match (true) {
            $cause instanceof PdfRenderingFailed => 'The PDF could not be rendered.',
            $cause instanceof ScannerUnavailable => 'The virus scanner could not be reached.',
            default => 'The PDF could not be generated.',
        });
    }

    private function store(ReportPdfGeneration $generation): string
    {
        $requester = $generation->requestedBy
            ?? throw new UnexpectedValueException("Generation {$generation->id} names nobody who asked for it.");
        $report = $this->reports->version($generation->reportId, $generation->version)
            ?? throw new UnexpectedValueException("Report {$generation->reportId} has no submitted version {$generation->version}.");

        $bytes = $this->renderer->render($this->html($report), $this->footer($report['code']));

        $path = $this->storage->storeContents(AttachmentParent::Report, $generation->reportId, AllowedFileType::Pdf, $bytes);

        return $this->connection->transaction(function () use ($generation, $report, $path, $bytes, $requester): string {
            $fileId = $this->files->create($path, $report['code'].'.pdf', AllowedFileType::Pdf->mimeType(), strlen($bytes), $requester);

            $this->files->attach(AttachmentParent::Report, $generation->reportId, $fileId);
            $this->generations->attachFile($generation->id, $fileId);

            return $fileId;
        });
    }

    /**
     * The page number Chrome draws in every page's bottom margin, as the quotation's footer does: its own
     * document, so its CSS is inline; Inter, English, the face the image installs (`CustomerQuotationHtml::footer()`).
     */
    private function footer(string $code): string
    {
        $page = (string) $this->translator->get('pdf.footer.page', [
            'current' => '<span class="pageNumber"></span>',
            'total' => '<span class="totalPages"></span>',
        ], 'ar');

        return '<div dir="ltr" lang="en" style="width: calc(100% - 44mm); position: relative; top: 4.6mm; margin: 0 22mm; padding-top: 3mm;'
            .' border-top: 0.75pt solid #bfbfbf; font-family: Inter; font-size: 8pt; color: #555; display: flex; justify-content: space-between;">'
            .'<span>'.e($code).'</span><span>'.$page.'</span></div>';
    }

    /**
     * `D-132`: Arabic only, so every label is `ar` whatever the worker's locale.
     *
     * @param  array<string, mixed>  $report  `SubmittedReportsInterface::version()`'s answer
     */
    private function html(array $report): string
    {
        $zone = new DateTimeZone($this->company->zone());

        return $this->views->make('pdf.report', [
            'report' => $report,
            'fontFaceCss' => $this->assets->fontFaceCss(),
            'logo' => $this->assets->logo(),
            't' => fn (string $key, array $replace = []): string => (string) $this->translator->get('pdf.report.'.$key, $replace, 'ar'),
            // `D-132`: a status or response code as its Arabic word; no code is a dash, an unlabelled code prints raw.
            'word' => function (string $set, ?string $code): string {
                if ($code === null) {
                    return (string) $this->translator->get('pdf.report.no_value', [], 'ar');
                }
                $key = "pdf.report.{$set}.{$code}";

                return $this->translator->has($key, 'ar') ? (string) $this->translator->get($key, [], 'ar') : $code;
            },
            // A date or code in an RTL line is isolated, as `CustomerQuotationHtml` does, so bidi keeps its order.
            'ltr' => static fn (?string $value): string => $value === null ? '' : "\u{2066}{$value}\u{2069}",
            // `D-133`, `D-132`: a stored UTC instant, shown in the company's zone (`locale.timezone`).
            'time' => static fn (string $utc): string => (new DateTimeImmutable($utc))->setTimezone($zone)->format('Y-m-d H:i'),
            // RPT-MN names its month from its first day, «سبتمبر 2026», Western digits (`D-70`; the owner, 2026-10-20).
            'month' => static fn (string $date): string => (string) (new IntlDateFormatter('ar-EG@numbers=latn', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'LLLL y'))
                ->format(new DateTimeImmutable($date, new DateTimeZone('UTC'))),
            // Display only, BCMath at two places (`D-99`), never a float (`DB-07`).
            // ponytail: 2 places, as the quotation PDF. A currency unit finer than 0.01, or rounding off with more
            // than 2 stored places, would print a rounded figure; upgrade: the currency's scale from the Currencies contract.
            'money' => static fn (string $value): string => bcround(Decimal::of($value, 'a report PDF money figure'), 2),
        ])->render();
    }
}
