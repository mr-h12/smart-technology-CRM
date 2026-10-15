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
        public ?string $notes,
        public int $versionToken,
        public ?string $submittedAt,
    ) {}

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
