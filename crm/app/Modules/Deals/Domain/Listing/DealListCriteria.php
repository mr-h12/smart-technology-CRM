<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/deals`, parsed once.
 *
 * ── What this resource declares ───────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource". The filters
 * are §4.3's own closed-vocabulary columns: `status` (§4.4's eleven-plus-terminal
 * states), `service_type` and `source` (§4.3's own two- and three-way splits),
 * and `approval_status` (§4.3, nullable — a caller may ask for a specific value
 * but there is no filter for "not applicable"; that is what omitting the filter
 * already means).
 *
 * Sorting is `code`, `created_at` or `last_activity_at`. `code` because §4.3's
 * codes are sequential and a code-ordered list reads as a creation order a
 * person can recognise; `last_activity_at` because it is the one field §4.3
 * gives explicit operational meaning to (`D-17`, `J-03`'s stale-deal scan), and
 * the default sort — a caller opening the list is most likely asking "what
 * needs attention", which is the newest activity first.
 *
 * `q` is **not** parsed into a pattern here, on `CustomerListCriteria`'s
 * precedent: §6.2 says it "always passes through `SearchService`". `title` is
 * the one free-text field §4.3 names for a deal, so it is what a search
 * matches — see `SearchIndex::Deals`.
 */
final readonly class DealListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['status', 'service_type', 'source', 'approval_status', 'owner', 'customer', 'created_from', 'created_to', 'progress'];

    /** Module 14 · 6.1 (`D-144`): open is before `won`; closed is `won` or later, or `lost`. */
    public const PROGRESS = ['open', 'closed'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['code', 'created_at', 'last_activity_at'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'last_activity_at';

    /**
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $q = null,
        public ?string $status = null,
        public ?string $serviceType = null,
        public ?string $source = null,
        public ?string $approvalStatus = null,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => true]],
        public ?string $ownerId = null,
        public ?string $customerId = null,
        // Module 14 · 6.1, the dashboard's drill-down: creation days in `$zone`, both included.
        public ?string $createdFrom = null,
        public ?string $createdTo = null,
        public ?string $progress = null,
        public string $zone = 'UTC',
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidDealListQuery
     */
    public static function fromQuery(array $query, string $zone = 'UTC'): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidDealListQuery::of('per_page', 'above_maximum');
        }

        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            q: self::freeText($query['q'] ?? null),
            status: $filters['status'],
            serviceType: $filters['service_type'],
            source: $filters['source'],
            approvalStatus: $filters['approval_status'],
            sorts: self::sorts($query['sort'] ?? null),
            ownerId: $filters['owner'],
            customerId: $filters['customer'],
            createdFrom: $filters['created_from'],
            createdTo: $filters['created_to'],
            progress: $filters['progress'],
            zone: $zone,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidDealListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidDealListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /** @throws InvalidDealListQuery */
    private static function freeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidDealListQuery::of('q', 'not_a_string');
        }

        $trimmed = trim($value);

        // A blank `q` is the caller asking for everything, not for nothing.
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidDealListQuery
     */
    private static function sorts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [['field' => self::DEFAULT_SORT, 'descending' => true]];
        }

        if (! is_string($value)) {
            throw InvalidDealListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, self::ALLOWED_SORTS, true)) {
                throw InvalidDealListQuery::of('sort', 'unknown_sort_field');
            }

            if (in_array($field, $seen, true)) {
                throw InvalidDealListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @return array{status: string|null, service_type: string|null, source: string|null, approval_status: string|null, owner: string|null, customer: string|null, created_from: string|null, created_to: string|null, progress: string|null}
     *
     * @throws InvalidDealListQuery
     */
    private static function filters(mixed $value): array
    {
        $empty = [
            'status' => null,
            'service_type' => null,
            'source' => null,
            'approval_status' => null,
            'owner' => null,
            'customer' => null,
            'created_from' => null,
            'created_to' => null,
            'progress' => null,
        ];

        if ($value === null) {
            return $empty;
        }

        if (! is_array($value)) {
            throw InvalidDealListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidDealListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'status' => self::slug($value['status'] ?? null, 'filter[status]'),
            'service_type' => self::slug($value['service_type'] ?? null, 'filter[service_type]'),
            'source' => self::slug($value['source'] ?? null, 'filter[source]'),
            'approval_status' => self::slug($value['approval_status'] ?? null, 'filter[approval_status]'),
            // `D-108`: the current `owner_id`; row scope still applies, so an `own` caller asking for
            // another owner gets nothing rather than a refusal that would confirm the owner exists.
            'owner' => self::id($value['owner'] ?? null, 'filter[owner]'),
            // `D-114`: the customer page's requests, under the same row scope.
            'customer' => self::id($value['customer'] ?? null, 'filter[customer]'),
            'created_from' => self::day($value['created_from'] ?? null, 'filter[created_from]'),
            'created_to' => self::day($value['created_to'] ?? null, 'filter[created_to]'),
            'progress' => self::progress($value['progress'] ?? null),
        ];
    }

    /**
     * An id, checked for shape rather than for existence — `QuotationListCriteria::id()`.
     *
     * @throws InvalidDealListQuery
     */
    /** Module 14 · 6.1: a calendar day (`Y-m-d`) that exists; the company's zone reads it (`D-145`). */
    private static function day(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw InvalidDealListQuery::of($parameter, 'not_a_date');
        }

        return $value;
    }

    private static function progress(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, self::PROGRESS, true)) {
            throw InvalidDealListQuery::of('filter[progress]', 'not_a_code');
        }

        return $value;
    }

    private static function id(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw InvalidDealListQuery::of($parameter, 'not_a_uuid');
        }

        return $value;
    }

    /** @throws InvalidDealListQuery */
    private static function slug(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw InvalidDealListQuery::of($parameter, 'not_a_code');
        }

        return $value;
    }
}
