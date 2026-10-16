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
        /** How many versions were submitted — the highest `report_versions.version`, 0 for a first draft. */
        public int $submittedVersions,
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

    /**
     * The version a read of it shows: the author's draft is the next one, a
     * frozen copy is the latest submitted (spec §4.4 builds the PDF link from it).
     */
    public function shownVersion(bool $frozen): int
    {
        return $this->submittedVersions + ($frozen ? 0 : 1);
    }
}
