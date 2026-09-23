<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Contracts;

use InvalidArgumentException;

/**
 * `D-81` (F-05 · 1.3): draw an accepted customer quotation's line from the
 * supplier line it was priced on. Published by Module 6 for Module 10.
 *
 * The caller is the customer quotation's `sent → accepted` transition
 * (`D-81`, F-05 · 1.5, built as Module 10 · 1.6 — `RespondToQuotation`), which
 * calls this once per line inside its own transaction and carries the balance
 * before and after in its audit entry.
 *
 * The quantity is a decimal string at `D-68`'s quantity scale; the key is
 * opaque here — Module 10 passes the accepted line's id, so a replayed
 * transition consumes nothing twice.
 */
interface SupplierItemQuantityInterface
{
    /**
     * Add `$quantity` to the line's `consumed_quantity`, once per `$idempotencyKey`.
     *
     * @return string the line's `consumed_quantity` after the call — unchanged on a replay
     *
     * @throws InvalidArgumentException on a quantity that is not positive, before any write
     */
    public function consume(string $supplierQuotationItemId, string $quantity, string $idempotencyKey): string;
}
