<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/fx-rates`, parsed once.
 *
 * ── Why this listing is paginated at all ───────────────────────────────────
 *
 * Because it is the first genuinely unbounded collection in Module 2. `GET
 * /settings` returns whatever `SystemSetting` declares and `GET /currencies`
 * returns three rows; both are bounded by an enum. A rate history is not — it
 * is append-only by `AP-06` and grows for as long as the business prices
 * anything. §4.2 leaves no room anyway: *"Every list endpoint is paginated; an
 * endpoint must **never** return an unbounded collection."*
 *
 * ── No filters, and one fixed order ────────────────────────────────────────
 *
 * §6.2 allows *"only fields explicitly declared for that resource"*, and this
 * resource declares none — so `filter[...]` and `sort` are refused rather than
 * ignored, which is the behaviour §6.2 actually names. A `filter[from_currency]`
 * is the obvious next one and is deliberately **not** invented here: §13 screen
 * 5 says *"rate history"* and stops, the whole history is at most a few pages,
 * and a filter nobody has specified is a contract the SPA would be written
 * against before anyone decided it was right.
 *
 * The order is `effective_from` descending, `created_at` descending to break a
 * tie. §6.2 requires the default to be documented; this is that documentation.
 * Newest first, because the question a rate screen answers is *what is the rate
 * now*, and the tie-break exists because two rates for different pairs may
 * share a moment and a paginated listing with an unstable order silently drops
 * and repeats rows across pages.
 *
 * The `per_page` numbers are §6.1's contract and are restated rather than
 * imported, for the reason Identity's copy gives: they belong to the document,
 * not to a class, and a test reads them from both places.
 */
final readonly class RateHistoryQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private function __construct(public int $page, public int $perPage) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidRateHistoryQuery
     */
    public static function fromQueryString(array $query): self
    {
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400."
        // Clamping would answer a different question than the one asked, which
        // is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidRateHistoryQuery::of('per_page', 'above_maximum');
        }

        // Declared as empty above, so anything at all is unknown. `sort` is
        // checked the same way and for the same reason.
        if (($query['filter'] ?? null) !== null) {
            throw InvalidRateHistoryQuery::of('filter', 'unknown_filter');
        }

        if (($query['sort'] ?? null) !== null && $query['sort'] !== '') {
            throw InvalidRateHistoryQuery::of('sort', 'unknown_sort_field');
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

    /** @throws InvalidRateHistoryQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number, and "0"
        // is not a page.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidRateHistoryQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }
}
