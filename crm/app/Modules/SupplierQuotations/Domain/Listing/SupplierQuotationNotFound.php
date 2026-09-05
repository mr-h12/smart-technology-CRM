<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1` — 404 for a supplier quotation that is not there.
 *
 * `SupplierNotFound`'s shape (Module 4), and only one of §5.1's two cases can
 * produce it here for the same reason: §3.6 grants every role that may read
 * `Scope::All` under "a shared screen — not restricted by ownership", so an
 * offer is never merely invisible to a caller who got past the middleware. The
 * remaining way to arrive is a row that is absent or `DB-01` soft-deleted, and
 * those two must answer identically — §5.1 says "do not reveal which case
 * applies", and an archived offer that answered differently would confirm its
 * own existence.
 */
final class SupplierQuotationNotFound extends RuntimeException
{
    private function __construct(public readonly string $quotationId)
    {
        parent::__construct('Supplier quotation '.$quotationId.' was not found.');
    }

    public static function of(string $quotationId): self
    {
        return new self($quotationId);
    }

    /** Coding Standards §11: the sentence a caller reads lives in a lang file. */
    public function messageKey(): string
    {
        return 'supplier_quotations.not_found';
    }
}
