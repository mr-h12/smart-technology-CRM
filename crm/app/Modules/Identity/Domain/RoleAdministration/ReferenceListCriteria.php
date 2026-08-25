<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

use App\Modules\Identity\Domain\Administration\InvalidListQuery;

/**
 * `OpenAPI §6` — the query contract for `GET /api/v1/roles` and
 * `GET /api/v1/permissions`, parsed once.
 *
 * ── Why these listings are paginated at all ────────────────────────────────
 *
 * There are eight roles and 143 permission rows today, so a page size looks
 * like ceremony. §4.2 does not leave it open: "Every list endpoint is
 * paginated; an endpoint must **never** return an unbounded collection." And
 * neither set is fixed — §3.12 rule 5 makes a ninth role a configuration
 * change, and every module from 3 onward adds permission rows. An endpoint that
 * returns everything works until the day it does not, and by then the SPA has
 * been written against it.
 *
 * ── One class, two resources ───────────────────────────────────────────────
 *
 * §6.2 allows "only fields explicitly declared for that resource", so each
 * resource declares its own allowlists and the parsing is shared. Two classes
 * would be the same 120 lines twice with four constants changed, and the second
 * copy is where the `per_page` maximum eventually stops matching §6.1.
 *
 * The limits are deliberately re-declared here rather than imported from
 * {@see \App\Modules\Identity\Domain\Administration\UserListCriteria}: they are
 * §6.1's contract numbers, and a test reads them from both places to assert the
 * two resources did not drift apart.
 */
final readonly class ReferenceListCriteria
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** §6.2's declaration for `roles`. */
    public const ROLE_FILTERS = ['is_system'];

    public const ROLE_SORTS = ['name', 'slug', 'created_at'];

    public const ROLE_DEFAULT_SORT = 'name';

    /** §6.2's declaration for `permissions`. */
    public const PERMISSION_FILTERS = ['resource'];

    public const PERMISSION_SORTS = ['resource', 'action', 'scope'];

    /**
     * §6.2: "default order is resource-specific and documented" — this is that
     * documentation. `resource` first is what makes the listing read as §3.3…
     * §3.11 do, one table at a time.
     */
    public const PERMISSION_DEFAULT_SORT = 'resource';

    /**
     * §6.2's declaration for `auth/sessions` — `SEC-05`'s device list.
     *
     * No filters. The listing is already scoped to one account by the guard,
     * and §6.2 allows "only fields explicitly declared for that resource": a
     * `filter[user_id]` would be the one field this endpoint must never accept.
     */
    public const SESSION_FILTERS = [];

    public const SESSION_SORTS = ['last_activity_at', 'created_at'];

    /**
     * §6.2: "default order is resource-specific and documented" — this is that
     * documentation. Most recently active first, because the row a person is
     * looking for on a security screen is the one that moved last.
     */
    public const SESSION_DEFAULT_SORT = '-last_activity_at';

    /**
     * @param  array<string, string|bool>  $filters  keyed by the declared filter name; absent means unfiltered
     */
    private function __construct(
        public int $page,
        public int $perPage,
        public string $sortField,
        public bool $sortDescending,
        public array $filters,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidListQuery
     */
    public static function forRoles(array $query): self
    {
        return self::parse($query, self::ROLE_FILTERS, self::ROLE_SORTS, self::ROLE_DEFAULT_SORT);
    }

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidListQuery
     */
    public static function forPermissions(array $query): self
    {
        return self::parse($query, self::PERMISSION_FILTERS, self::PERMISSION_SORTS, self::PERMISSION_DEFAULT_SORT);
    }

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidListQuery
     */
    public static function forSessions(array $query): self
    {
        return self::parse($query, self::SESSION_FILTERS, self::SESSION_SORTS, self::SESSION_DEFAULT_SORT);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function filterBool(string $name): ?bool
    {
        $value = $this->filters[$name] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function filterString(string $name): ?string
    {
        $value = $this->filters[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  list<string>  $allowedFilters
     * @param  list<string>  $allowedSorts
     *
     * @throws InvalidListQuery
     */
    private static function parse(array $query, array $allowedFilters, array $allowedSorts, string $defaultSort): self
    {
        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger($query['per_page'] ?? null, 'per_page', self::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400."
        // Clamping would silently answer a different question than the one
        // asked, which is the same defect as ignoring an unknown filter.
        if ($perPage > self::MAX_PER_PAGE) {
            throw InvalidListQuery::of('per_page', 'above_maximum');
        }

        [$sortField, $sortDescending] = self::sort($query['sort'] ?? null, $allowedSorts, $defaultSort);

        return new self(
            page: $page,
            perPage: $perPage,
            sortField: $sortField,
            sortDescending: $sortDescending,
            filters: self::filters($query['filter'] ?? null, $allowedFilters),
        );
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
     * @param  list<string>  $allowed
     * @return array{string, bool}
     *
     * @throws InvalidListQuery
     */
    private static function sort(mixed $value, array $allowed, string $default): array
    {
        // The default is parsed through the same `-` handling as a submitted
        // value, so a resource whose documented default order is descending can
        // say so as `-field` rather than needing a second parameter. Roles and
        // permissions declare ascending defaults and are unaffected — their
        // constants carry no prefix, which `SessionManagementTest` pins.
        $value = $value === null || $value === '' ? $default : $value;

        if (! is_string($value)) {
            throw InvalidListQuery::of('sort', 'unknown_sort_field');
        }

        // §6.2 allows a comma-separated list. These resources accept one field:
        // a second key changes the ordering, so accepting and ignoring it would
        // be the silent behaviour §6.2 forbids.
        if (str_contains($value, ',')) {
            throw InvalidListQuery::of('sort', 'too_many_sort_fields');
        }

        $descending = str_starts_with($value, '-');
        $field = $descending ? substr($value, 1) : $value;

        if (! in_array($field, $allowed, true)) {
            throw InvalidListQuery::of('sort', 'unknown_sort_field');
        }

        return [$field, $descending];
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string|bool>
     *
     * @throws InvalidListQuery
     */
    private static function filters(mixed $value, array $allowed): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidListQuery::of('filter', 'unknown_filter');
        }

        $parsed = [];

        foreach ($value as $key => $raw) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw InvalidListQuery::of('filter', 'unknown_filter');
            }

            if ($raw === null || $raw === '') {
                continue;
            }

            $parsed[$key] = $key === 'is_system'
                ? self::boolean($raw)
                : self::identifier($key, $raw);
        }

        return $parsed;
    }

    /** @throws InvalidListQuery */
    private static function boolean(mixed $value): bool
    {
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidListQuery::of('filter[is_system]', 'not_a_boolean'),
        };
    }

    /**
     * A `resource` name, matched against the `permissions` table rather than
     * against a list of the resources §3 happens to name today: §3.12 rule 5
     * makes the live matrix the answer, and a filter that only knew the shipped
     * resources would return an empty page for one a later module added.
     *
     * @throws InvalidListQuery
     */
    private static function identifier(string $key, mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $value) !== 1) {
            throw InvalidListQuery::of('filter['.$key.']', 'not_an_identifier');
        }

        return $value;
    }
}
