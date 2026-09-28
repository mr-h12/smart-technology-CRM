<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Generation;

use DateTimeImmutable;

/**
 * One `pdf_generations` row (Point 3.3) as 4.2 reads it: a request to render
 * one quotation's customer PDF. `id` is the `job_id` `OpenAPI §4.3` hands
 * back; `finishedAt` is set once the render completed or gave up.
 */
final readonly class PdfGeneration
{
    public function __construct(
        public string $id,
        public PdfGenerationStatus $status,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?string $failureReason,
    ) {}

    /** When the render completed — never for a failure, which only finished. */
    public function completedAt(): ?DateTimeImmutable
    {
        return $this->status === PdfGenerationStatus::Completed ? $this->finishedAt : null;
    }
}
