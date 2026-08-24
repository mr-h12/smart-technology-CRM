<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/users`, parsed once.
 *
 * ── Why the limits are here and not in a validator ─────────────────────────
 *
 * §6.1 fixes the default at 25 and the maximum at 100, and §4.2 says an
 * endpoint "must never return an unbounded collection". Those are contract
 * numbers, so they are constants a test can read rather than digits inside a
 * `max:100` rule string.
 *
 * ── What this resource declares ────────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource", so the two
 * allowlists below are that declaration: filter on `is_active` and `role`, sort
 * by `name`, `email` or `created_at`. Anything else is `400 invalid_request` —
 * §6.2 is explicit that an unknown parameter must not be ignored.
 *
 * ── ⚠️ `q` is deliberately absent ──────────────────────────────────────────
 *
 * §6.2 says the free-text `q` "always passes through `SearchService`", and
 * `CLAUDE.md` builds `SearchService` in **Module 3** so that Meilisearch can
 * replace its driver later: "All search calls must use it". A bare `ILIKE`
 * here would be exactly the call site that rule exists to prevent, and
 * inventing the seam three modules early is the speculative abstraction
 * `CLAUDE.md` forbids in the same breath. Recorded as an open gap.
 */
final readonly class UserListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2: the fields this resource declares as filterable. */
    public const ALLOWED_FILTERS = ['is_active', 'role'];

    /** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
    public const ALLOWED_SORTS = ['name', 'email', 'created_at'];

    /** §6.2: "default order is resource-specific and documented" — this is that documentation. */
    public const DEFAULT_SORT = 'name';

    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?bool $isActive = null,
        public ?string $roleSlug = null,
        public string $sortField = self::DEFAULT_SORT,
        public bool $sortDescending = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidListQuery
     */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        // Clamping instead would silently answer a different question than the
        // one asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidListQuery::of('per_page', 'above_maximum');
        }

        [$sortField, $sortDescending] = self::sort($query['sort'] ?? null);
        $filters = self::filters($query['filter'] ?? null);

        return new self(
            page: $page,
            perPage: $perPage,
            isActive: $filters['is_active'],
            roleSlug: $filters['role'],
            sortField: $sortField,
            sortDescending: $sortDescending,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @throws InvalidListQuery */
    private static function positiveInteger(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // A string, because it came off a query string. `is_numeric` would
        // accept "1.5" and "1e3"; the contract means a whole number.
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw InvalidListQuery::of($parameter, 'not_a_positive_integer');
        }

        return (int) $value;
    }

    /**
     * @return array{string, bool}
     *
     * @throws InvalidListQuery
     */
    private static function sort(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [self::DEFAULT_SORT, false];
        }

        if (! is_string($value)) {
            throw InvalidListQuery::of('sort', 'unknown_sort_field');
        }

        // §6.2 allows a comma-separated list. This resource accepts one field:
        // a second key changes the result set, so accepting and ignoring it
        // would be the silent behaviour §6.2 forbids.
        if (str_contains($value, ',')) {
            throw InvalidListQuery::of('sort', 'too_many_sort_fields');
        }

        $descending = str_starts_with($value, '-');
        $field = $descending ? substr($value, 1) : $value;

        if (! in_array($field, self::ALLOWED_SORTS, true)) {
            throw InvalidListQuery::of('sort', 'unknown_sort_field');
        }

        return [$field, $descending];
    }

    /**
     * @return array{is_active: bool|null, role: string|null}
     *
     * @throws InvalidListQuery
     */
    private static function filters(mixed $value): array
    {
        if ($value === null) {
            return ['is_active' => null, 'role' => null];
        }

        if (! is_array($value)) {
            throw InvalidListQuery::of('filter', 'unknown_filter');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ALLOWED_FILTERS, true)) {
                throw InvalidListQuery::of('filter', 'unknown_filter');
            }
        }

        return [
            'is_active' => self::boolean($value['is_active'] ?? null),
            'role' => self::role($value['role'] ?? null),
        ];
    }

    /** @throws InvalidListQuery */
    private static function boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidListQuery::of('filter[is_active]', 'not_a_boolean'),
        };
    }

    /** @throws InvalidListQuery */
    private static function role(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // The slug is matched against the `roles` table by the directory, not
        // against §3.1's enum: §3.12 rule 5 makes a ninth role a configuration
        // change, and a filter that only knows the shipped eight would answer
        // an empty page for a role that exists.
        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $value) !== 1) {
            throw InvalidListQuery::of('filter[role]', 'not_a_role_slug');
        }

        return $value;
    }
}
