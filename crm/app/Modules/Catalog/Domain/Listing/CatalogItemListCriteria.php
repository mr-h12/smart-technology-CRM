<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/catalog-items`, parsed once.
 *
 * ── What this resource declares ───────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource", and each of
 * the three is a documented need rather than a column that happened to exist:
 *
 * - `kind` is §7.3's "Two tabs: Product · Service". Point 1.2 put both tabs in
 *   one table, so the tab is a filter here rather than a second route.
 * - `category` is the one column §7.3 annotates "(for search)".
 * - `is_active` is §3.7's "deactivate" and §7.3's "active product" / "Active
 *   service (yes/no)".
 *
 * - `company` is §7.3's "grouped by company/team name". `group_by=company` only
 *   *orders* the whole list, and a screen that groups by a column has to be
 *   able to ask for one group of it. Added 2026-08-31 on the owner's ruling,
 *   in the same point that made the column required at the write boundary.
 *
 * `unit`, `service_type` and `product_code` are **not** filterable. Each would
 * be a product decision nobody has recorded, and each is one line here when
 * somebody makes it — the same floor `SearchIndex` sets for columns.
 *
 * ── `group_by`, and the response shape it deliberately does not change ─────
 *
 * `API-06` requires server-side grouping "by employee / company"; §7.3 groups
 * the catalog "by company/team name"; §6.2 adds that "each resource declares
 * its allowed groups; clients do not request arbitrary database grouping".
 *
 * None of them specifies the **shape** of a grouped collection, and §4.2 shows
 * exactly one collection envelope. So grouping is expressed as **ordering**:
 * every row of a company adjacent, companies alphabetical, the caller's `sort`
 * applied inside each group, and `data` still the flat paginated list §4.2
 * describes. That keeps `API-04`'s row-based pagination meaningful — a nested
 * `{group, items}` body would make `per_page` count something the caller never
 * asked about — and it is still server-side grouping, because the client names
 * a declared group and can never ask for an arbitrary one.
 *
 * A row with no company sorts **last** rather than wherever the planner puts a
 * NULL: §7.3 leaves the column optional, so the ungrouped rows need a defined
 * place instead of an accidental one.
 *
 * ── `is_active` is tri-state ──────────────────────────────────────────────
 *
 * Unset lists both, for the reason `SupplierListCriteria` gives at length:
 * §10.4 hides a deactivated item from **selection lists**, which are Modules
 * 6/7, not this management screen — and a screen that hid them by default
 * would be one nobody could reactivate from.
 *
 * `q` is **not** parsed into a pattern here. §6.2: it "always passes through
 * `SearchService`; do not expose a database-specific search syntax".
 */
final readonly class CatalogItemListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['kind', 'category', 'is_active', 'company', 'is_incomplete'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['name', 'created_at'];

    /** §6.2: "Each resource declares its allowed groups" — §7.3 declares one. */
    public const ALLOWED_GROUPS = ['company'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'name';

    /**
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $q = null,
        public ?string $kind = null,
        public ?string $category = null,
        public ?bool $isActive = null,
        public ?string $company = null,
        public ?string $groupBy = null,
        public ?bool $isIncomplete = null,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => false]],
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidCatalogItemListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        // Clamping instead would silently answer a different question than the
        // one asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidCatalogItemListQuery::of('per_page', 'above_maximum');
        }

        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            q: self::text($query['q'] ?? null, 'q'),
            kind: $filters['kind'],
            category: $filters['category'],
            isActive: $filters['is_active'],
            company: $filters['company'],
            groupBy: self::group($query['group_by'] ?? null),
            sorts: self::sorts($query['sort'] ?? null),
            isIncomplete: $filters['is_incomplete'],
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidCatalogItemListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidCatalogItemListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /**
     * Words a person typed — the search box, or a category as it is written.
     *
     * Blank means "everything", not "nothing": `SearchService` refuses an empty
     * query outright, so it is dropped here rather than sent down to raise from
     * the driver.
     *
     * @throws InvalidCatalogItemListQuery
     */
    private static function text(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidCatalogItemListQuery::of($parameter, 'not_a_string');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @throws InvalidCatalogItemListQuery */
    private static function group(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // §6.2: "Reject unknown … group … values with 400 invalid_request;
        // never ignore them silently."
        if (! is_string($value) || ! in_array($value, self::ALLOWED_GROUPS, true)) {
            throw InvalidCatalogItemListQuery::of('group_by', 'unknown_group');
        }

        return $value;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidCatalogItemListQuery
     */
    private static function sorts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [['field' => self::DEFAULT_SORT, 'descending' => false]];
        }

        if (! is_string($value)) {
            throw InvalidCatalogItemListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, self::ALLOWED_SORTS, true)) {
                throw InvalidCatalogItemListQuery::of('sort', 'unknown_sort_field');
            }

            // The same column twice cannot mean two things, and the second one
            // would silently never apply.
            if (in_array($field, $seen, true)) {
                throw InvalidCatalogItemListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @return array{kind: string|null, category: string|null, is_active: bool|null, company: string|null, is_incomplete: bool|null}
     *
     * @throws InvalidCatalogItemListQuery
     */
    private static function filters(mixed $value): array
    {
        $empty = ['kind' => null, 'category' => null, 'is_active' => null, 'company' => null, 'is_incomplete' => null];

        if ($value === null) {
            return $empty;
        }

        if (! is_array($value)) {
            throw InvalidCatalogItemListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidCatalogItemListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'kind' => self::code($value['kind'] ?? null, 'filter[kind]'),
            // A category is words a person wrote — "power tools", not a code —
            // so it is checked for being text and matched as written.
            'category' => self::text($value['category'] ?? null, 'filter[category]'),
            'is_active' => self::boolean($value['is_active'] ?? null, 'filter[is_active]'),
            // A company is a name a person wrote, not a code — matched as
            // written, exactly like `category` above.
            'company' => self::text($value['company'] ?? null, 'filter[company]'),
            'is_incomplete' => self::boolean($value['is_incomplete'] ?? null, 'filter[is_incomplete]'),
        ];
    }

    /** @throws InvalidCatalogItemListQuery */
    private static function boolean(mixed $value, string $parameter): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidCatalogItemListQuery::of($parameter, 'not_a_boolean'),
        };
    }

    /**
     * A `kind`, checked for shape rather than for membership.
     *
     * Point 1.2 holds the closed set as a CHECK constraint, and a value outside
     * it simply matches nothing — an empty page, which is the honest answer to
     * "show me the tab that does not exist". What is rejected here is a value
     * that is not a code at all, because that is a malformed request rather
     * than a request with no results. The same reading `SupplierListCriteria`
     * applies to a colour.
     *
     * @throws InvalidCatalogItemListQuery
     */
    private static function code(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw InvalidCatalogItemListQuery::of($parameter, 'not_a_code');
        }

        return $value;
    }
}
