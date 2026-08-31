<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Listing\CatalogItemNotFound;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;
use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use Illuminate\Database\ConnectionInterface;

/**
 * §7.3's create and edit, each with `AUD-01`'s record in one transaction.
 *
 * ── The audit is the acceptance criterion, not a side effect ───────────────
 *
 * `D-45` opened catalog and supplier editing to every employee and named the
 * mitigation in the same breath: *"every edit is written to the audit log"*,
 * plus a monthly Team Leader review. The build plan turns that into Module 4's
 * criterion — "Every catalog edit is written to the audit log" — so this
 * record is the control the module rests on rather than a trace of it.
 *
 * `DB-11`: the business write and its audit row commit together or not at all.
 *
 * ── No scope, and no owner ─────────────────────────────────────────────────
 *
 * §3.7 has no ownership and no scope: one permission, `catalog.manage`, held
 * at `Scope::All` by every operational role. The route's middleware is the
 * whole authorisation decision, so nothing is re-decided here.
 *
 * ── Deactivation is an update, deliberately ────────────────────────────────
 *
 * §3.7 puts "deactivate" in the same cell as "edit", so it is a field on the
 * row and arrives through this class like any other. `OpenAPI §7.2` reserves
 * an action suffix for what is "not a normal resource update"; `is_active` is
 * one. What §10.4 makes it *mean* — the item keeps working on open quotations
 * and disappears from new selection lists — belongs to Modules 6 and 7, which
 * own those lists.
 */
final readonly class SaveCatalogItem
{
    public function __construct(
        private CatalogItemDirectoryInterface $items,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public function create(array $validated, string $actorId): CatalogItemSummary
    {
        $draft = CatalogItemDraft::of($validated);

        return $this->connection->transaction(function () use ($draft, $actorId): CatalogItemSummary {
            $item = $this->items->create($draft, $actorId);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create", and an empty array would read as "it used to be
            // nothing", which is a different claim.
            $this->audit->record(
                AuditEvent::of('CATALOG_ITEM_CREATED'),
                'catalog_item',
                $item->id,
                null,
                $draft->attributes,
            );

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws CatalogItemNotFound when the row is absent or soft-deleted
     */
    public function update(string $catalogItemId, array $validated, string $actorId): CatalogItemSummary
    {
        $draft = CatalogItemDraft::of($validated);

        return $this->connection->transaction(function () use ($catalogItemId, $draft, $actorId): CatalogItemSummary {
            // Read inside the transaction: the old values `AUD-02` requires must
            // be the ones this write actually replaced, not the ones some
            // earlier read happened to see.
            $before = $this->items->find($catalogItemId);

            if (! $before instanceof CatalogItemSummary) {
                throw CatalogItemNotFound::of($catalogItemId);
            }

            $after = $this->items->update($catalogItemId, $draft, $actorId);

            if (! $after instanceof CatalogItemSummary) {
                // A row that vanished between two statements of one transaction
                // is a defect, and a silent 200 would hide it.
                throw CatalogItemNotFound::of($catalogItemId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('CATALOG_ITEM_UPDATED'),
                    'catalog_item',
                    $catalogItemId,
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
     * would bury the one field that changed among nine that did not.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(CatalogItemSummary $before, CatalogItemDraft $draft): array
    {
        $previous = [
            'kind' => $before->kind,
            'name' => $before->name,
            'product_code' => $before->productCode,
            'category' => $before->category,
            'unit' => $before->unit,
            'service_type' => $before->serviceType,
            'company' => $before->company,
            'description' => $before->description,
            'notes' => $before->notes,
            'is_active' => $before->isActive,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $previous[$field] ?? null;
        }

        return $old;
    }
}
