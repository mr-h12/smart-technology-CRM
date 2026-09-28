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
        $row = $this->newestOf($quotationId)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $finishedAt = self::nullableText($row, 'finished_at');

        return new PdfGeneration(
            id: self::text($row, 'id'),
            status: PdfGenerationStatus::from(self::text($row, 'status')),
            requestedAt: self::instant(self::text($row, 'created_at')),
            finishedAt: $finishedAt === null ? null : self::instant($finishedAt),
            failureReason: self::nullableText($row, 'failure_reason'),
        );
    }

    public function latestFileIdFor(string $quotationId): ?string
    {
        $fileId = $this->newestOf($quotationId)
            ->where('status', PdfGenerationStatus::Completed->value)
            ->value('file_id');

        return is_string($fileId) ? $fileId : null;
    }

    /** The live rows of one quotation, newest first — `pdf_generations_latest`'s order. */
    private function newestOf(string $quotationId): Builder
    {
        return $this->connection->table('pdf_generations')
            ->where('quotation_id', $quotationId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
