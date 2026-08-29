<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Archiving;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use Illuminate\Database\ConnectionInterface;

/**
 * Flow 7's manual archive and its restore.
 *
 * ── One use case, two directions ───────────────────────────────────────────
 *
 * §3.3 writes the permission as a single merged row, `archive / restore`, and
 * `PermissionMatrix` carries one `customer.archive` with no `customer.restore`
 * beside it. The two routes differ only in the boolean and the event name, so
 * splitting them into two classes would duplicate the scope resolution, the
 * transaction and the idempotence — three places for one rule to drift.
 *
 * ── Idempotent, and silent when nothing changed ────────────────────────────
 *
 * `OpenAPI §7.2` wants every action's "accepted current state" documented and
 * no source names one for this pair, so archiving an archived customer
 * succeeds and does nothing. It writes **no audit row** either: `AUD-03` keeps
 * entries permanently and immutably, so one describing a change that did not
 * happen is a permanent false record — and Flow 7's select-all restore makes
 * "some of these are already active" the ordinary case rather than the odd one.
 *
 * ── Archive is not delete ──────────────────────────────────────────────────
 *
 * Flow 7: "No customer is ever permanently deleted", and `DB-01` and §3.12
 * rule 3 say it for every business table. Nothing here touches `deleted_at`.
 */
final readonly class ArchiveCustomer
{
    public function __construct(
        private CustomerDirectoryInterface $customers,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them */
    public function archive(string $customerId, array $heldScopes, string $actorId): CustomerSummary
    {
        // `AUD-01` lists update among what must be recorded, and an archive is
        // an update. §3.12 rule 4's named event is the *restore*, not this.
        return $this->apply($customerId, true, AuditEvent::of('CUSTOMER_ARCHIVED'), $heldScopes, $actorId);
    }

    /** @param  list<string>  $heldScopes */
    public function restore(string $customerId, array $heldScopes, string $actorId): CustomerSummary
    {
        // §3.12 rule 4 — "restore from archive" is one of the nine entries that
        // must always exist, which is why `AuditEvent` already names this one.
        return $this->apply($customerId, false, AuditEvent::archiveRestored(), $heldScopes, $actorId);
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws CustomerNotFound when the row is absent **or** outside the caller's reach
     */
    private function apply(
        string $customerId,
        bool $archived,
        AuditEvent $event,
        array $heldScopes,
        string $actorId,
    ): CustomerSummary {
        $scope = CustomerRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($customerId, $archived, $event, $scope, $actorId): CustomerSummary {
            $before = $this->customers->find($customerId, $scope);

            if (! $before instanceof CustomerSummary) {
                // §5.1: 404 for absent **or** out of reach, never revealing
                // which. A Team Leader reaches here for every customer in the
                // company while `team` has no mechanism — the owner's deferral
                // of 2026-08-29, and the reason Flow 7's "Manager / TL only" is
                // currently half met.
                throw CustomerNotFound::of($customerId);
            }

            if ($before->isArchived === $archived) {
                // Already in the requested state. No write, so no `updated_by`
                // churn, and no audit row claiming a change.
                return $before;
            }

            $after = $this->customers->setArchived($customerId, $archived, $scope, $actorId);

            if (! $after instanceof CustomerSummary) {
                // Unreachable: the same scope found the row one statement ago,
                // inside this transaction. A silent 200 would hide the defect.
                throw CustomerNotFound::of($customerId);
            }

            // Inside the transaction (`DB-11`): the flag and its record commit
            // together or not at all.
            $this->audit->record(
                $event,
                'customer',
                $customerId,
                ['is_archived' => $before->isArchived],
                ['is_archived' => $after->isArchived],
            );

            return $after;
        });
    }
}
