<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Assignment;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Deals\Domain\Writing\DealDraft;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * §3.4's `assign_owner` — `AssignCustomer`'s shape (Module 3 Point 3.5), on
 * the same reasoning throughout.
 *
 * ── §3.4 grants this to Manager (`All`) and Team Leader (`Team`) only ──────
 *
 * `Team` has no mechanism (Point 2.1), so a Team Leader holding only that
 * scope reaches this use case for every deal in the company while
 * `DealRowScope` finds none of them — the same half-unreachable permission
 * row `AssignCustomer` already documented for Customers' own `Team` grant.
 * Not resolved here; the debt is Point 2.1's, not this one's.
 *
 * ── Nobody is notified, on §18's same closed list ──────────────────────────
 *
 * No flow names notifying anyone of a deal reassignment, and even if one did,
 * §18.1 limits the MVP to badge counters (Requests, Approvals, Reports, My
 * Quotations — deals reassigned is not among them) and §18.2's email list is
 * closed at five names, none of them this.
 *
 * ── Idempotent, and silent when nothing changed ────────────────────────────
 *
 * `AUD-03` keeps entries permanently and immutably, so an entry describing a
 * change that did not happen is a permanent false record.
 */
final readonly class AssignDeal
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private UserDirectoryInterface $users,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     * @throws ValidationException when the named owner is not a user of this system
     */
    public function handle(string $dealId, string $newOwnerId, array $heldScopes, string $actorId): DealSummary
    {
        if ($this->users->find($newOwnerId) === null) {
            throw ValidationException::withMessages([
                'owner_id' => [(string) __('deals.validation.unknown_owner')],
            ]);
        }

        $scope = DealRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($dealId, $newOwnerId, $scope, $actorId): DealSummary {
            $before = $this->deals->find($dealId, $scope);

            if (! $before instanceof DealSummary) {
                throw DealNotFound::of($dealId);
            }

            if ($before->ownerId === $newOwnerId) {
                // Already theirs. No write, so no audit row claiming a
                // transfer that did not happen.
                return $before;
            }

            $after = $this->deals->update($dealId, DealDraft::forAssignment($newOwnerId), $scope, $actorId);

            if (! $after instanceof DealSummary) {
                // Unreachable: the same scope found the row one statement ago,
                // inside this transaction.
                throw DealNotFound::of($dealId);
            }

            $this->audit->record(
                AuditEvent::of('DEAL_REASSIGNED'),
                'deal',
                $dealId,
                ['owner_id' => $before->ownerId],
                ['owner_id' => $after->ownerId],
            );

            return $after;
        });
    }
}
