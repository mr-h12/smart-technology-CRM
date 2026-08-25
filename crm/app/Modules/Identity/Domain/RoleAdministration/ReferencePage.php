<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

/**
 * One page of a reference listing, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * ── Why this is generic and {@see \App\Modules\Identity\Domain\Administration\UserPage} is not ──
 *
 * Two listings land in this point — roles and permissions — and both need the
 * identical arithmetic `UserPage` already performs. A third copy of
 * `max(1, (int) ceil(...))` is a third place for the empty-page rule to be got
 * wrong. `UserPage` is deliberately left alone: folding it into this template
 * is a refactor of shipped code, and this point is not that.
 *
 * @template TItem of object
 */
final readonly class ReferencePage
{
    /** @param list<TItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        // An empty result is one (empty) page, not zero — `page=1` of an empty
        // list is a valid request, and reporting 0 makes `has_next_page` and
        // the SPA's paginator disagree about whether page 1 exists.
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
