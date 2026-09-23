<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/quotations`, parsed once.
 *
 * What this resource declares is the Step 5 list the owner approved on
 * 2026-09-12 (Q1–Q5), on `DealListCriteria`'s shape:
 *
 * - `filter[status]` — §6.1's nine, repeatable; `filter[bucket]` — Q1's two, plus
 *   `incomplete` (§8: a returned draft, Module 8 · 2.2)
 *   halves of the same nine (`active` = what is still moving, `history` = the
 *   five terminals). `filter[employee]` is the deal's `owner_id` (Q2), read
 *   through `DealFactsInterface::dealIdsOwnedBy()` in 5.3, so it is a user id
 *   here and nothing more.
 * - `filter[amount_min]` / `filter[amount_max]` on `final_total`, and
 *   `sort=final_total`, are accepted **only with `filter[currency]`** (Q3): a
 *   quotation carries one currency, and ranking an EGP total against a USD
 *   one by the bare number is a comparison that means nothing (`DB-06`).
 * - `filter[from]` / `filter[to]` on `quotation_date`, inclusive (Q4).
 * - No `q` (Q5), no `include`; `group_by` is `employee | customer`, the two
 *   views §6.6 names. Anything else is a 400 — §6.2: "never ignore them
 *   silently".
 *
 * Values are checked for shape, not for existence: a currency code no
 * currency has, or an id no deal has, matches nothing — an empty page, which
 * is the honest answer to the question asked.
 */
final readonly class QuotationListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.1's nine statuses, in §6.4's order — the same nine `QuotationStatusTransition` walks. */
    public const STATUSES = ['draft', 'pending', 'approved', 'sent', 'accepted', 'partial', 'counter', 'rejected', 'expired'];

    /** Q1: the nine split into what still moves and what is settled. */
    public const BUCKETS = [
        'active' => ['draft', 'pending', 'approved', 'sent'],
        'history' => ['accepted', 'partial', 'counter', 'rejected', 'expired'],
    ];

    /** §8 "Incomplete": `draft` with `returned_at` set — a predicate, not a status list. */
    public const INCOMPLETE_BUCKET = 'incomplete';

    public const ALLOWED_PARAMETERS = ['page', 'per_page', 'filter', 'sort', 'group_by'];

    public const ALLOWED_FILTERS = ['status', 'bucket', 'employee', 'customer_id', 'deal_id', 'currency', 'amount_min', 'amount_max', 'from', 'to'];

    public const ALLOWED_SORTS = ['quotation_date', 'created_at', 'updated_at', 'code', 'final_total'];

    public const ALLOWED_GROUPS = ['employee', 'customer'];

    /** §6.2: "default order is resource-specific and documented" — approved as `-updated_at`. */
    public const DEFAULT_SORT = 'updated_at';

    /**
     * @param  list<string>  $statuses  empty means "any"
     * @param  list<array{field: string, descending: bool}>  $sorts  in the order the caller wrote them
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public array $statuses = [],
        public ?string $bucket = null,
        public ?string $employeeId = null,
        public ?string $customerId = null,
        public ?string $dealId = null,
        public ?string $currency = null,
        public ?string $amountMin = null,
        public ?string $amountMax = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $groupBy = null,
        public array $sorts = [['field' => self::DEFAULT_SORT, 'descending' => true]],
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidQuotationListQuery
     */
    public static function fromQuery(array $query): self
    {
        self::refuseUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidQuotationListQuery::of('per_page', 'above_maximum');
        }

        $f = self::filters($query['filter'] ?? null);
        $currency = self::currency($f['currency'] ?? null);
        $amountMin = self::amount($f['amount_min'] ?? null, 'filter[amount_min]', $currency);
        $amountMax = self::amount($f['amount_max'] ?? null, 'filter[amount_max]', $currency);
        $from = self::date($f['from'] ?? null, 'filter[from]');
        $to = self::date($f['to'] ?? null, 'filter[to]');

        // Q4: an inclusive range whose start is after its end is a malformed
        // request, not an empty one.
        if ($from !== null && $to !== null && $from > $to) {
            throw InvalidQuotationListQuery::of('filter[from]', 'after_to');
        }

        return new self(
            page: $page,
            perPage: $perPage,
            statuses: self::statuses($f['status'] ?? null),
            bucket: self::oneOf($f['bucket'] ?? null, 'filter[bucket]', [...array_keys(self::BUCKETS), self::INCOMPLETE_BUCKET], 'unknown_bucket'),
            employeeId: self::id($f['employee'] ?? null, 'filter[employee]'),
            customerId: self::id($f['customer_id'] ?? null, 'filter[customer_id]'),
            dealId: self::id($f['deal_id'] ?? null, 'filter[deal_id]'),
            currency: $currency,
            amountMin: $amountMin,
            amountMax: $amountMax,
            from: $from,
            to: $to,
            groupBy: self::oneOf($query['group_by'] ?? null, 'group_by', self::ALLOWED_GROUPS, 'unknown_group'),
            sorts: self::sorts($query['sort'] ?? null, $currency),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * §6.2: a parameter this resource does not declare is refused, never ignored.
     * Public for `PurchaseOrderListCriteria` (Module 10 · 2.2).
     *
     * @param  array<array-key, mixed>  $query
     * @param  list<string>  $allowed
     *
     * @throws InvalidQuotationListQuery
     */
    public static function refuseUnknownParameters(array $query, array $allowed): void
    {
        foreach (array_keys($query) as $parameter) {
            if (! in_array($parameter, $allowed, true)) {
                throw InvalidQuotationListQuery::of((string) $parameter, 'unknown_parameter');
            }
        }
    }

    /**
     * Public for `PurchaseOrderListCriteria` (Module 10 · 2.2).
     *
     * @throws InvalidQuotationListQuery
     */
    public static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidQuotationListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidQuotationListQuery
     */
    private static function filters(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidQuotationListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidQuotationListQuery::of('filter', 'unknown_filter');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Repeatable: `filter[status]=draft` arrives as a string,
     * `filter[status][]=draft&filter[status][]=sent` as a list.
     *
     * @return list<string>
     *
     * @throws InvalidQuotationListQuery
     */
    private static function statuses(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $statuses = [];
        foreach (is_array($value) ? $value : [$value] as $status) {
            $statuses[] = self::oneOf($status, 'filter[status]', self::STATUSES, 'unknown_status') ?? '';
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidQuotationListQuery
     */
    private static function oneOf(mixed $value, string $parameter, array $allowed, string $detailCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw InvalidQuotationListQuery::of($parameter, $detailCode);
        }

        return $value;
    }

    /** An id, checked for shape rather than for existence — `SupplierQuotationListCriteria::id()`. */
    private static function id(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw InvalidQuotationListQuery::of($parameter, 'not_a_uuid');
        }

        return $value;
    }

    /** An ISO 4217 shape; Domain cannot see Admin's `CurrencyCode`, and need not — an unknown code matches nothing. */
    private static function currency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw InvalidQuotationListQuery::of('filter[currency]', 'not_a_code');
        }

        return $value;
    }

    /**
     * A non-negative decimal, kept as the string it arrived as (`DB-07`: no
     * float touches money). Q3: meaningless without a currency to bound.
     *
     * @throws InvalidQuotationListQuery
     */
    private static function amount(mixed $value, string $parameter, ?string $currency): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($currency === null) {
            throw InvalidQuotationListQuery::of($parameter, 'currency_required');
        }

        if (! is_string($value) || preg_match('/^[0-9]+(\.[0-9]{1,6})?$/D', $value) !== 1) {
            throw InvalidQuotationListQuery::of($parameter, 'not_an_amount');
        }

        return $value;
    }

    /** A calendar date, `Y-m-d`, matching how `quotation_date` is written (`DB-08`: a date, not an instant). */
    private static function date(mixed $value, string $parameter): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw InvalidQuotationListQuery::of($parameter, 'not_a_date');
        }

        return $value;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidQuotationListQuery
     */
    private static function sorts(mixed $value, ?string $currency): array
    {
        $sorts = self::sortKeys($value, self::ALLOWED_SORTS, self::DEFAULT_SORT);

        foreach ($sorts as $sort) {
            if ($sort['field'] === 'final_total' && $currency === null) {
                throw InvalidQuotationListQuery::of('sort', 'currency_required');
            }
        }

        return $sorts;
    }

    /**
     * `sort=a,-b` against a closed list; absent means `-$default` (newest
     * first). Public for `PurchaseOrderListCriteria` (Module 10 · 2.2).
     *
     * @param  list<string>  $allowed
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidQuotationListQuery
     */
    public static function sortKeys(mixed $value, array $allowed, string $default): array
    {
        if ($value === null || $value === '') {
            return [['field' => $default, 'descending' => true]];
        }

        if (! is_string($value)) {
            throw InvalidQuotationListQuery::of('sort', 'unknown_sort_field');
        }

        $sorts = [];
        $seen = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            $descending = str_starts_with($key, '-');
            $field = $descending ? substr($key, 1) : $key;

            if (! in_array($field, $allowed, true)) {
                throw InvalidQuotationListQuery::of('sort', 'unknown_sort_field');
            }

            if (in_array($field, $seen, true)) {
                throw InvalidQuotationListQuery::of('sort', 'repeated_sort_field');
            }

            $seen[] = $field;
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }
}
