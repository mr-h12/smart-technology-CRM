<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Listing\SupplierNotFound;
use App\Modules\Suppliers\Domain\Listing\SupplierSummary;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
use Illuminate\Database\ConnectionInterface;

/**
 * §7.1's create and edit, each with `AUD-01`'s record in one transaction.
 *
 * ── The audit is inside the transaction ────────────────────────────────────
 *
 * `DB-11`: the business write and its audit row commit together or not at all.
 * `AUD-01` names create and update explicitly, and `D-45`'s mitigation for
 * opening catalog and supplier editing to every employee is precisely *"every
 * edit is written to the audit log"* — which makes this record load-bearing
 * rather than decorative.
 *
 * ── No scope, and no owner ─────────────────────────────────────────────────
 *
 * `SaveCustomer` resolves §3.3's create scope into the owner a record may be
 * filed under. §3.7 has no ownership and no scope: one permission,
 * `catalog.manage`, held at `Scope::All` by every operational role. The route's
 * middleware is the whole authorisation decision, so nothing is re-decided here.
 *
 * ── Deactivation is an update, deliberately ────────────────────────────────
 *
 * §3.7 puts "deactivate" in the same cell as "edit", so it is a field on the
 * row and arrives through this class like any other. `OpenAPI §7.2` reserves an
 * action suffix for what is "not a normal resource update"; `is_active` is one.
 * The event is therefore `SUPPLIER_UPDATED` and not a third verb — §3.12 rule
 * 4's mandatory "account deactivation" is `D-34`'s *employee* account, not this.
 */
final readonly class SaveSupplier
{
    public function __construct(
        private SupplierDirectoryInterface $suppliers,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public function create(array $validated, string $actorId): SupplierSummary
    {
        $draft = SupplierDraft::of($validated);

        return $this->connection->transaction(function () use ($draft, $actorId): SupplierSummary {
            $supplier = $this->suppliers->create($draft, $actorId);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create", and an empty array would read as "it used to be
            // nothing", which is a different claim.
            $this->audit->record(
                AuditEvent::of('SUPPLIER_CREATED'),
                'supplier',
                $supplier->id,
                null,
                $draft->attributes,
            );

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws SupplierNotFound when the row is absent or soft-deleted
     */
    public function update(string $supplierId, array $validated, string $actorId): SupplierSummary
    {
        $draft = SupplierDraft::of($validated);

        return $this->connection->transaction(function () use ($supplierId, $draft, $actorId): SupplierSummary {
            // Read inside the transaction: the old values `AUD-02` requires must
            // be the ones this write actually replaced, not the ones some
            // earlier read happened to see.
            $before = $this->suppliers->find($supplierId);

            if (! $before instanceof SupplierSummary) {
                throw SupplierNotFound::of($supplierId);
            }

            $after = $this->suppliers->update($supplierId, $draft, $actorId);

            if (! $after instanceof SupplierSummary) {
                // A row that vanished between two statements of one transaction
                // is a defect, and a silent 200 would hide it.
                throw SupplierNotFound::of($supplierId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('SUPPLIER_UPDATED'),
                    'supplier',
                    $supplierId,
                    self::changedFrom($before, $draft),
                    $draft->attributes,
                );
            }

            return $after;
        });
    }

    /**
     * The values this write replaced, limited to the fields it actually touched.
     *
     * `AUD-02` wants the old value beside the new one. Recording the whole row
     * would bury the one field that changed among six that did not.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(SupplierSummary $before, SupplierDraft $draft): array
    {
        $previous = [
            'name' => $before->name,
            'type' => $before->type,
            'color_rating' => $before->colorRating,
            'phone' => $before->phone,
            'contact_person' => $before->contactPerson,
            'has_open_account' => $before->hasOpenAccount,
            'is_active' => $before->isActive,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $previous[$field] ?? null;
        }

        return $old;
    }
}
