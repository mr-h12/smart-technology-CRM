<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Application\Generation\DownloadReportPdf;
use App\Modules\Pdf\Application\Generation\RetryReportPdf;
use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\ReportPdfGeneration;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Support\Http\ApiEnvelope;
use App\Support\Http\PrivateFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `D-132`, spec §4.3 — `GET /api/v1/reports/{report}/versions/{version}/pdf`:
 * where a submitted version's stored PDF stands. `report.export` is the
 * route's; the author or a live recipient is the row's. A draft, a missing
 * version, a version with no generation row and a caller outside the rule
 * are all the report's 404.
 *
 * Point 7.2 adds `POST …/pdf/retry` (a `failed` version back to the queue, `202` with
 * the status's shape) and `GET …/pdf/download` (the stored file, audited), on the same rule.
 */
final class ReportPdfController
{
    public function show(Request $request, string $report, int $version, SubmittedReportsInterface $reports, ReportPdfGenerationsInterface $generations): JsonResponse
    {
        $generation = $reports->mayOpen($report, self::actorId($request)) ? $generations->forVersion($report, $version) : null;

        if ($generation === null) {
            throw ReportNotFound::of($report);
        }

        return ApiEnvelope::single($request, self::state($generation));
    }

    /** The job is pushed once the retry has committed, so it never runs ahead of the row it works on. */
    public function retry(Request $request, string $report, int $version, RetryReportPdf $retry, ReportPdfGenerationsInterface $generations): JsonResponse
    {
        $id = $retry->retry($report, $version, self::actorId($request));

        RenderReportPdfJob::dispatch($id);

        $generation = $generations->find($id) ?? throw ReportNotFound::of($report);

        return ApiEnvelope::single($request, self::state($generation), 202);
    }

    /** Streams as `DownloadFileController` does, under `<code>-v<version>.pdf`. */
    public function download(Request $request, string $report, int $version, DownloadReportPdf $download): StreamedResponse
    {
        $opened = $download->open($report, $version, self::actorId($request));

        return PrivateFileResponse::make($opened['stream'], $opened['file']->mimeType, $opened['file']->sizeBytes, $opened['name'], 'report.pdf');
    }

    /** @return array<string, string|null> */
    private static function state(ReportPdfGeneration $generation): array
    {
        return [
            'status' => $generation->status->value,
            'file_id' => $generation->fileId,
            'failure_reason' => $generation->failureReason,
            'finished_at' => $generation->finishedAt?->format(DATE_ATOM),
        ];
    }

    private static function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The report PDF route requires authentication.');
        }

        return (string) $id;
    }
}
