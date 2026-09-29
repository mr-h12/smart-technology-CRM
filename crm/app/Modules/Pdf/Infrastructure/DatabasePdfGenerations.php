<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Pdf\Domain\Contracts\PdfGenerationsInterface;
use App\Modules\Pdf\Domain\Generation\PdfGeneration;
use App\Modules\Pdf\Domain\Generation\PdfGenerationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;
use UnexpectedValueException;

/**
 * `pdf_generations` through the query builder — no Eloquent model, so
 * `D-77`'s Contract/Driver split is still not forced on `Pdf` (Point 1.0).
 */
final readonly class DatabasePdfGenerations implements PdfGenerationsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function latestFor(string $quotationId): ?PdfGeneration
    {
        return self::hydrate($this->newestOf($quotationId)->first());
    }

    public function latestFileIdFor(string $quotationId): ?string
    {
        $fileId = $this->newestOf($quotationId)
            ->where('status', PdfGenerationStatus::Completed->value)
            ->value('file_id');

        return is_string($fileId) ? $fileId : null;
    }

    public function find(string $id): ?PdfGeneration
    {
        return self::hydrate($this->live()->where('id', $id)->first());
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

    private function live(): Builder
    {
        return $this->connection->table('pdf_generations')->whereNull('deleted_at');
    }

    /** The one row a write may touch: still `queued`, so a final generation is never rewritten. */
    private function queued(string $id): Builder
    {
        return $this->live()->where('id', $id)->where('status', PdfGenerationStatus::Queued->value);
    }

    /**
     * `DB-02`'s audit columns for a job's write: it has no request to take an
     * actor from, so it acts for whoever asked (3.5 records them as `created_by`).
     *
     * @return array<string, mixed>
     */
    private function touched(): array
    {
        return ['updated_at' => now(), 'updated_by' => $this->connection->raw('created_by')];
    }

    /** The live rows of one quotation, newest first — `pdf_generations_latest`'s order. */
    private function newestOf(string $quotationId): Builder
    {
        return $this->live()
            ->where('quotation_id', $quotationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private static function hydrate(mixed $row): ?PdfGeneration
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        $finishedAt = self::nullableText($row, 'finished_at');

        return new PdfGeneration(
            id: self::text($row, 'id'),
            quotationId: self::text($row, 'quotation_id'),
            locale: self::text($row, 'locale'),
            status: PdfGenerationStatus::from(self::text($row, 'status')),
            fileId: self::nullableText($row, 'file_id'),
            requestedBy: self::nullableText($row, 'created_by'),
            requestedAt: self::instant(self::text($row, 'created_at')),
            finishedAt: $finishedAt === null ? null : self::instant($finishedAt),
            failureReason: self::nullableText($row, 'failure_reason'),
        );
    }

    /** In UTC whatever the session's zone, as the Eloquent-read payloads are. */
    private static function instant(string $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A `NOT NULL` column, refused loudly if the driver hands back anything but
     * text — `EloquentQuotationDirectory::text()`'s reasoning.
     */
    private static function text(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        if (! is_string($value)) {
            throw new UnexpectedValueException("pdf_generations.{$column} did not come back as text.");
        }

        return $value;
    }

    private static function nullableText(stdClass $row, string $column): ?string
    {
        return ($row->{$column} ?? null) === null ? null : self::text($row, $column);
    }
}
