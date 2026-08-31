<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Approval;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Approval\DealApprovalRefused;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealSummary;
use Illuminate\Database\ConnectionInterface;

/**
 * Flow 3's decision point — one use case, two directions, on
 * `ArchiveCustomer`'s shape (Module 3 Point 3.4), with one deliberate
 * difference: this does **not** treat a repeated call as an idempotent
 * no-op. See {@see DealApprovalRefused} for why.
 *
 * ── Only a `pending` deal may be decided ────────────────────────────────────
 *
 * `approval_status = null` (Flow 1 — a Team-Leader-entered deal was never
 * submitted) and an already-`approved`/`rejected` deal both refuse with
 * `409 state_transition_invalid`, checked inside the same transaction the
 * write happens in so a concurrent decision cannot slip between the read and
 * the write.
 *
 * ── Rejection's reason is validated at the boundary, not here ──────────────
 *
 * §4.3's `rejection_reason` CHECK (Point 1.1) already makes a blank reason a
 * database error; `RejectDealRequest` refuses it as a 422 first, so this
 * class only ever sees a reason worth writing.
 */
final readonly class ReviewDealApproval
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them */
    public function approve(string $dealId, array $heldScopes, string $actorId): DealSummary
    {
        return $this->decide($dealId, 'approved', null, AuditEvent::of('DEAL_APPROVED'), $heldScopes, $actorId);
    }

    /** @param  list<string>  $heldScopes */
    public function reject(string $dealId, string $reason, array $heldScopes, string $actorId): DealSummary
    {
        return $this->decide($dealId, 'rejected', $reason, AuditEvent::of('DEAL_REJECTED'), $heldScopes, $actorId);
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     * @throws DealApprovalRefused when the deal is not currently `pending`
     */
    private function decide(
        string $dealId,
        string $newApprovalStatus,
        ?string $reason,
        AuditEvent $event,
        array $heldScopes,
        string $actorId,
    ): DealSummary {
        $scope = DealRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($dealId, $newApprovalStatus, $reason, $event, $scope, $actorId): DealSummary {
            $before = $this->deals->find($dealId, $scope);

            if (! $before instanceof DealSummary) {
                throw DealNotFound::of($dealId);
            }

            if ($before->approvalStatus !== 'pending') {
                throw DealApprovalRefused::notPending($dealId, $before->approvalStatus);
            }

            $after = $this->deals->reviewApproval($dealId, $newApprovalStatus, $reason, $scope, $actorId);

            if (! $after instanceof DealSummary) {
                // Unreachable: the same scope found the row one statement ago,
                // inside this transaction.
                throw DealNotFound::of($dealId);
            }

            $this->audit->record(
                $event,
                'deal',
                $dealId,
                ['approval_status' => $before->approvalStatus, 'rejection_reason' => $before->rejectionReason],
                ['approval_status' => $after->approvalStatus, 'rejection_reason' => $after->rejectionReason],
            );

            return $after;
        });
    }
}
