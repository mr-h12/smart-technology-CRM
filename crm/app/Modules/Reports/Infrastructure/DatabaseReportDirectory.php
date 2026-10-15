<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure;

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
final readonly class DatabaseReportDirectory implements ReportDirectoryInterface
{
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

    public function find(string $reportId): ?Report
    {
        if (! Str::isUuid($reportId)) {
            return null;
        }

        /**
         * @var object{
         *     id: string, code: string, type: string, status: string, author_id: string,
         *     period_start: string, period_end: string, notes: ?string, version_token: int,
         *     submitted_at: ?string
         * }|null $row
         */
        $row = $this->connection->table('reports')->whereNull('deleted_at')->where('id', $reportId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function forAuthor(string $authorId, int $page, int $perPage): array
    {
        $query = $this->connection->table('reports')->whereNull('deleted_at')->where('author_id', $authorId);
        $total = (clone $query)->count();

        /**
         * @var list<object{
         *     id: string, code: string, type: string, status: string, author_id: string,
         *     period_start: string, period_end: string, notes: ?string, version_token: int,
         *     submitted_at: ?string
         * }> $rows
         */
        $rows = $query->orderByDesc('period_start')->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)->get()->all();

        return ['items' => array_map(self::hydrate(...), $rows), 'total' => $total];
    }

    public function updateNotes(string $reportId, int $versionToken, ?string $notes, string $actorId): bool
    {
        return $this->connection->table('reports')
            ->where('id', $reportId)
            ->where('version_token', $versionToken)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->update([
                'notes' => $notes,
                'version_token' => $versionToken + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]) === 1;
    }

    public function submit(string $reportId, int $versionToken, array $snapshot, array $recipientIds, string $actorId): bool
    {
        $moved = $this->connection->table('reports')
            ->where('id', $reportId)
            ->where('version_token', $versionToken)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'version_token' => $versionToken + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]) === 1;

        if (! $moved) {
            return false;
        }

        $latest = $this->connection->table('report_versions')->where('report_id', $reportId)->max('version');
        $version = (is_numeric($latest) ? (int) $latest : 0) + 1;

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

        $this->connection->table('report_recipients')->insert(array_map(static fn (string $userId): array => [
            'id' => Str::uuid7()->toString(),
            'report_id' => $reportId,
            'user_id' => $userId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $recipientIds));

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

    /**
     * The row's shape, spelled out because the query builder answers `mixed`
     * per property; `ReportsMigrationTest` is what holds the table to it.
     *
     * @param  object{
     *     id: string, code: string, type: string, status: string, author_id: string,
     *     period_start: string, period_end: string, notes: ?string, version_token: int,
     *     submitted_at: ?string
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
            notes: $row->notes,
            versionToken: $row->version_token,
            submittedAt: $row->submitted_at === null ? null : (new \DateTimeImmutable($row->submitted_at))
                ->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        );
    }
}
