<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/deals/{id}/timeline`.
 *
 * ── Two parameters, and every absence is a decision ────────────────────────
 *
 * `page` and `per_page`, and nothing else. §6.2 allows "only fields explicitly
 * declared for that resource", so each thing this list does **not** declare is
 * a control that would answer 400 if a screen offered it:
 *
 * **No `sort`.** The order is the port's promise — newest first — not the
 * caller's option. A timeline read oldest-first would put what just happened on
 * the last page nobody opens, and `audit_log` has no second field anybody would
 * order a history by.
 *
 * **No `q`.** `D-48` routes free text through `SearchService`, and
 * `SearchIndex` has no audit index: offering a search here would be a control
 * with nothing behind it.
 *
 * **No `filter[event]`,** though the temptation is real — the acceptance
 * criterion names status changes and six other `DEAL_*` events share the table.
 * §4.4 says "every status change is written to the deal timeline", which
 * describes what must be *in* it, not what must be filtered *out* of it, and no
 * source describes an event filter. Recorded rather than invented; a screen
 * that wants one is owed a `D-xx` first.
 *
 * The bounds are `DealListCriteria`'s own, transcribed rather than shared: two
 * resources agreeing on a page size today is not a reason to make one of them
 * change when the other's contract does.
 */
final readonly class DealTimelineCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * Both are `positive-int` and the parser guarantees it — `^[1-9][0-9]*$`
     * refuses zero, a negative and a non-numeric alike. Stated so the audit
     * port's own `positive-int` contract is satisfied by a type rather than by
     * a promise.
     *
     * @param  positive-int  $page
     * @param  positive-int  $perPage
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidDealListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidDealListQuery::of('per_page', 'above_maximum');
        }

        // §6.2 answers an undeclared parameter with a 400 rather than ignoring
        // it: a caller who sent `sort=-created_at` and got an unsorted list
        // would believe the sort had been applied.
        foreach (array_keys($query) as $parameter) {
            if ($parameter !== 'page' && $parameter !== 'per_page') {
                throw InvalidDealListQuery::of((string) $parameter, 'unknown_parameter');
            }
        }

        return new self(page: $page, perPage: $perPage);
    }

    /**
     * @param  positive-int  $default
     * @return positive-int
     *
     * @throws InvalidDealListQuery
     */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidDealListQuery::of($parameter, 'not_a_positive_integer');
        }

        /** @var positive-int $parsed the pattern above refuses zero and below */
        $parsed = (int) $value;

        return $parsed;
    }
}
