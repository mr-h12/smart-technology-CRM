<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/customers`, parsed once.
 *
 * ── Why this accepts several sort fields when Identity's accepts one ───────
 *
 * Not an inconsistency. §6.2's example is written against **this** resource:
 * `GET /api/v1/customers?q=ahmed&sort=-created_at,name`. The contract asks this
 * endpoint for a comma-separated sort by name, so it parses one; Identity's
 * criteria rejects a comma because nothing documents a second key there and
 * accepting-and-ignoring is the silent behaviour §6.2 forbids.
 *
 * ── What this resource declares ───────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource". The filters
 * are §4.2's own columns plus `owner_inactive`, which is §10.1's required
 * *"Customers of deactivated employees"* filter. Sorting is by `name`,
 * `created_at` or `start_date` — the three a customer list is actually ordered
 * by, and an allowlist is what makes `sort=password` a 400 rather than a
 * question for the database.
 *
 * `q` is **not** parsed into a pattern here. §6.2: it "always passes through
 * `SearchService`; do not expose a database-specific search syntax". This class
 * carries the words a person typed and nothing more.
 */
final readonly class CustomerListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['customer_status', 'sector', 'is_archived', 'is_incomplete', 'owner_inactive'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['name', 'created_at', 'start_date'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'name';

    /**
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $q = null,
        public ?string $customerStatus = null,
        public ?string $sector = null,
        public bool $isArchived = false,
        public ?bool $isIncomplete = null,
        public bool $ownerInactive = false,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => false]],
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidCustomerListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        // Clamping instead would silently answer a different question than the
        // one asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidCustomerListQuery::of('per_page', 'above_maximum');
        }

        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            q: self::freeText($query['q'] ?? null),
            customerStatus: $filters['customer_status'],
            sector: $filters['sector'],
            // Archived rows are absent unless asked for. §9 Flow 7 archives a
            // customer to take it out of the working list; a list that still
            // showed it would make the action look like it had not happened.
            isArchived: $filters['is_archived'] ?? false,
            isIncomplete: $filters['is_incomplete'],
            ownerInactive: $filters['owner_inactive'] ?? false,
            sorts: self::sorts($query['sort'] ?? null),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidCustomerListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidCustomerListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /** @throws InvalidCustomerListQuery */
    private static function freeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidCustomerListQuery::of('q', 'not_a_string');
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
     * @throws InvalidCustomerListQuery
     */
    private static function sorts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [['field' => self::DEFAULT_SORT, 'descending' => false]];
        }

        if (! is_string($value)) {
            throw InvalidCustomerListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, self::ALLOWED_SORTS, true)) {
                throw InvalidCustomerListQuery::of('sort', 'unknown_sort_field');
            }

            // The same column twice cannot mean two things, and the second one
            // would silently never apply.
            if (in_array($field, $seen, true)) {
                throw InvalidCustomerListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @return array{customer_status: string|null, sector: string|null, is_archived: bool|null, is_incomplete: bool|null, owner_inactive: bool|null}
     *
     * @throws InvalidCustomerListQuery
     */
    private static function filters(mixed $value): array
    {
        $empty = [
            'customer_status' => null,
            'sector' => null,
            'is_archived' => null,
            'is_incomplete' => null,
            'owner_inactive' => null,
        ];

        if ($value === null) {
            return $empty;
        }

        if (! is_array($value)) {
            throw InvalidCustomerListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidCustomerListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'customer_status' => self::slug($value['customer_status'] ?? null, 'filter[customer_status]'),
            'sector' => self::slug($value['sector'] ?? null, 'filter[sector]'),
            'is_archived' => self::boolean($value['is_archived'] ?? null, 'filter[is_archived]'),
            'is_incomplete' => self::boolean($value['is_incomplete'] ?? null, 'filter[is_incomplete]'),
            'owner_inactive' => self::boolean($value['owner_inactive'] ?? null, 'filter[owner_inactive]'),
        ];
    }

    /** @throws InvalidCustomerListQuery */
    private static function boolean(mixed $value, string $parameter): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidCustomerListQuery::of($parameter, 'not_a_boolean'),
        };
    }

    /**
     * A managed-list code or a status, matched against the database rather than
     * an enum here.
     *
     * `DB-05` puts sectors in `enum_lists` and §3.12 rule 5 makes adding one a
     * configuration change, so a filter that only knew the seeded six would
     * answer an empty page for a sector that exists.
     *
     * @throws InvalidCustomerListQuery
     */
    private static function slug(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw InvalidCustomerListQuery::of($parameter, 'not_a_code');
        }

        return $value;
    }
}
