<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Listing;

use App\Modules\Admin\Domain\Money\RecordedRate;

/**
 * One page of `§13` screen 5's rate history, plus the six numbers `OpenAPI
 * §4.2` puts in `meta.pagination`.
 *
 * All six are written out rather than left for the client to compute, for the
 * reason Identity's `ReferencePage` gives: a client that has to compute them
 * will compute one of them differently.
 *
 * ── Why `Listing` and not `Money` ──────────────────────────────────────────
 *
 * It began in `Domain\Money` beside the rate it pages, and
 * `CurrencyMatrixDataTest` refused it: that test tokenises every file in the
 * money namespace and forbids `ceil`, `floor`, `intdiv`, `round`, `fdiv`, a
 * float literal and a float cast — `DB-07` *"anywhere near a price"*, enforced
 * by namespace rather than by judgement. `totalPages()` is integer arithmetic
 * over a row count and is not near a price, so the answer was to move the class
 * rather than to weaken the guard or to reach for BCMath to count pages.
 *
 * A blunt guard flagging an innocent class is the guard working. Point 3.4's
 * managed-list listing will land here too.
 */
final readonly class RateHistoryPage
{
    /** @param list<RecordedRate> $rates */
    public function __construct(
        public array $rates,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        // An empty result is one (empty) page, not zero — `page=1` of an empty
        // list is a valid request, and reporting 0 makes `has_next_page` and
        // the SPA's paginator disagree about whether page 1 exists.
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
