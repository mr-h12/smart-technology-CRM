<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;

/**
 * The copy a submit freezes (§11.3), the same whoever submits: `SubmitReport`
 * for the author, `SubmitUnreviewedWeeklyReports` (J-06) for the system.
 * `D-131`: the follow-ups with their customer's name as it is now, and the
 * notes — no row ids. `D-126`/`D-134`/`D-140`: a weekly or monthly report adds its own sections.
 */
final readonly class FrozenCopy
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ShowReport $show,
        private DailyActivity $activity,
    ) {}

    /** @return array<string, mixed> */
    public function of(Report $report): array
    {
        return [
            'follow_ups' => array_map(static fn (array $row): array => array_diff_key($row, ['id' => true]), $this->show->followUpsOf($report->id)),
            'notes' => array_map(static fn (array $note): array => ['body' => $note['body']], $this->reports->rowsOf('note', $report->id)),
            ...($report->hasActivity() ? ['activity' => $this->activity->of($report->authorId, $report->periodStart, $report->periodEnd)] : []),
            ...$this->show->contentOf($report),
        ];
    }
}
