<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

/**
 * What Module 7 reads about a deal to create a quotation on it (Point 3.4):
 * whose deal it is and whom it is for.
 *
 * Two facts, one read, on {@see \App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface}'s
 * terms. The owner is what a scoped `quotation.create` is filed against — the
 * owner ruled 2026-09-11 that a quotation's "own" is its deal's `owner_id`,
 * not the tracking field `created_by` — and the customer is what a quotation's
 * own `customer_id` must agree with, since §6.2 carries both and a quotation
 * addressed to somebody other than its deal's customer is a defect. Neither is
 * a screen, so neither takes a {@see \App\Modules\Deals\Domain\Access\DealRowScope}:
 * the caller resolves its own scope and compares.
 */
interface DealFactsInterface
{
    /**
     * Null for an absent or `DB-01` soft-deleted deal — the caller's `deal_id`
     * names nothing a quotation may hang from.
     */
    public function factsOf(string $dealId): ?DealFacts;

    /**
     * The live deals one user owns (Point 5.1) — what a scoped `quotation.view`
     * on the list becomes (`WHERE deal_id IN`) and what `filter[employee]`
     * intersects. Soft-deleted deals are absent (`DB-01`).
     *
     * ponytail: unbounded set. Denormalise `owner_id` onto `quotations` when a
     * Manager's `filter[employee]` on a ten-thousand-deal owner measures slow.
     *
     * @return list<string>
     */
    public function dealIdsOwnedBy(string $ownerId): array;

    /**
     * Deal id => owner id for one page's deals (`group_by=employee`). An
     * unowned deal maps to `null`; an absent or soft-deleted id has no entry.
     * The empty list answers `[]` without a query.
     *
     * @param  list<string>  $dealIds
     * @return array<string, ?string>
     */
    public function ownersOf(array $dealIds): array;
}
