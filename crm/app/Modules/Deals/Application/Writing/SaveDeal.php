<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Application\CustomerStatus\RecomputeCustomerStatus;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Deals\Domain\Writing\DealDraft;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * §10's create and update, with §3.4's scope and `AUD-01`'s record in one
 * transaction each — `SaveCustomer`'s shape (Module 3 Point 3.3), on the same
 * reasoning throughout.
 *
 * ── A scope on `create` is not a `WHERE` ───────────────────────────────────
 *
 * §3.4's create row carries scopes — `All · All · Out · Own · Own · — · —` —
 * and there is no row yet to filter. The only thing a scope can constrain
 * before the row exists is **who it is filed under**, so that is what it
 * constrains, on `CustomerRowScope`'s reading: `all` files under anybody,
 * `own` files under the actor and refuses anybody else. `Out` fails closed
 * here exactly as it does on read — a resolver that permits no rows cannot be
 * asked to permit one new one.
 *
 * ── `approval_status` is derived from *who* is creating the deal ───────────
 *
 * Flow 1 has a Team Leader enter a deal and it starts at `Lead` with no
 * approval step. Flow 3 has an employee enter a *request*, and it "appears for
 * the Team Leader as Pending Approval". Neither flow is named on the request
 * itself — the only signal this use case has is the scope §3.4's `create` row
 * already grants by role: Manager and Team Leader hold `All`, Outdoor and
 * Indoor Sales hold `Own`. So `unrestricted` (`all`) creates with no approval
 * step (`approval_status = null`, matching a Team-Leader-entered deal that was
 * never submitted for one), and a scoped (`own`) create sets `pending`.
 *
 * ⚠️ **This is a recorded reading of an unstated rule, not a documented one.**
 * No source ties "holds the `All` create scope" to "skips approval" in as many
 * words; it is the only signal available that lines up with both flows as
 * written. **Flow 3 also says the request "stays inactive until approved"**,
 * and §4.3 has no visibility column for that — nothing here invents one. What
 * "inactive" means structurally is left to whichever point builds `/approve`
 * and `/reject`, which is where a pending deal's visibility must actually be
 * decided.
 *
 * ── The audit is inside the transaction ────────────────────────────────────
 *
 * `DB-11`: the business write, the `document_sequences` allocation it triggers,
 * and the audit row commit together or not at all.
 */
final readonly class SaveDeal
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private AuditRecorderInterface $audit,
        private UserDirectoryInterface $users,
        private ConnectionInterface $connection,
        private RecomputeCustomerStatus $customerStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function create(array $validated, array $heldScopes, string $actorId): DealSummary
    {
        $scope = DealRowScope::resolve($heldScopes, $actorId);

        $draft = DealDraft::forCreate(
            $this->ownedWithinScope($validated, $scope, $actorId),
        );

        // See the class docblock: `all` skips approval, `own` needs it — the
        // one signal available for which of Flow 1 or Flow 3 this create is.
        $approvalStatus = $scope->unrestricted ? null : 'pending';

        return $this->connection->transaction(function () use ($draft, $actorId, $approvalStatus): DealSummary {
            $deal = $this->deals->create($draft, $actorId, $approvalStatus);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create".
            $this->audit->record(
                AuditEvent::of('DEAL_CREATED'),
                'deal',
                $deal->id,
                null,
                $draft->attributes,
            );

            // §4.5: a brand-new deal can turn a `Deal Not Completed` or
            // `No Response` customer back into an active `Prospect` even
            // though no *status* changed — a deal simply started existing.
            $this->customerStatus->forCustomer($deal->customerId);

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $heldScopes
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     */
    public function update(string $dealId, array $validated, array $heldScopes, string $actorId): DealSummary
    {
        $scope = DealRowScope::resolve($heldScopes, $actorId);
        $draft = DealDraft::forUpdate($validated);

        return $this->connection->transaction(function () use ($dealId, $draft, $scope, $actorId): DealSummary {
            // Read inside the transaction, and through the same scope the
            // write uses: the old values `AUD-02` requires must be the ones
            // this write actually replaced.
            $before = $this->deals->find($dealId, $scope);

            if (! $before instanceof DealSummary) {
                throw DealNotFound::of($dealId);
            }

            $after = $this->deals->update($dealId, $draft, $scope, $actorId);

            if (! $after instanceof DealSummary) {
                // Unreachable through the scope above; a row that vanished
                // between two statements of one transaction is a defect.
                throw DealNotFound::of($dealId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('DEAL_UPDATED'),
                    'deal',
                    $dealId,
                    self::changedFrom($before, $draft),
                    $draft->attributes,
                );
            }

            return $after;
        });
    }

    /**
     * §3.4's create scope, read as the owner the record may be filed under —
     * `SaveCustomer::ownedWithinScope()`'s shape, on the same reasoning.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function ownedWithinScope(array $validated, DealRowScope $scope, string $actorId): array
    {
        if ($scope->permitsNothing()) {
            // `Out` has no mechanism (Point 2.1). Refusing is the safe half of
            // an undefined rule.
            throw AuthorizationRefused::of('deal', 'create');
        }

        $requested = $validated['owner_id'] ?? null;

        if ($requested !== null) {
            // Asked of Identity's contract rather than of `users`: `CLAUDE.md`
            // forbids the direct cross-module read.
            if (! is_string($requested) || $this->users->find($requested) === null) {
                throw ValidationException::withMessages([
                    'owner_id' => [(string) __('deals.validation.unknown_owner')],
                ]);
            }
        }

        if ($scope->unrestricted) {
            return $validated;
        }

        if ($requested === null) {
            // `own` and no owner named: the actor is the owner.
            $validated['owner_id'] = $actorId;

            return $validated;
        }

        // `own`: the actor's id is the only one in `ownerIds`.
        if (! in_array($requested, $scope->ownerIds, true)) {
            throw AuthorizationRefused::of('deal', 'create');
        }

        return $validated;
    }

    /**
     * The values this write replaced, limited to the fields it actually
     * touched — `SaveCustomer::changedFrom()`'s shape.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(DealSummary $before, DealDraft $draft): array
    {
        $previous = [
            'title' => $before->title,
            'source' => $before->source,
            'service_type' => $before->serviceType,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $previous[$field] ?? null;
        }

        return $old;
    }
}
