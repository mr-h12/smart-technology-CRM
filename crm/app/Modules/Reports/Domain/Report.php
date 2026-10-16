<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

/** One `reports` row, as the module reads it back. */
final readonly class Report
{
    public function __construct(
        public string $id,
        public string $code,
        public string $type,
        public string $status,
        public string $authorId,
        public string $periodStart,
        public string $periodEnd,
        public int $versionToken,
        public ?string $submittedAt,
    ) {}

    /** §11.4: the author writes a draft, and a returned report on its way to the next version. */
    public function isEditable(): bool
    {
        return $this->status === 'draft' || $this->status === 'returned';
    }

    /** §11.4, the owner's ruling of 2026-10-16: Acknowledge and Return answer a Submitted or Viewed report. */
    public function awaitsAnswer(): bool
    {
        return $this->status === 'submitted' || $this->status === 'viewed';
    }
}
