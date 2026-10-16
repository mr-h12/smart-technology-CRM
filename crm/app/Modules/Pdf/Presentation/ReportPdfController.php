<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `D-132`, spec §4.3 — `GET /api/v1/reports/{report}/versions/{version}/pdf`:
 * where a submitted version's stored PDF stands. `report.export` is the
 * route's; the author or a live recipient is the row's. A draft, a missing
 * version, a version with no generation row and a caller outside the rule
 * are all the report's 404.
 */
final class ReportPdfController
{
    public function show(Request $request, string $report, int $version, SubmittedReportsInterface $reports, ReportPdfGenerationsInterface $generations): JsonResponse
    {
        $generation = $reports->mayOpen($report, self::actorId($request)) ? $generations->forVersion($report, $version) : null;

        if ($generation === null) {
            throw ReportNotFound::of($report);
        }

        return ApiEnvelope::single($request, [
            'status' => $generation->status->value,
            'file_id' => $generation->fileId,
            'failure_reason' => $generation->failureReason,
            'finished_at' => $generation->finishedAt?->format(DATE_ATOM),
        ]);
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
