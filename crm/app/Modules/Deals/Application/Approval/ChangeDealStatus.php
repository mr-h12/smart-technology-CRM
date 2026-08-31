<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Approval;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Access\DealStatusTransition;
use App\Modules\Deals\Domain\Approval\DealStatusTransitionRefused;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * §4.4's transition — `PATCH /deals/{id}/status`.
 *
 * ── Two permissions, one route ──────────────────────────────────────────────
 *
 * `deal.change_status` (checked by the route's middleware, Point 2.1's scope
 * already narrows the row this reaches) covers every edge in
 * {@see DealStatusTransition} except one. `Delivery → Delivery Complete`
 * additionally needs `deal.mark_delivery_complete` — `D-14`'s four named
 * roles, a **different** set from `change_status`'s (Outdoor Supervisor and
 * Outdoor Sales hold `change_status` but not this one) — so the second
 * permission is asked here, mid-flow, once the requested edge is known. A
 * route cannot ask this in middleware: which permission applies depends on
 * the request body's target status, not on the URL alone.
 *
 * ── Why the finer-grained "who" in §4.4's table is not enforced ────────────
 *
 * See {@see DealStatusTransition}'s own docblock: no second permission is
 * seeded in `PermissionMatrix` for any other edge, so building one would be
 * inventing authorisation the matrix does not grant — the same restraint
 * every row-scope class in this module already exercises.
 *
 * ── `lost_reason` is validated at the boundary, on `RejectDealRequest`'s
 * precedent ──
 *
 * `ChangeDealStatusRequest` refuses a blank reason on the `lost` transition
 * as a 422 before this ever runs; the table's own CHECK (Point 2.6) would
 * catch it too, but as a 500.
 */
final readonly class ChangeDealStatus
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private AuditRecorderInterface $audit,
        private AuthorizeAction $authorizeAction,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     * @throws DealStatusTransitionRefused when §4.4 does not name this edge
     * @throws AuthorizationRefused when the edge is `Delivery → Delivery Complete`
     *                              and the caller does not hold `deal.mark_delivery_complete` over this row
     */
    public function handle(
        string $dealId,
        string $newStatus,
        ?string $lostReason,
        array $heldScopes,
        string $actorId,
    ): DealSummary {
        $scope = DealRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($dealId, $newStatus, $lostReason, $scope, $actorId): DealSummary {
            $before = $this->deals->find($dealId, $scope);

            if (! $before instanceof DealSummary) {
                throw DealNotFound::of($dealId);
            }

            if (! DealStatusTransition::isAllowed($before->status, $newStatus)) {
                throw DealStatusTransitionRefused::of($dealId, $before->status, $newStatus);
            }

            if ($before->status === 'delivery' && $newStatus === 'delivery_complete') {
                $this->authoriseDeliveryComplete($before, $actorId);
            }

            $after = $this->deals->changeStatus($dealId, $newStatus, $lostReason, $scope, $actorId);

            if (! $after instanceof DealSummary) {
                // Unreachable: the same scope found the row one statement ago,
                // inside this transaction.
                throw DealNotFound::of($dealId);
            }

            $this->audit->record(
                AuditEvent::of('DEAL_STATUS_CHANGED'),
                'deal',
                $dealId,
                ['status' => $before->status],
                ['status' => $after->status],
            );

            return $after;
        });
    }

    /**
     * `D-14`: "Delivery Complete can be confirmed by any of: Procurement ·
     * Sales owner · Team Leader · Manager." A caller already proved they may
     * change *some* status on this row (`change_status`); this asks the
     * narrower question `mark_delivery_complete` actually is.
     *
     * @throws AuthorizationRefused
     */
    private function authoriseDeliveryComplete(DealSummary $deal, string $actorId): void
    {
        $decision = $this->authorizeAction->authorize($actorId, 'deal', 'mark_delivery_complete');

        $deliveryScope = DealRowScope::resolve($decision->scopeValues(), $actorId);

        if ($deliveryScope->unrestricted) {
            return;
        }

        if ($deal->ownerId === null || ! in_array($deal->ownerId, $deliveryScope->ownerIds, true)) {
            throw AuthorizationRefused::of('deal', 'mark_delivery_complete');
        }
    }
}
