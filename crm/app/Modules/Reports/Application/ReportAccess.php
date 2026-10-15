<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Reports\Domain\ReportWriteRefused;

/**
 * Who reaches a report, on `QuotationWriteAccess`'s order: 404 for a report
 * out of reach, then 400 for `If-Match`, then 409 for a stale token. The
 * author reaches their own; a recipient reaches what was sent to them — a live
 * `report_recipients` row (the owner, 2026-10-16).
 * ponytail: Team / Out scopes (`D-129`) narrow nothing yet — they wait for teams.
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
        return self::current($this->reach($reportId, $actorId), $ifMatch);
    }

    public function reachReceived(string $reportId, string $actorId): Report
    {
        $report = $this->reports->find($reportId);

        if ($report === null || ! in_array($actorId, $this->reports->recipientIdsOf($reportId), true)) {
            throw ReportNotFound::of($reportId);
        }

        return $report;
    }

    public function openReceived(string $reportId, ?string $ifMatch, string $actorId): Report
    {
        return self::current($this->reachReceived($reportId, $actorId), $ifMatch);
    }

    private static function current(Report $report, ?string $ifMatch): Report
    {
        if (ReportEtag::tokenFrom($ifMatch, $report->id) !== $report->versionToken) {
            throw ReportWriteRefused::staleVersion(ReportEtag::of($report));
        }

        return $report;
    }
}
