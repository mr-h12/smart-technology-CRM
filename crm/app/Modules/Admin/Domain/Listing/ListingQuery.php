<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for every paginated Admin listing, parsed
 * once.
 *
 * ── Why these listings are paginated at all ────────────────────────────────
 *
 * Because they are the collections in Module 2 that genuinely have no ceiling.
 * `GET /settings`, `GET /system-limits` and `GET /currencies` are each bounded
 * by an enum and return an object rather than a list. An FX rate history is
 * append-only by `AP-06` and grows for as long as the business prices anything;
 * `enum_lists` is `DB-05`'s *"extendable"* and grows every time somebody adds a
 * sector. §4.2 leaves no room anyway: *"Every list endpoint is paginated; an
 * endpoint must **never** return an unbounded collection."*
 *
 * ── No filters, and one order per resource ─────────────────────────────────
 *
 * §6.2 allows *"only fields explicitly declared for that resource"*, and these
 * resources declare none — so `filter[...]` and `sort` are refused rather than
 * ignored, which is the behaviour §6.2 actually names. A
 * `filter[from_currency]` on the rate history is the obvious next one and is
 * deliberately **not** invented: §13 screen 5 says *"rate history"* and stops.
 *
 * The order is not a parameter here; each repository documents its own, because
 * §6.2 requires the default to be documented rather than chosen at the call
 * site. Newest-first for a rate history, `(position, code)` for a managed list.
 *
 * The `per_page` numbers are §6.1's contract and are restated rather than
 * imported, for the reason Identity's copy gives: they belong to the document,
 * not to a class, and a test reads them from both places.
 */
final readonly class ListingQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private function __construct(public int $page, public int $perPage) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidListingQuery
     */
    public static function fromQueryString(array $query): self
    {
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400."
        // Clamping would answer a different question than the one asked, which
        // is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidListingQuery::of('per_page', 'above_maximum');
        }

        // Declared as empty above, so anything at all is unknown. `sort` is
        // checked the same way and for the same reason.
        if (($query['filter'] ?? null) !== null) {
            throw InvalidListingQuery::of('filter', 'unknown_filter');
        }

        if (($query['sort'] ?? null) !== null && $query['sort'] !== '') {
            throw InvalidListingQuery::of('sort', 'unknown_sort_field');
        }

        return new self(
            page: self::positiveInteger($query['page'] ?? null, 'page', 1),
            perPage: $perPage,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidListingQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number, and "0"
        // is not a page.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidListingQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }
}
