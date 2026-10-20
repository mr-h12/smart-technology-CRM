<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Role;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;
use App\Modules\Reports\Domain\ReportEtag;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Reports\Domain\ReportWriteRefused;

/**
 * Who reaches a report, on `QuotationWriteAccess`'s order: 404 for a report
 * out of reach, then 400 for `If-Match`, then 409 for a stale token. The
 * author reaches their own; a recipient reaches what was sent to them — a live
 * `report_recipients` row (the owner, 2026-10-16) — while holding `report.view_received` (`OpenAPI §3.2`).
 * `Team` and `Out` choose who may be sent a report, never what a caller reaches
 * without a recipient row (`D-138`). `D-141`: the author reaches an RPT-MN only
 * while an active Manager whose role holds `deal.view` and `quotation.view` at All —
 * every author route (read, submit, notes, candidates, the PDF) goes through `reach()`.
 */
final readonly class ReportAccess
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private UserFactsInterface $users,
        private AuthorizeAction $authorize,
    ) {}

    /** `$lock` holds the report row for the caller's transaction (a write's). */
    public function reach(string $reportId, string $actorId, bool $lock = false): Report
    {
        $report = $this->reports->find($reportId, $lock);

        if ($report === null || $report->authorId !== $actorId || ($report->type === 'monthly' && ! $this->seesTheCompany($actorId))) {
            throw ReportNotFound::of($reportId);
        }

        return $report;
    }

    public function open(string $reportId, ?string $ifMatch, string $actorId): Report
    {
        return self::current($this->reach($reportId, $actorId, true), $ifMatch);
    }

    /**
     * `D-131`: a follow-up or note is written by the author while the report is
     * a draft, returned or missed (`D-135`) — checked under the row lock a submit also takes, so a
     * write racing a submit ends in 409, never in a row the frozen copy missed.
     */
    public function editable(string $reportId, string $actorId): Report
    {
        $report = $this->reach($reportId, $actorId, true);

        if (! $report->isEditable()) {
            throw ReportWriteRefused::notEditable();
        }

        return $report;
    }

    /**
     * `OpenAPI §3.2`: a download never exceeds the view — a recipient reaches what was sent to them only while
     * holding §3.10's `report.view_received` (any scope: `Team` and `Out` choose recipients, `D-138`), so every
     * path through here (the received read, the PDF's status, both downloads) refuses one who lost it.
     */
    public function reachReceived(string $reportId, string $actorId): Report
    {
        $report = $this->reports->find($reportId);

        if ($report === null || ! in_array($actorId, $this->reports->recipientIdsOf($reportId), true) || ! $this->authorize->decide($actorId, 'report', 'view_received')->granted) {
            throw ReportNotFound::of($reportId);
        }

        return $report;
    }

    public function openReceived(string $reportId, ?string $ifMatch, string $actorId): Report
    {
        return self::current($this->reachReceived($reportId, $actorId), $ifMatch);
    }

    /** `D-141`: an active Manager whose role sees every deal and every quotation — the author an RPT-MN opens for, and lists for (`ShowReport::mine`). */
    public function seesTheCompany(string $actorId): bool
    {
        return ($this->users->activeRolesOf([$actorId])[$actorId] ?? null) === Role::Manager->value
            && $this->authorize->decide($actorId, 'deal', 'view', Scope::All)->granted
            && $this->authorize->decide($actorId, 'quotation', 'view', Scope::All)->granted;
    }

    private static function current(Report $report, ?string $ifMatch): Report
    {
        if (ReportEtag::tokenFrom($ifMatch, $report->id) !== $report->versionToken) {
            throw ReportWriteRefused::staleVersion(ReportEtag::of($report));
        }

        return $report;
    }
}
