<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Reports\Domain\ReportWriteRefused;

/**
 * The author's reach, on `QuotationWriteAccess`'s order: 404 for a report
 * that is not the caller's, then 400 for `If-Match`, then 409 for a stale token.
 * This slice is the author's side only; the receiving side is a later slice.
 */
final readonly class ReportAccess
{
    public function __construct(private ReportDirectoryInterface $reports) {}

    public function reach(string $reportId, string $actorId): Report
    {
        $report = $this->reports->find($reportId);

        if ($report === null || $report->authorId !== $actorId) {
            throw ReportNotFound::of($reportId);
        }

        return $report;
    }

    public function open(string $reportId, ?string $ifMatch, string $actorId): Report
    {
        $report = $this->reach($reportId, $actorId);

        if (ReportEtag::tokenFrom($ifMatch, $reportId) !== $report->versionToken) {
            throw ReportWriteRefused::staleVersion(ReportEtag::of($report));
        }

        return $report;
    }
}
