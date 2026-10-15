<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;

/**
 * The reads: "My reports" and "Received reports", and one report with its activity — live
 * while the author can edit it, the frozen copy's once submitted (§11.3) — and its recipients.
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

    /** @return array{items: list<array{report: Report, author_name: string}>, total: int} */
    public function received(string $actorId, int $page, int $perPage): array
    {
        $sent = $this->reports->forRecipient($actorId, $page, $perPage);
        $names = $this->users->namesOf(array_values(array_unique(array_map(static fn (Report $r): string => $r->authorId, $sent['items']))));

        return [
            'items' => array_map(static fn (Report $r): array => ['report' => $r, 'author_name' => $names[$r->authorId] ?? ''], $sent['items']),
            'total' => $sent['total'],
        ];
    }

    /** @return array{report: Report, notes: ?string, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>} */
    public function one(string $reportId, string $actorId): array
    {
        $report = $this->access->reach($reportId, $actorId);

        return $this->read($report, $report->isEditable() ? null : $this->reports->latestSnapshot($reportId), $report->notes);
    }

    /**
     * A recipient reads the frozen copy — its notes too (§11.3) — and who wrote it.
     *
     * @return array{report: Report, notes: ?string, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>, author_name: string}
     */
    public function oneReceived(string $reportId, string $actorId): array
    {
        $report = $this->access->reachReceived($reportId, $actorId);
        $frozen = $this->reports->latestSnapshot($reportId);
        $notes = is_string($frozen['notes'] ?? null) ? $frozen['notes'] : null;

        return [
            ...$this->read($report, $frozen, $notes),
            'author_name' => $this->users->namesOf([$report->authorId])[$report->authorId] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $frozen
     * @return array{report: Report, notes: ?string, activity: array<string, mixed>, recipients: list<array{id: string, name: string}>}
     */
    private function read(Report $report, ?array $frozen, ?string $notes): array
    {
        $ids = $this->reports->recipientIdsOf($report->id);
        $names = $this->users->namesOf($ids);

        /** @var array<string, mixed> $activity the frozen copy holds what `DailyActivity::of()` answered at submit */
        $activity = is_array($frozen['activity'] ?? null) ? $frozen['activity'] : $this->activity->of($report->authorId, $report->periodStart);

        return [
            'report' => $report,
            'notes' => $notes,
            'activity' => $activity,
            'recipients' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? ''], $ids),
        ];
    }
}
