<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use Illuminate\Database\ConnectionInterface;

/**
 * §7.2's edit — the header, its lines and `AUD-01`'s record in one transaction.
 * `SaveSupplier::update()`'s shape (Module 4), with this module's absences.
 *
 * ── `DB-11`: the whole edit, or none of it ─────────────────────────────────
 *
 * The header write, the soft-delete of the replaced lines, the insert of the
 * new ones and the audit row commit together. A line the database refuses takes
 * the header change and the old lines' removal with it, rather than leaving an
 * offer whose lines were deleted and never replaced.
 *
 * ── No scope, no `If-Match`, no recomputation ──────────────────────────────
 *
 * No scope, because §3.6 grants `Scope::All` to every role that may edit —
 * "a shared screen, not restricted by ownership" — so there is nothing for a
 * scope to constrain, and the route's `permission:supplier_quotation.create`
 * middleware is the whole authorisation decision (§3.6's write row is one cell,
 * "create / edit").
 *
 * No optimistic locking: `OpenAPI §9.2` scopes that pattern to *quotations* and
 * allows adopting it elsewhere "only through a documented contract update",
 * while §9.1 lists supplier quotations as a separate resource. Confirmed by the
 * owner 2026-09-02 and carried on the debt register.
 *
 * No recomputation of `total_price`: the owner ruled on 2026-09-02 that it is
 * entered, and an edit that replaced the lines would be the tempting place to
 * quietly start summing them.
 */
final readonly class UpdateSupplierQuotation
{
    public function __construct(
        private SupplierQuotationDirectoryInterface $quotations,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  already validated at the boundary
     *
     * @throws SupplierQuotationNotFound when the row is absent or soft-deleted
     */
    public function update(string $quotationId, array $validated, string $actorId): SupplierQuotationSummary
    {
        $draft = SupplierQuotationDraft::forUpdate($validated);

        return $this->connection->transaction(function () use ($quotationId, $draft, $actorId): SupplierQuotationSummary {
            // Read inside the transaction: `AUD-02`'s old values must be the
            // ones this write actually replaced, not the ones an earlier read
            // happened to see.
            $before = $this->quotations->find($quotationId);

            if ($before === null) {
                throw SupplierQuotationNotFound::of($quotationId);
            }

            $after = $this->quotations->update($quotationId, $draft, $actorId);

            if (! $after instanceof SupplierQuotationSummary) {
                // A row that vanished between two statements of one transaction
                // is a defect, and a silent 200 would hide it.
                throw SupplierQuotationNotFound::of($quotationId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('SUPPLIER_QUOTATION_UPDATED'),
                    'supplier_quotation',
                    $quotationId,
                    self::changedFrom($before->header, $draft),
                    self::submitted($draft),
                );
            }

            return $after;
        });
    }

    /**
     * The values this edit replaced, limited to the fields it actually touched.
     *
     * `AUD-02` wants the old value beside the new one. Recording the whole row
     * would bury the one field that changed among six that did not —
     * `SaveSupplier::changedFrom()`'s reasoning, and its shape.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(SupplierQuotationSummary $before, SupplierQuotationDraft $draft): array
    {
        $was = [
            'supplier_id' => $before->supplierId,
            'deal_id' => $before->dealId,
            'total_price' => $before->totalPrice,
            'currency_id' => $before->currencyId,
            'offer_date' => $before->offerDate,
            'valid_until' => $before->validUntil,
            'notes' => $before->notes,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $was[$field] ?? null;
        }

        return $old;
    }

    /**
     * What the caller asked for. `items` appears only when the caller named it,
     * so an audit row for a header-only edit does not claim the lines were
     * rewritten.
     *
     * @return array<string, mixed>
     */
    private static function submitted(SupplierQuotationDraft $draft): array
    {
        return $draft->items === null
            ? $draft->attributes
            : [...$draft->attributes, 'items' => $draft->items];
    }
}
