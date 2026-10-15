<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;

/**
 * The author's reads: "My reports", and one report with its activity — live
 * on a draft, the frozen copy's once submitted (§11.3) — and its recipients.
 */
final readonly class ShowReport
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private DailyActivity $activity,
        private UserFactsInterface $users,
    ) {}

    /** @return array{items: list<Report>, total: int} */
    public function mine(string $actorId, int $page, int $perPage): array
    {
        return $this->reports->forAuthor($actorId, $page, $perPage);
    }

    /** @return array{report: Report, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>} */
    public function one(string $reportId, string $actorId): array
    {
        $report = $this->access->reach($reportId, $actorId);
        $frozen = $report->isDraft() ? null : $this->reports->latestSnapshot($reportId);

        $ids = $this->reports->recipientIdsOf($reportId);
        $names = $this->users->namesOf($ids);

        /** @var array<string, mixed> $activity the frozen copy holds what `DailyActivity::of()` answered at submit */
        $activity = is_array($frozen['activity'] ?? null) ? $frozen['activity'] : $this->activity->of($report->authorId, $report->periodStart);

        return [
            'report' => $report,
            'activity' => $activity,
            'recipients' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? ''], $ids),
        ];
    }
}
