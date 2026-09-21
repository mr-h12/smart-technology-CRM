<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;
use App\Modules\Customers\Domain\Writing\CustomerWriteResult;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Support\Settings\SettingReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * §10's create and update, with §3.3's scope, `AUD-01`'s record and `D-35`'s
 * warning in one transaction each.
 *
 * ── A scope on `create` is not a `WHERE` ───────────────────────────────────
 *
 * §3.3's create row carries scopes — `All · All · Out · Own · Own · — · —` —
 * and there is no row yet to filter. The only thing a scope can constrain
 * before the row exists is **who it is filed under**, so that is what it
 * constrains: `all` files under anybody, `own` files under the actor and
 * refuses anybody else. The same {@see CustomerRowScope} answers it, which is
 * why `team`, `out` and `asgn` fail closed here exactly as they do on read —
 * a resolver that permits no rows cannot be asked to permit one new one.
 *
 * ── The audit is inside the transaction ────────────────────────────────────
 *
 * `DB-11`: the business write and its audit row commit together or not at all.
 * `AUD-01` names create and update explicitly, and this module's persistence
 * adapter writes through a module-aliased Eloquent model — which
 * `AuditEnforcementTest` cannot see. The register records that, and the reason
 * the recorder is held here rather than there is the one every other module
 * gives: an adapter has no actor and no event vocabulary.
 *
 * ── The duplicate probe runs after the write, never before it ──────────────
 *
 * `D-35` is *"warning only; the employee decides"*, so nothing about the probe
 * may change whether the record is saved. Running it afterwards makes that
 * structural rather than a rule somebody has to keep: there is no branch in
 * which a similar name can stop the write.
 */
