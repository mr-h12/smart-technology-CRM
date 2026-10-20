<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Contracts\SubmittedReportsInterface;
use App\Modules\Reports\Domain\ReportNotFound;

/**
 * `D-132`, spec §4.1: the Pdf module's read of a submitted version. In
 * `Application` because `mayOpen()` asks `ReportAccess` itself, so the reach
 * rule (author, or a live recipient) is never copied.
 */
final readonly class SubmittedReports implements SubmittedReportsInterface
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private UserFactsInterface $users,
    ) {}

    public function version(string $reportId, int $version): ?array
    {
        $report = $this->reports->find($reportId);
        $frozen = $report === null ? null : $this->reports->versionOf($reportId, $version);

        if ($report === null || $frozen === null) {
            return null;
        }

        $snapshot = $frozen['snapshot'];

        /** @var array{counts: array<string, int>, sections: array<string, list<array<string, string|int|null>>>} $activity `DailyActivity::of()`'s answer, frozen at submit */
        $activity = $snapshot['activity'] ?? ['counts' => [], 'sections' => []];
        /** @var list<array{customer_id: string, customer_name: string, note: string, followed_up_at?: string}> $followUps */
        $followUps = $snapshot['follow_ups'] ?? [];
        /** @var list<array{body: string}> $notes */
        $notes = $snapshot['notes'] ?? [];
        /** @var array{daily_reports?: list<array{code: string, period_start: string, status: string}>, purchase_orders_received?: int, new_customers?: array{count: int, items: list<array{customer_id: string, name: string}>}, deals_by_status?: list<array{status: string, count: int}>, quotations?: array{sent: int, won: int, lost: int}, money?: list<array{currency: string, won_value: string, cost?: string, profit?: string}>} $content `WeeklyContent::of()`'s or `MonthlyContent::of()`'s answer, frozen at submit (`D-126`, `D-134`, `D-140`, `D-141`) */
        $content = array_intersect_key($snapshot, array_flip(ShowReport::CONTENT_KEYS[$report->type] ?? []));

        $read = [
            'report_id' => $report->id,
            'version' => $version,
            'code' => $report->code,
            'type' => $report->type,
            'author_id' => $report->authorId,
            'author_name' => $this->users->namesOf([$report->authorId])[$report->authorId] ?? '',
            'period_start' => $report->periodStart,
            'period_end' => $report->periodEnd,
            'submitted_at' => $frozen['submitted_at'],
            // ponytail: the shapes `SubmitReport` freezes since `D-131`; slice 4 renders only versions submitted after it (`D-132`, no back-fill).
            'follow_ups' => $followUps,
            'notes' => $notes,
            'activity' => $activity,
            ...$content,
        ];

        if (! $report->hasActivity()) {
            unset($read['activity']);
        }

        return $read;
    }

    public function mayOpen(string $reportId, string $actorId): bool
    {
        foreach ([$this->access->reach(...), $this->access->reachReceived(...)] as $reach) {
            try {
                $reach($reportId, $actorId);

                return true;
            } catch (ReportNotFound) {
            }
        }

        return false;
    }
}
