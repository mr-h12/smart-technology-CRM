<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;
use App\Support\Database\UtcAtom;

/**
 * The reads: "My reports" and "Received reports", and one report with its activity (none
 * on RPT-MN, `D-140`), its own sections (`CONTENT_KEYS`), its follow-ups and notes (`D-131`)
 * — live while the author can edit it, the frozen copy's once submitted (§11.3) — and its
 * recipients.
 */
final readonly class ShowReport
{
    /** `D-126`/`D-134` RPT-WK's and `D-140` RPT-MN's own sections, beside the follow-ups and notes. */
    public const CONTENT_KEYS = [
        'weekly' => ['daily_reports', 'purchase_orders_received', 'new_customers'],
        'monthly' => ['deals_by_status', 'quotations'],
    ];

    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private DailyActivity $activity,
        private WeeklyContent $weekly,
        private MonthlyContent $monthly,
        private UserFactsInterface $users,
        private CustomerNamesInterface $customers,
    ) {}

    /** @return array{items: list<Report>, total: int} */
    public function mine(string $actorId, int $page, int $perPage, bool $includeArchived): array
    {
        return $this->reports->forAuthor($actorId, $page, $perPage, $includeArchived);
    }

    /** @return array{items: list<array{report: Report, author_name: string}>, total: int} */
    public function received(string $actorId, int $page, int $perPage, bool $includeArchived): array
    {
        $sent = $this->reports->forRecipient($actorId, $page, $perPage, $includeArchived);
        $names = $this->users->namesOf(array_values(array_unique(array_map(static fn (Report $r): string => $r->authorId, $sent['items']))));

        return [
            'items' => array_map(static fn (Report $r): array => ['report' => $r, 'author_name' => $names[$r->authorId] ?? ''], $sent['items']),
            'total' => $sent['total'],
        ];
    }

    /** @return array{report: Report, version: int, follow_ups: list<array{id?: string, customer_id: string, customer_name: string, note: string}>, notes: list<array{id?: string, body: string}>, comments: list<array{id: string, body: string, author: array{id: string, name: string}, created_at: string}>, activity: array<string, mixed>|null, content: array<string, mixed>, recipients: list<array{id: string, name: string}>} */
    public function one(string $reportId, string $actorId): array
    {
        $report = $this->access->reach($reportId, $actorId);

        return $this->read($report, $report->isEditable() ? null : $this->reports->latestSnapshot($reportId));
    }

    /**
     * A recipient reads the frozen copy (§11.3) and who wrote it.
     *
     * @return array{report: Report, version: int, follow_ups: list<array{id?: string, customer_id: string, customer_name: string, note: string}>, notes: list<array{id?: string, body: string}>, comments: list<array{id: string, body: string, author: array{id: string, name: string}, created_at: string}>, activity: array<string, mixed>|null, content: array<string, mixed>, recipients: list<array{id: string, name: string}>, author_name: string}
     */
    public function oneReceived(string $reportId, string $actorId): array
    {
        $report = $this->access->reachReceived($reportId, $actorId);

        return [
            ...$this->read($report, $this->reports->latestSnapshot($reportId)),
            'author_name' => $this->users->namesOf([$report->authorId])[$report->authorId] ?? '',
        ];
    }

    /**
     * The report's own sections, live (`CONTENT_KEYS`): what a draft shows and a submit freezes.
     *
     * @return array<string, mixed>
     */
    public function contentOf(Report $report): array
    {
        return match ($report->type) {
            'weekly' => $this->weekly->of($report),
            'monthly' => $this->monthly->of($report),
            default => [],
        };
    }

    /**
     * `D-131`'s follow-ups with their customer's name now, as `SubmitReport` freezes them.
     *
     * @return list<array{id: string, customer_id: string, customer_name: string, note: string, followed_up_at: string}>
     */
    public function followUpsOf(string $reportId): array
    {
        $rows = $this->reports->rowsOf('follow_up', $reportId);
        $names = $this->customers->namesOf(array_values(array_unique(array_column($rows, 'customer_id'))));

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'customer_id' => $row['customer_id'],
            'customer_name' => $names[$row['customer_id']] ?? '',
            'note' => $row['note'],
            // `D-133`: the row's first save, carried into the frozen copy at submit.
            'followed_up_at' => UtcAtom::of($row['created_at']),
        ], $rows);
    }

    /**
     * @param  array<string, mixed>|null  $frozen
     * @return array{report: Report, version: int, follow_ups: list<array{id?: string, customer_id: string, customer_name: string, note: string}>, notes: list<array{id?: string, body: string}>, comments: list<array{id: string, body: string, author: array{id: string, name: string}, created_at: string}>, activity: array<string, mixed>|null, content: array<string, mixed>, recipients: list<array{id: string, name: string}>}
     */
    private function read(Report $report, ?array $frozen): array
    {
        $ids = $this->reports->recipientIdsOf($report->id);
        $comments = $this->reports->commentsOf($report->id);
        $names = $this->users->namesOf(array_values(array_unique([...$ids, ...array_column($comments, 'author_id')])));

        /** @var array<string, mixed>|null $activity the frozen copy holds what `DailyActivity::of()` answered at submit; none on RPT-MN (`Report::hasActivity()`) */
        $activity = $report->hasActivity() ? (is_array($frozen['activity'] ?? null) ? $frozen['activity'] : $this->activity->of($report->authorId, $report->periodStart, $report->periodEnd)) : null;

        if ($frozen === null) {
            $followUps = $this->followUpsOf($report->id);
            /** @var list<array{id?: string, body: string}> $notes */
            $notes = $this->reports->rowsOf('note', $report->id);
        } else {
            /** @var list<array{id?: string, customer_id: string, customer_name: string, note: string}> $followUps `SubmitReport` froze this shape; a copy older than `D-131` has none */
            $followUps = is_array($frozen['follow_ups'] ?? null) ? $frozen['follow_ups'] : [];
            $frozenNotes = $frozen['notes'] ?? null;
            // A copy older than `D-131` froze one notes string: it reads as one note.
            /** @var list<array{id?: string, body: string}> $notes */
            $notes = is_string($frozenNotes) ? [['body' => $frozenNotes]] : (is_array($frozenNotes) ? $frozenNotes : []);
        }

        return [
            'report' => $report,
            'version' => $report->shownVersion($frozen !== null),
            'follow_ups' => $followUps,
            'notes' => $notes,
            // `D-127`: live whatever the status — a comment never freezes with a version.
            'comments' => array_map(static fn (array $c): array => [
                'id' => $c['id'], 'body' => $c['body'],
                'author' => ['id' => $c['author_id'], 'name' => $names[$c['author_id']] ?? ''],
                'created_at' => $c['created_at'],
            ], $comments),
            'activity' => $activity,
            // The type's own sections — the frozen copy's once submitted.
            'content' => $frozen === null ? $this->contentOf($report) : array_intersect_key($frozen, array_flip(self::CONTENT_KEYS[$report->type] ?? [])),
            'recipients' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? ''], $ids),
        ];
    }
}
