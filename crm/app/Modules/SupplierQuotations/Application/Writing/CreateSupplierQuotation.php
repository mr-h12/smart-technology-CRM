<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationSummary;
use App\Modules\SupplierQuotations\Domain\Writing\SupplierQuotationDraft;
use Illuminate\Database\ConnectionInterface;

/**
 * §7.2's create — the header, its lines and `AUD-01`'s record, in one
 * transaction. `SaveDeal`'s shape (Module 5 Point 2.3), with two differences
 * that are both §3.6's doing.
 *
 * ── No scope, and therefore no scope resolution ────────────────────────────
 *
 * `SaveDeal::create()` takes the caller's held scopes because §3.4's create row
 * carries them, and files the deal under an owner accordingly. §3.6 carries
 * none — every role that may create here holds `Scope::All`, under "a shared
 * screen — not restricted by ownership" — so there is no owner column to file
 * under and nothing for a scope to constrain. The permission itself is checked
 * at the route (`SEC-07`, §3.12 rule 1), which is Point 2.2's.
 *
 * ── `DB-11`: the whole offer, or none of it ────────────────────────────────
 *
 * The header, the `document_sequences` allocation its code comes from, every
 * line, and the audit row commit together. A line the database refuses — a
 * `catalog_item_id` no catalog item has — takes the header and the audit entry
 * with it rather than leaving a priced offer with half its lines, and
 * `CreateSupplierQuotationTest` proves that by making the second of two lines
 * fail.
 *
 * ── What this use case deliberately does not do ────────────────────────────
 *
 * It does not **compute** `total_price`: the owner ruled on 2026-09-02 that the
 * figure is entered by the user, which §7.2 permits by listing it as a field of
 * its own. It does not **check that the product exists** either — the foreign
 * key does, and turning that refusal into `OpenAPI §295`'s 422 is Point 2.2's
 * boundary. And it does not **create a missing product**: that is `D-22`, and
 * Step 3 builds it behind an interface rather than reaching into Catalog.
 */
final readonly class CreateSupplierQuotation
{
    public function __construct(
        private SupplierQuotationDirectoryInterface $quotations,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param  array<string, mixed>  $validated  already validated at the boundary */
    public function create(array $validated, string $actorId): SupplierQuotationSummary
    {
        $draft = SupplierQuotationDraft::forCreate($validated);

        return $this->connection->transaction(function () use ($draft, $actorId): SupplierQuotationSummary {
            $quotation = $this->quotations->create($draft, $actorId);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create". The lines go in beside the header's fields, because
            // what changed is the offer — an audit row naming only the header
            // would answer "what was created?" with half the answer.
            $this->audit->record(
                AuditEvent::of('SUPPLIER_QUOTATION_CREATED'),
                'supplier_quotation',
                $quotation->id,
                null,
                [...$draft->attributes, 'items' => $draft->items],
            );

            return $quotation;
        });
    }
}