final readonly class SaveCustomer
{
    /**
     * Admin's `SystemLimit::CustomerSimilarityThreshold`, transcribed.
     *
     * `deptrac.modules.yaml` does not let `Customers` reference `Admin`, and
     * `SettingReader` exists in `App\Support` precisely so a consumer never
     * learns the implementing module's name. The same arrangement
     * `AuthenticateUser` uses for the lockout limit.
     */
    private const SIMILARITY_THRESHOLD = 'limits.customer_similarity_threshold';

    public function __construct(
        private CustomerDirectoryInterface $customers,
        private AuditRecorderInterface $audit,
        private SettingReader $settings,
        private UserDirectoryInterface $users,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function create(array $validated, array $heldScopes, string $actorId): CustomerWriteResult
    {
        $scope = CustomerRowScope::resolve($heldScopes, $actorId);

        $draft = CustomerDraft::forCreate(
            $this->ownedWithinScope($validated, $scope, $actorId),
        );

        $customer = $this->connection->transaction(function () use ($draft, $actorId): CustomerSummary {
            $customer = $this->customers->create($draft, $actorId);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create", and an empty array would read as "it used to be
            // nothing", which is a different claim.
            $this->audit->record(
                AuditEvent::of('CUSTOMER_CREATED'),
                'customer',
                $customer->id,
                null,
                $draft->attributes,
            );

            return $customer;
        });

        return new CustomerWriteResult($customer, $this->similarTo($draft, $scope, $customer));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $heldScopes
     * @param  string|null  $actorId  null when the system acts on its own behalf (`D-87`'s correction; the J-15 shape); `own` cannot be resolved for it
     *
     * @throws CustomerNotFound when the row is absent **or** outside the caller's reach
     */
    public function update(string $customerId, array $validated, array $heldScopes, ?string $actorId): CustomerWriteResult
    {
        $scope = CustomerRowScope::resolve($heldScopes, $actorId);
        $draft = CustomerDraft::forUpdate($validated);

        $customer = $this->connection->transaction(function () use ($customerId, $draft, $scope, $actorId): CustomerSummary {
            // Read inside the transaction, and through the same scope the write
            // uses: the old values `AUD-02` requires must be the ones this write
            // actually replaced, not the ones some earlier read happened to see.
            $before = $this->customers->find($customerId, $scope);

            if (! $before instanceof CustomerSummary) {
                throw CustomerNotFound::of($customerId);
            }

            // `D-87`: an edit that leaves every `EXPECTED` field filled clears
            // the importer's flag. Clear only — a row the importer did not
            // flag is never flagged here, whatever the edit empties (`D-31`).
            if ($before->isIncomplete && self::isComplete($before, $draft)) {
                $draft = $draft->completed();
            }

            $after = $this->customers->update($customerId, $draft, $scope, $actorId);

            if (! $after instanceof CustomerSummary) {
                // Unreachable through the scope above; a row that vanished
                // between two statements of one transaction is a defect, and a
                // silent 200 would hide it.
                throw CustomerNotFound::of($customerId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('CUSTOMER_UPDATED'),
                    'customer',
                    $customerId,
                    self::changedFrom($before, $draft),
                    $draft->attributes,
                );
            }

            return $after;
        });

        return new CustomerWriteResult($customer, $this->similarTo($draft, $scope, $customer));
    }

    /**
     * §3.3's create scope, read as the owner the record may be filed under.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function ownedWithinScope(array $validated, CustomerRowScope $scope, string $actorId): array
    {
        if ($scope->permitsNothing()) {
            // `team`, `out` and `asgn` have no mechanism (owner's deferral,
            // 2026-08-29). Refusing is the safe half of an undefined rule; the
            // alternative is filing a record under a rule nobody has written.
            throw AuthorizationRefused::of('customer', 'create');
        }

        $requested = $validated['sales_owner_id'] ?? null;

        if ($requested !== null) {
            // The column carries a foreign key to `users`, so an id nobody owns
            // is a constraint violation — a 500 for what is plainly a bad
            // field. Asked of Identity's contract rather than of `users`:
            // `CLAUDE.md` forbids the direct cross-module read, and Point 3.2
            // already paid this price for §10.1's filter.
            if (! is_string($requested) || $this->users->find($requested) === null) {
                throw ValidationException::withMessages([
                    'sales_owner_id' => [(string) __('customers.validation.unknown_owner')],
                ]);
            }
        }

        if ($scope->unrestricted) {
            return $validated;
        }

        if ($requested === null) {
            // `own` and no owner named: the actor is the owner. Leaving it null
            // would create a row its own creator cannot then read.
            $validated['sales_owner_id'] = $actorId;

            return $validated;
        }

        // `own`: the actor's id is the only one in `ownerIds`, so this is
        // §3.3's create scope read as "themselves and nobody else".
        if (! in_array($requested, $scope->ownerIds, true)) {
            throw AuthorizationRefused::of('customer', 'create');
        }

        return $validated;
    }

    /**
     * §10.2's probe, or nothing at all while `OD-08` is unanswered.
     *
     * @return list<CustomerSummary>
     */
    private function similarTo(CustomerDraft $draft, CustomerRowScope $scope, CustomerSummary $written): array
    {
        $name = $draft->name();

        if ($name === null) {
            // A write that does not touch the name cannot have created a
            // duplicate name.
            return [];
        }

        $threshold = $this->settings->decimal(self::SIMILARITY_THRESHOLD);

        if ($threshold === null) {
            // `OD-08` is open and the limit is unseeded by the owner's decision
            // of 2026-08-30. No threshold means no warning — not a guessed one.
            return [];
        }

        return $this->customers->similarTo($name, $threshold, $scope, $written->id);
    }

    /**
     * The values this write replaced, limited to the fields it actually touched.
     *
     * `AUD-02` wants the old value beside the new one. Recording the whole row
     * would bury the one field that changed among fifteen that did not.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(CustomerSummary $before, CustomerDraft $draft): array
    {
        $previous = [
            'name' => $before->name,
            'sector' => $before->sector,
            'region' => $before->region,
            'contact_person' => $before->contactPerson,
            'phone' => $before->phone,
            'phone2' => $before->phone2,
            'whatsapp' => $before->whatsapp,
            'email' => $before->email,
            'start_date' => $before->startDate?->format('Y-m-d'),
            'notes' => $before->notes,
            'is_incomplete' => $before->isIncomplete,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $previous[$field] ?? null;
        }

        return $old;
    }

    /** The row as this write leaves it has every `D-87` core field filled. */
    private static function isComplete(CustomerSummary $before, CustomerDraft $draft): bool
    {
        $after = $draft->attributes + [
            'name' => $before->name,
            'sector' => $before->sector,
            'region' => $before->region,
            'contact_person' => $before->contactPerson,
            'phone' => $before->phone,
        ];

        foreach (CustomerDraft::EXPECTED as $field) {
            if (($after[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }
}
