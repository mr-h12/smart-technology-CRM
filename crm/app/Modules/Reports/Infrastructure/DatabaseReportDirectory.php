<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure;

use App\Modules\Reports\Domain\Contracts\CustomerFollowUpsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\Report;
use App\Support\Database\DocumentNumberAllocator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * `reports`, `report_versions` and `report_recipients` through the query
 * builder. A persistence adapter: the use cases own the transactions and the
 * audit entries (`AUD-01`).
 */
final readonly class DatabaseReportDirectory implements CustomerFollowUpsInterface, ReportDirectoryInterface
{
    /** `D-131`: each row kind's table, and the columns it writes and reads. */
    private const ROWS = ['follow_up' => 'report_follow_ups', 'note' => 'report_notes'];

    private const FIELDS = ['follow_up' => ['customer_id', 'note'], 'note' => ['body']];

    /** `Report::$submittedVersions`, read beside each row. */
    private const SUBMITTED_VERSIONS = 'SELECT COALESCE(MAX(v.version), 0) FROM report_versions v WHERE v.report_id = reports.id';

    public function __construct(private ConnectionInterface $connection) {}

    /** @return array{id: string, code: string} */
    public function createDraft(string $type, string $codePrefix, string $authorId, string $periodStart, string $periodEnd): array
    {
        $id = Str::uuid7()->toString();
        $code = (new DocumentNumberAllocator($this->connection, $codePrefix))->next();

        $this->connection->table('reports')->insert([
            'id' => $id,
            'code' => $code,
            'type' => $type,
            'status' => 'draft',
            'author_id' => $authorId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'created_by' => $authorId,
            'updated_by' => $authorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'code' => $code];
    }

    public function find(string $reportId, bool $lock = false): ?Report
    {
        if (! Str::isUuid($reportId)) {
            return null;
        }

        /**
         * @var object{
         *     id: string, code: string, type: string, status: string, author_id: string,
         *     period_start: string, period_end: string, version_token: int,
         *     submitted_at: ?string, submitted_versions: int, submitted_late: bool, not_reviewed: bool
         * }|null $row
         */
        $row = $this->connection->table('reports')->select('reports.*')->selectSub(self::SUBMITTED_VERSIONS, 'submitted_versions')
            ->whereNull('deleted_at')->where('id', $reportId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function forAuthor(string $authorId, int $page, int $perPage): array
    {
        $query = $this->connection->table('reports')->whereNull('deleted_at')->where('author_id', $authorId);
        $total = (clone $query)->count();

        /**
         * @var list<object{
         *     id: string, code: string, type: string, status: string, author_id: string,
         *     period_start: string, period_end: string, version_token: int,
         *     submitted_at: ?string, submitted_versions: int, submitted_late: bool, not_reviewed: bool
         * }> $rows
         */
        $rows = $query->select('reports.*')->selectSub(self::SUBMITTED_VERSIONS, 'submitted_versions')
            ->orderByDesc('period_start')->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)->get()->all();

        return ['items' => array_map(self::hydrate(...), $rows), 'total' => $total];
    }

    public function rowsOf(string $kind, string $reportId): array
    {
        /** @var list<array<string, string>> $rows the columns are non-null uuid and text */
        $rows = $this->connection->table(self::ROWS[$kind])
            ->where('report_id', $reportId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            // `D-133`: a follow-up's first save travels into the frozen copy.
            ->get(['id', ...self::FIELDS[$kind], ...($kind === 'follow_up' ? ['created_at'] : [])])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return $rows;
    }

    public function addRow(string $kind, string $reportId, array $fields, string $actorId): string
    {
        $id = Str::uuid7()->toString();

        $this->connection->table(self::ROWS[$kind])->insert([
            ...$fields,
            'id' => $id,
            'report_id' => $reportId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function findRow(string $kind, string $reportId, string $rowId): ?array
    {
        if (! Str::isUuid($rowId)) {
            return null;
        }

        $row = $this->connection->table(self::ROWS[$kind])
            ->where('id', $rowId)
            ->where('report_id', $reportId)
            ->whereNull('deleted_at')
            ->first(['id', ...self::FIELDS[$kind]]);

        /** @var array<string, string>|null */
        return $row === null ? null : (array) $row;
    }

    public function updateRow(string $kind, string $rowId, array $fields, string $actorId): void
    {
        $this->connection->table(self::ROWS[$kind])->where('id', $rowId)
            ->update([...$fields, 'updated_by' => $actorId, 'updated_at' => now()]);
    }

    public function removeRow(string $kind, string $rowId, string $actorId): void
    {
        $this->connection->table(self::ROWS[$kind])->where('id', $rowId)
            ->update(['deleted_at' => now(), 'updated_by' => $actorId, 'updated_at' => now()]);
    }

    public function submit(string $reportId, int $versionToken, array $snapshot, array $recipientIds, ?string $actorId, bool $notReviewed = false): bool
    {
        $moved = $this->connection->table('reports')
            ->where('id', $reportId)
            ->where('version_token', $versionToken)
            ->whereIn('status', ['draft', 'returned', 'missed'])
            ->whereNull('deleted_at')
            ->update([
                'status' => 'submitted',
                // `D-135`: a Missed report submitted is late for good, even if returned and resubmitted.
                'submitted_late' => $this->connection->raw("submitted_late OR status = 'missed'"),
                'not_reviewed' => $notReviewed,
                'submitted_at' => now(),
                'version_token' => $versionToken + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]) === 1;

        if (! $moved) {
            return false;
        }

        $version = $this->latestVersion($reportId) + 1;

        $this->connection->table('report_versions')->insert([
            'id' => Str::uuid7()->toString(),
            'report_id' => $reportId,
            'version' => $version,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->connection->table('report_recipients')
            ->where('report_id', $reportId)
            ->whereNull('deleted_at')
            ->whereNotIn('user_id', $recipientIds)
            ->update(['deleted_at' => now(), 'updated_by' => $actorId, 'updated_at' => now()]);

        $kept = $this->recipientIdsOf($reportId);

        $this->connection->table('report_recipients')->insert(array_map(static fn (string $userId): array => [
            'id' => Str::uuid7()->toString(),
            'report_id' => $reportId,
            'user_id' => $userId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_values(array_diff($recipientIds, $kept))));

        return true;
    }

    /** @return array<string, mixed>|null */
    public function latestSnapshot(string $reportId): ?array
    {
        $snapshot = $this->connection->table('report_versions')
            ->where('report_id', $reportId)
            ->whereNull('deleted_at')
            ->orderByDesc('version')
            ->value('snapshot');

        if (! is_string($snapshot)) {
            return null;
        }

        $decoded = json_decode($snapshot, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded `SubmitReport` froze an object */
        return $decoded;
    }

    public function versionOf(string $reportId, int $version): ?array
    {
        if (! Str::isUuid($reportId)) {
            return null;
        }

        $row = $this->connection->table('report_versions')
            ->where('report_id', $reportId)
            ->where('version', $version)
            ->whereNull('deleted_at')
            ->first(['snapshot', 'created_at']);
        $snapshot = is_object($row) && is_string($row->snapshot ?? null) ? json_decode($row->snapshot, true) : null;

        if (! is_array($snapshot) || ! is_string($row->created_at ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $snapshot `SubmitReport` froze an object */
        return [
            'snapshot' => $snapshot,
            'submitted_at' => (new \DateTimeImmutable($row->created_at))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    public function recipientIdsOf(string $reportId): array
    {
        /** @var list<string> $ids `user_id` is a non-null uuid column */
        $ids = $this->connection->table('report_recipients')
            ->where('report_id', $reportId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('user_id')
            ->all();

        return $ids;
    }

    public function forRecipient(string $userId, int $page, int $perPage): array
    {
        $query = $this->connection->table('reports')
            ->join('report_recipients', 'report_recipients.report_id', '=', 'reports.id')
            ->where('report_recipients.user_id', $userId)
            ->whereNull('report_recipients.deleted_at')
            ->whereNull('reports.deleted_at');
        $total = (clone $query)->count();

        /**
         * @var list<object{
         *     id: string, code: string, type: string, status: string, author_id: string,
         *     period_start: string, period_end: string, version_token: int,
         *     submitted_at: ?string, submitted_versions: int, submitted_late: bool, not_reviewed: bool
         * }> $rows
         */
        $rows = $query->select('reports.*')->selectSub(self::SUBMITTED_VERSIONS, 'submitted_versions')
            ->orderByDesc('reports.period_start')->orderByDesc('reports.submitted_at')->orderByDesc('reports.id')
            ->forPage($page, $perPage)->get()->all();

        return ['items' => array_map(self::hydrate(...), $rows), 'total' => $total];
    }

    public function receive(string $reportId, int $versionToken, string $status, ?string $returnReason, string $actorId): bool
    {
        $moved = $this->connection->table('reports')
            ->where('id', $reportId)
            ->where('version_token', $versionToken)
            ->whereNull('deleted_at')
            ->update([
                'status' => $status,
                'version_token' => $versionToken + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]) === 1;

        if (! $moved) {
            return false;
        }

        // `viewed` / `acknowledged` / `returned` name their own `_by` and `_at` columns.
        $this->connection->table('report_versions')
            ->where('report_id', $reportId)
            ->where('version', $this->latestVersion($reportId))
            ->update([
                $status.'_by' => $actorId,
                $status.'_at' => now(),
                ...($status === 'returned' ? ['return_reason' => $returnReason] : []),
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        return true;
    }

    /**
     * The row's shape, spelled out because the query builder answers `mixed`
     * per property; `ReportsMigrationTest` is what holds the table to it.
     *
     * @param  object{
     *     id: string, code: string, type: string, status: string, author_id: string,
     *     period_start: string, period_end: string, version_token: int,
     *     submitted_at: ?string, submitted_versions: int, submitted_late: bool, not_reviewed: bool
     * }  $row
     */
    private static function hydrate(object $row): Report
    {
        return new Report(
            id: $row->id,
            code: $row->code,
            type: $row->type,
            status: $row->status,
            authorId: $row->author_id,
            periodStart: $row->period_start,
            periodEnd: $row->period_end,
            versionToken: $row->version_token,
            submittedAt: $row->submitted_at === null ? null : (new \DateTimeImmutable($row->submitted_at))
                ->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            submittedVersions: $row->submitted_versions,
            submittedLate: $row->submitted_late,
            notReviewed: $row->not_reviewed,
        );
    }

    public function ofCustomer(string $customerId): array
    {
        // ponytail: scans every report's latest snapshot (no index on the JSONB); a GIN index on `snapshot` if this gets slow.
        /** @var list<object{note: string, author_id: string, followed_up_at: string}> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT f->>'note' AS note, latest.author_id,
                   COALESCE((f->>'followed_up_at')::timestamptz, latest.created_at AT TIME ZONE 'UTC') AS followed_up_at
            FROM (
                SELECT DISTINCT ON (v.report_id) v.snapshot, v.created_at, r.author_id
                FROM report_versions v JOIN reports r ON r.id = v.report_id
                WHERE v.deleted_at IS NULL AND r.deleted_at IS NULL
                ORDER BY v.report_id, v.version DESC
            ) latest
            CROSS JOIN LATERAL jsonb_array_elements(COALESCE(latest.snapshot->'follow_ups', '[]'::jsonb)) f
            WHERE f->>'customer_id' = ?
            ORDER BY 3 DESC
            SQL, [$customerId]);

        return array_map(static fn (object $row): array => [
            'note' => $row->note,
            'author_id' => $row->author_id,
            'followed_up_at' => (new \DateTimeImmutable($row->followed_up_at))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ], $rows);
    }

    public function markMissed(string $goLive, string $today): array
    {
        // `D-125`: Friday (5) and Saturday (6) are not working days.
        $rows = $this->connection->select(
            "UPDATE reports SET status = 'missed', version_token = version_token + 1, updated_by = NULL, updated_at = now()
             WHERE type = 'daily' AND status = 'draft' AND deleted_at IS NULL
               AND period_start < ? AND period_start >= ? AND EXTRACT(DOW FROM period_start) NOT IN (5, 6)
             RETURNING id",
            [$today, $goLive],
        );

        $ids = [];
        foreach ($rows as $row) {
            if ($row instanceof \stdClass && is_string($row->id)) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    public function weeklyAuthorsOf(string $periodStart): array
    {
        /** @var list<string> $ids `author_id` is a non-null uuid column */
        $ids = $this->connection->table('reports')->where('type', 'weekly')->where('period_start', $periodStart)->pluck('author_id')->all();

        return $ids;
    }

    public function dailyReportsOf(string $authorId, string $from, string $to): array
    {
        /** @var list<array{code: string, period_start: string, status: string}> $rows */
        $rows = $this->connection->table('reports')
            ->where('type', 'daily')->where('author_id', $authorId)->whereNull('deleted_at')
            ->whereBetween('period_start', [$from, $to])
            ->orderBy('period_start')->orderBy('code')
            ->get(['code', 'period_start', 'status'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return $rows;
    }

    public function staleWeeklyDraftIds(\DateTimeInterface $createdBefore): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->table('reports')
            ->where('type', 'weekly')->where('status', 'draft')->whereNull('deleted_at')
            ->where('created_at', '<', $createdBefore)
            ->orderBy('created_at')->orderBy('id')
            ->pluck('id')->all();

        return $ids;
    }

    /** The report's highest version number; 0 when it has none. */
    private function latestVersion(string $reportId): int
    {
        $latest = $this->connection->table('report_versions')->where('report_id', $reportId)->max('version');

        return is_numeric($latest) ? (int) $latest : 0;
    }
}
