<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Assignment;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Flow 10 — "change the sales owner → the customer and their full history move
 * → both employees notified → audit entry written".
 *
 * ── What "their full history" means in Module 3 ────────────────────────────
 *
 * Nothing is copied and nothing is rewritten, because the customer's id does
 * not change: the audit trail is keyed to that id, and every later module's
 * deal, quotation and visit will hang off it too. A transfer that moved rows
 * between owners would be the version of this flow that loses history.
 *
 * ── ⚠️ Nobody is notified, and that is the owner's ruling ──────────────────
 *
 * Flow 10's third clause has no mechanism in the MVP: §18.1 limits the MVP to
 * badge counters — *Requests · Approvals · Reports · My Quotations*, with
 * customers not among them — and §18.2, "Email — The Only Exception", is a
 * closed list of five (`MAIL-01`…`MAIL-05`) that a reassignment is not on.
 * §18.3 puts the notification centre post-MVP. Recorded in `CHECKLIST.md` as a
 * narrowing awaiting a `D-xx` (owner, 2026-08-30) rather than resolved here.
 *
 * ── Idempotent, and silent when nothing changed ────────────────────────────
 *
 * Point 3.4's rule and Point 3.4's reason: `AUD-03` keeps entries permanently
 * and immutably, so an entry describing a change that did not happen is a
 * permanent false record.
 */
final readonly class AssignCustomer
{
    public function __construct(
        private CustomerDirectoryInterface $customers,
        private UserDirectoryInterface $users,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws CustomerNotFound when the row is absent **or** outside the caller's reach
     * @throws ValidationException when the named owner is not a user of this system
     */
    public function handle(string $customerId, string $newOwnerId, array $heldScopes, string $actorId): CustomerSummary
    {
        if ($this->users->find($newOwnerId) === null) {
            // Asked of Identity's contract rather than of `users`: `CLAUDE.md`
            // forbids the direct cross-module read, and `SaveCustomer` already
            // pays exactly this price on create. The contract answers null for
            // a hidden account too, so §3.12 rule 6's Super Admin cannot be
            // made an owner through a guessed id.
            throw ValidationException::withMessages([
                'sales_owner_id' => [(string) __('customers.validation.unknown_owner')],
            ]);
        }

        $scope = CustomerRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($customerId, $newOwnerId, $scope, $actorId): CustomerSummary {
            // Read inside the transaction and through the same scope the write
            // uses, so `AUD-02`'s old value is the one this write replaced.
            $before = $this->customers->find($customerId, $scope);

            if (! $before instanceof CustomerSummary) {
                // §5.1: 404 for absent **or** out of reach, never revealing
                // which. A Team Leader reaches here for every customer in the
                // company while `team` has no mechanism (owner's deferral,
                // 2026-08-29), which is why half of §3.3's `assign` row is
                // currently unreachable.
                throw CustomerNotFound::of($customerId);
            }

            if ($before->salesOwnerId === $newOwnerId) {
                // Already theirs. No write, so no `updated_by` churn, and no
                // audit row claiming a transfer that did not happen.
                return $before;
            }

            $after = $this->customers->update(
                $customerId,
                CustomerDraft::forAssignment($newOwnerId),
                $scope,
                $actorId,
            );

            if (! $after instanceof CustomerSummary) {
                // Unreachable: the same scope found the row one statement ago,
                // inside this transaction. A silent 200 would hide the defect.
                throw CustomerNotFound::of($customerId);
            }

            // Inside the transaction (`DB-11`): §3.12 rule 4 names "customer
            // reassignment" among the nine entries that must always exist, so
            // the transfer and its record commit together or not at all.
            $this->audit->record(
                AuditEvent::customerReassigned(),
                'customer',
                $customerId,
                ['sales_owner_id' => $before->salesOwnerId],
                ['sales_owner_id' => $after->salesOwnerId],
            );

            return $after;
        });
    }
}
