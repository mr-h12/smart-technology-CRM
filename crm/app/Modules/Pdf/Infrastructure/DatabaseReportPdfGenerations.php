<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Pdf\Domain\Contracts\ReportPdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use App\Modules\Pdf\Domain\Generation\ReportPdfGeneration;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * `report_pdf_generations` through the query builder, on `DatabasePdfGenerations`'
 * terms: every job write touches only a row still `queued`, and acts for whoever
 * the row names as `created_by` (`DB-02`).
 */
final readonly class DatabaseReportPdfGenerations implements ReportPdfGenerationsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function queue(string $reportId, int $version, string $actorId): ?string
    {
        $id = Str::uuid7()->toString();

        // `ON CONFLICT DO NOTHING` on `(report_id, version)`: a repeat is a no-op.
        $inserted = $this->connection->table('report_pdf_generations')->insertOrIgnore([
            'id' => $id,
            'report_id' => $reportId,
            'version' => $version,
            'status' => PdfGenerationStatus::Queued->value,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1 ? $id : null;
    }

    public function find(string $id): ?ReportPdfGeneration
    {
        return Str::isUuid($id) ? self::hydrate($this->live()->where('id', $id)->first()) : null;
    }

    public function forVersion(string $reportId, int $version): ?ReportPdfGeneration
    {
        return Str::isUuid($reportId)
            ? self::hydrate($this->live()->where('report_id', $reportId)->where('version', $version)->first())
            : null;
    }

    public function recordAttempt(string $id, int $attempt): void
    {
        $this->queued($id)->update(['attempts' => $attempt] + $this->touched());
    }

    public function attachFile(string $id, string $fileId): void
    {
        $this->queued($id)->update(['file_id' => $fileId] + $this->touched());
    }

    public function complete(string $id): void
    {
        $this->queued($id)->update(['status' => PdfGenerationStatus::Completed->value, 'finished_at' => now()] + $this->touched());
    }

    public function fail(string $id, string $reason): void
    {
        $this->queued($id)->update([
            'status' => PdfGenerationStatus::Failed->value,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ] + $this->touched());
    }

    public function requeue(string $id, string $actorId): bool
    {
        return $this->live()->where('id', $id)->where('status', PdfGenerationStatus::Failed->value)->update([
            'status' => PdfGenerationStatus::Queued->value,
            'failure_reason' => null,
            'finished_at' => null,
            'updated_at' => now(),
            'updated_by' => $actorId,
        ]) === 1;
    }

    private function live(): Builder
    {
        return $this->connection->table('report_pdf_generations')->whereNull('deleted_at');
    }

    private function queued(string $id): Builder
    {
        return $this->live()->where('id', $id)->where('status', PdfGenerationStatus::Queued->value);
    }

    /** @return array<string, mixed> */
    private function touched(): array
    {
        return ['updated_at' => now(), 'updated_by' => $this->connection->raw('created_by')];
    }

    private static function hydrate(mixed $row): ?ReportPdfGeneration
    {
        if (! is_object($row)) {
            return null;
        }

        /** @var object{id: string, report_id: string, version: int, status: string, file_id: ?string, created_by: ?string, finished_at: ?string, failure_reason: ?string} $row */
        return new ReportPdfGeneration(
            id: $row->id,
            reportId: $row->report_id,
            version: $row->version,
            status: PdfGenerationStatus::from($row->status),
            fileId: $row->file_id,
            requestedBy: $row->created_by,
            finishedAt: $row->finished_at === null ? null : (new DateTimeImmutable($row->finished_at))->setTimezone(new DateTimeZone('UTC')),
            failureReason: $row->failure_reason,
        );
    }
}
