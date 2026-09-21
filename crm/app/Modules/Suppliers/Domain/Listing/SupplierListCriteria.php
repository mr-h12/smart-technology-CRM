<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/suppliers`, parsed once.
 *
 * ── What this resource declares ───────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource". The four
 * filters are §7.1's own columns: the colour `D-19` lets any employee set, the
 * supplier/distributor type, the open-account flag, and §3.7's deactivation.
 * Sorting is by `name` or `created_at`.
 *
 * **`color_rating` is filterable but not sortable**, and that is deliberate.
 * Sorting by it would order green/red/white/yellow alphabetically, which is not
 * the ranking §7.1 gives those colours any more than it is any other order. A
 * meaningful ranking is a product decision nobody has recorded, and an
 * allowlist that quietly offered a meaningless one would be worse than a 400.
 *
 * ── `filter[is_active]` is tri-state, unlike Module 3's `is_archived` ──────
 *
 * Module 3 defaults `is_archived` to false because §9 Flow 7 archives a
 * customer to take it *out of the working list*. Nothing says that of a
 * supplier: §10.4 hides a deactivated one from **selection lists**, which are
 * Modules 6/7, not this screen. Unset therefore means both — and a management
 * list that hid them by default would be a list from which nobody could ever
 * reactivate one.
 *
 * `q` is **not** parsed into a pattern here. §6.2: it "always passes through
 * `SearchService`; do not expose a database-specific search syntax". This class
 * carries the words a person typed and nothing more.
 */
final readonly class SupplierListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['color_rating', 'type', 'is_active', 'has_open_account', 'is_incomplete'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['name', 'created_at'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'name';

    /**
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $q = null,
        public ?string $colorRating = null,
        public ?string $type = null,
        public ?bool $isActive = null,
        public ?bool $hasOpenAccount = null,
        public ?bool $isIncomplete = null,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => false]],
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidSupplierListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        // Clamping instead would silently answer a different question than the
        // one asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidSupplierListQuery::of('per_page', 'above_maximum');
        }

        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            q: self::freeText($query['q'] ?? null),
            colorRating: $filters['color_rating'],
            type: $filters['type'],
            isActive: $filters['is_active'],
            hasOpenAccount: $filters['has_open_account'],
            isIncomplete: $filters['is_incomplete'],
            sorts: self::sorts($query['sort'] ?? null),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidSupplierListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidSupplierListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /** @throws InvalidSupplierListQuery */
    private static function freeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidSupplierListQuery::of('q', 'not_a_string');
        }

        $trimmed = trim($value);

        // A blank `q` is the caller asking for everything, not for nothing.
        // `SearchService` refuses an empty query outright, so it is dropped
        // here rather than sent down to raise from the driver.
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidSupplierListQuery
     */
    private static function sorts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [['field' => self::DEFAULT_SORT, 'descending' => false]];
        }

        if (! is_string($value)) {
            throw InvalidSupplierListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, self::ALLOWED_SORTS, true)) {
                throw InvalidSupplierListQuery::of('sort', 'unknown_sort_field');
            }

            // The same column twice cannot mean two things, and the second one
            // would silently never apply.
            if (in_array($field, $seen, true)) {
                throw InvalidSupplierListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @return array{color_rating: string|null, type: string|null, is_active: bool|null, has_open_account: bool|null, is_incomplete: bool|null}
     *
     * @throws InvalidSupplierListQuery
     */
    private static function filters(mixed $value): array
    {
        $empty = [
            'color_rating' => null,
            'type' => null,
            'is_active' => null,
            'has_open_account' => null,
            'is_incomplete' => null,
        ];

        if ($value === null) {
            return $empty;
        }

        if (! is_array($value)) {
            throw InvalidSupplierListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidSupplierListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'color_rating' => self::code($value['color_rating'] ?? null, 'filter[color_rating]'),
            'type' => self::code($value['type'] ?? null, 'filter[type]'),
            'is_active' => self::boolean($value['is_active'] ?? null, 'filter[is_active]'),
            'has_open_account' => self::boolean($value['has_open_account'] ?? null, 'filter[has_open_account]'),
            'is_incomplete' => self::boolean($value['is_incomplete'] ?? null, 'filter[is_incomplete]'),
        ];
    }

    /** @throws InvalidSupplierListQuery */
    private static function boolean(mixed $value, string $parameter): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidSupplierListQuery::of($parameter, 'not_a_boolean'),
        };
    }

    /**
     * A colour or a type, checked for shape rather than for membership.
     *
     * The database holds the closed sets as CHECK constraints (Point 1.1), and
     * a value outside them simply matches nothing — an empty page, which is the
     * honest answer to "show me the purple suppliers". What is rejected here is
     * a value that is not a code at all, because that is a malformed request
     * rather than a request with no results.
     *
     * @throws InvalidSupplierListQuery
     */
    private static function code(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw InvalidSupplierListQuery::of($parameter, 'not_a_code');
        }

        return $value;
    }
}
