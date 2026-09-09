<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Reading;

/**
 * One page of audit records, plus the six numbers `OpenAPI §4.2` puts in
 * `meta.pagination`.
 *
 * The arithmetic is `DealPage`'s, transcribed rather than shared — the same
 * choice `DealPage` itself made against `CustomerPage`, and for the same
 * reason: a shared pagination base class would be a dependency between modules
 * that have no business knowing about each other.
 */
final readonly class AuditRecordPage
{
    /** @param list<AuditRecord> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        // An empty result is one (empty) page, not zero — see CustomerPage.
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
