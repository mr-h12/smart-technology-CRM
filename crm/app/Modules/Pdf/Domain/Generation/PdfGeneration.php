<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Generation;

use DateTimeImmutable;

/**
 * One `pdf_generations` row (Point 3.3): a request to render one quotation's
 * customer PDF in one language (Q15). `id` is the `job_id` `OpenAPI §4.3`
 * hands back; `fileId` is set once the render is stored (3.4), `finishedAt`
 * once it completed or gave up; `requestedBy` is whoever asked, and the job
 * acts for them.
 */
final readonly class PdfGeneration
{
    public function __construct(
        public string $id,
        public string $quotationId,
        public string $locale,
        public PdfGenerationStatus $status,
        public ?string $fileId,
        public ?string $requestedBy,
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
