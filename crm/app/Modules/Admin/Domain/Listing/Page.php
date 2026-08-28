<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Listing;

/**
 * One page of any Admin listing, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * All six are written out rather than left for the client to compute, for the
 * reason Identity's `ReferencePage` gives: a client that has to compute them
 * will compute one of them differently.
 *
 * ── Why `Listing` and not `Money` ──────────────────────────────────────────
 *
 * It began in `Domain\Money` beside the FX rate it paged, and
 * `CurrencyMatrixDataTest` refused it: that test tokenises every file in the
 * money namespace and forbids `ceil`, `floor`, `intdiv`, `round`, `fdiv`, a
 * float literal and a float cast — `DB-07` *"anywhere near a price"*, enforced
 * by namespace rather than by judgement. `totalPages()` is integer arithmetic
 * over a row count and is not near a price, so the answer was to move the class
 * rather than to weaken the guard or to count pages in BCMath.
 *
 * ── Why it is generic ──────────────────────────────────────────────────────
 *
 * Point 3.3 shipped it as `RateHistoryPage`, and Point 3.4 needs the identical
 * arithmetic for `enum_lists`. Identity has this shape twice already — `UserPage`
 * and `ReferencePage` — and its own docblock says the third copy is where
 * `max(1, ...)` eventually gets written differently. Generalising one point-old
 * code inside its own module is not the boundary change `CLAUDE.md` reserves
 * for its own point: same layer, same module, same namespace, and
 * `FxRateEndpointTest`'s 28 cases are the safety net.
 *
 * @template TItem
 */
final readonly class Page
{
    /** @param list<TItem> $items */
    public function __construct(
        public array $items,
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

    /**
     * The `meta.pagination` block, exactly as `OpenAPI §4.2` draws it.
     *
     * Assembled here rather than in each controller because §4.2 names six keys
     * and a controller that spelled them out would be a sixth place for one of
     * them to be misspelled — which a client discovers, not a test.
     *
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}
     */
    public function meta(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages(),
            'has_next_page' => $this->hasNextPage(),
            'has_previous_page' => $this->hasPreviousPage(),
        ];
    }
}
