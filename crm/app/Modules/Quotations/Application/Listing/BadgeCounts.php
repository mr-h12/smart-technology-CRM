<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;

/**
 * Module 8 Point 2.3 (Q5) — the two sidebar counters §18.1 and Design System
 * §5.1 allow this module: *Approvals* and *My Quotations*.
 *
 * Both are `list()->total` over the reach the caller already has, so the
 * numbers can never disagree with the screens they lead to: `approvals` is
 * the pending rows within the caller's `quotation.approve` scope (`SEC-08`;
 * `decide`, not `authorize`, because a role without the cell is answered `0`,
 * not refused — a sidebar is drawn for everyone), `my_quotations` is 2.2's
 * `incomplete` bucket within `own`.
 */
final readonly class BadgeCounts
{
    public function __construct(
        private QuotationDirectoryInterface $directory,
        private AuthorizeAction $authorize,
    ) {}

    /** @return array{approvals: int, my_quotations: int} */
    public function for(string $actorId): array
    {
        // A denied decision carries no scopes, and a scope that reaches nothing
        // is answered `0` before any query — so no `granted` branch is needed.
        $approve = $this->authorize->decide($actorId, 'quotation', 'approve')->scopeValues();

        return [
            'approvals' => $this->total(new QuotationListCriteria(perPage: 1, statuses: ['pending']), QuotationRowScope::resolve($approve, $actorId)),
            'my_quotations' => $this->total(new QuotationListCriteria(perPage: 1, bucket: QuotationListCriteria::INCOMPLETE_BUCKET), QuotationRowScope::resolve([Scope::Own->value], $actorId)),
        ];
    }

    // ponytail: one row is fetched per count; a count-only directory method
    // if the badges are ever polled faster than a page load.
    private function total(QuotationListCriteria $criteria, QuotationRowScope $scope): int
    {
        return $this->directory->list($criteria, $scope)->total;
    }
}
