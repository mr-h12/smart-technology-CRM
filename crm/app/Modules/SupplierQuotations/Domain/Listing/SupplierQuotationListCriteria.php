<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/supplier-quotations`,
 * parsed once. `SupplierListCriteria`'s shape (Module 4).
 *
 * ── What this resource declares, and why it declares so little ────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource". Two filters
 * are declared, and each answers an acceptance criterion rather than a guess:
 * `supplier_id` is the build plan's *"the offer appears on the supplier page
 * under Linked Quotations"* — §7.1 lists `linked_quotations` as an **Automatic**
 * field of a supplier — and `deal_id` is `D-51`'s standalone offer that is
 * "available to any deal". A currency filter, a date range and `q` are not
 * declared because no source asks for them; §6.2's answer to a caller who sends
 * one is a 400, which is the point of an allowlist.
 *
 * **`total_price` is not sortable**, on `SupplierListCriteria`'s reasoning for
 * `color_rating`: an offer carries its own currency (§7.2, and Point 1.1's
 * `currency_id` beside the money column), and this table has no base amount —
 * `DB-06`'s `base_amount` belongs to the documents that convert, not to a
 * supplier's offer. Ordering by the number alone would rank an EGP total
 * against a USD one, which is not an ordering at all, and an allowlist that
 * quietly offered a meaningless one would be worse than a 400.
 *
 * ── The default order ──────────────────────────────────────────────────────
 *
 * §6.2: "default order is resource-specific and **documented**". Nothing in the
 * master documentation documents one for this resource; the owner chose
 * `-offer_date` on 2026-09-04 — newest offer first, which is the order a
 * supplier page is read in. This constant is that documentation.
 *
 * ⚠️ `offer_date` is **nullable** (Point 1.1: §7.2 marks neither date required),
 * and PostgreSQL sorts nulls first on a descending order. Where the nulls go is
 * the query's decision, not the contract's, so it belongs to Point 4.2 — the
 * same division `EloquentCatalogItemDirectory` draws when it writes `nulls
 * last` for a nullable group column.
 */
final readonly class SupplierQuotationListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['supplier_id', 'deal_id', 'deal_code'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['offer_date', 'created_at'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'offer_date';

    /** Newest offer first (owner, 2026-09-04). */
    public const DEFAULT_SORT_DESCENDING = true;

    /**
     * `$dealCode` is `D-88`'s fragment as typed (trimmed; null when blank).
     * `$dealIds` is what `ListSupplierQuotations` resolves it to through Deals'
     * contract — null means "not narrowed", `[]` means "no deal matched" and
     * so no offer does. The directory reads only `$dealIds`: it cannot ask
     * Deals, and the fragment means nothing to `supplier_quotations`.
     *
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     * @param  list<string>|null  $dealIds
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $supplierId = null,
        public ?string $dealId = null,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => self::DEFAULT_SORT_DESCENDING]],
        public ?string $dealCode = null,
        public ?array $dealIds = null,
    ) {}

    /** @param  list<string>  $dealIds  the deals `$dealCode` matched */
    public function withDealIds(array $dealIds): self
    {
        return new self($this->page, $this->perPage, $this->supplierId, $this->dealId, $this->sorts, $this->dealCode, $dealIds);
    }

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidSupplierQuotationListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        // Clamping instead would silently answer a different question than the
        // one asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidSupplierQuotationListQuery::of('per_page', 'above_maximum');
        }

        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            supplierId: $filters['supplier_id'],
            dealId: $filters['deal_id'],
            sorts: self::sorts($query['sort'] ?? null),
            dealCode: $filters['deal_code'],
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidSupplierQuotationListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidSupplierQuotationListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidSupplierQuotationListQuery
     */
    private static function sorts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [['field' => self::DEFAULT_SORT, 'descending' => self::DEFAULT_SORT_DESCENDING]];
        }

        if (! is_string($value)) {
            throw InvalidSupplierQuotationListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, self::ALLOWED_SORTS, true)) {
                throw InvalidSupplierQuotationListQuery::of('sort', 'unknown_sort_field');
            }

            // The same column twice cannot mean two things, and the second one
            // would silently never apply.
            if (in_array($field, $seen, true)) {
                throw InvalidSupplierQuotationListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @return array{supplier_id: string|null, deal_id: string|null, deal_code: string|null}
     *
     * @throws InvalidSupplierQuotationListQuery
     */
    private static function filters(mixed $value): array
    {
        $empty = ['supplier_id' => null, 'deal_id' => null, 'deal_code' => null];

        if ($value === null) {
            return $empty;
        }

        if (! is_array($value)) {
            throw InvalidSupplierQuotationListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidSupplierQuotationListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'supplier_id' => self::id($value['supplier_id'] ?? null, 'filter[supplier_id]'),
            'deal_id' => self::id($value['deal_id'] ?? null, 'filter[deal_id]'),
            'deal_code' => self::fragment($value['deal_code'] ?? null),
        ];
    }

    /**
     * `D-88`'s fragment, trimmed; blank is no filter, as `DealListCriteria`
     * reads `q` — and a blank value must never reach `SearchService`, which
     * refuses one.
     *
     * @throws InvalidSupplierQuotationListQuery
     */
    private static function fragment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidSupplierQuotationListQuery::of('filter[deal_code]', 'not_a_string');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * An id, checked for shape rather than for existence.
     *
     * A well-formed id that belongs to no supplier matches nothing — an empty
     * page, which is the honest answer to "show me that supplier's offers".
     * What is refused here is a value that is not an id at all, because that is
     * a malformed request rather than a request with no results.
     * `SupplierListCriteria::code()` draws the same line for a colour.
     *
     * @throws InvalidSupplierQuotationListQuery
     */
    private static function id(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw InvalidSupplierQuotationListQuery::of($parameter, 'not_a_uuid');
        }

        return $value;
    }
}
