<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

use DateTimeImmutable;
use DateTimeZone;

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
        /** `D-135`: submitted after it was marked Missed; once true it stays true. */
        public bool $submittedLate = false,
        /** `D-136`: J-06 submitted it, not its author; cleared when a person submits again. */
        public bool $notReviewed = false,
    ) {}

    /** §11.4: the author writes a draft, and a returned report on its way to the next version; `D-135`: and a Missed one, to submit it late. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'returned', 'missed'], true);
    }

    /** §11.4, the owner's ruling of 2026-10-16: Acknowledge and Return answer a Submitted or Viewed report. */
    public function awaitsAnswer(): bool
    {
        return $this->status === 'submitted' || $this->status === 'viewed';
    }

    /** `D-140`: RPT-MN is company-wide — it carries no author activity; every other report does. */
    public function hasActivity(): bool
    {
        return $this->type !== 'monthly';
    }

    /**
     * The version a read of it shows: the author's draft is the next one, a
     * frozen copy is the latest submitted (spec §4.4 builds the PDF link from it).
     */
    public function shownVersion(bool $frozen): int
    {
        return $this->submittedVersions + ($frozen ? 0 : 1);
    }

    /**
     * The period's company-time days as UTC instants, `[first day 00:00, the day after the last)`:
     * stored instants are UTC and the query builder formats a bound date without its zone.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function utcPeriod(string $zone): array
    {
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($this->periodStart.' 00:00:00', new DateTimeZone($zone));
        $to = (new DateTimeImmutable($this->periodEnd.' 00:00:00', new DateTimeZone($zone)))->modify('+1 day');

        return [$from->setTimezone($utc), $to->setTimezone($utc)];
    }
}
