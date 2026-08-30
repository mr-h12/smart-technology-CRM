<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1` — 404 for a supplier that is not there.
 *
 * ── Only one of §5.1's two cases can arise here, and that is worth saying ──
 *
 * §5.1 covers "does not exist **or** is not visible to the caller". Module 3
 * needed both, because §3.3 scopes customers by owner. §3.7 gives every role
 * `Scope::All`, so a supplier is never invisible to a caller who reached this
 * far — the only way to arrive here is a row that is absent or soft-deleted.
 *
 * The response is identical to Module 3's on purpose. If §3.7 ever gains a
 * scope, the second case starts arriving at a handler already shaped for it,
 * rather than at one that would have to learn not to leak.
 */
final class SupplierNotFound extends RuntimeException
{
    private function __construct(public readonly string $supplierId)
    {
        parent::__construct('Supplier '.$supplierId.' was not found.');
    }

    public static function of(string $supplierId): self
    {
        return new self($supplierId);
    }

    /** Coding Standards §11: the sentence a caller reads lives in a lang file. */
    public function messageKey(): string
    {
        return 'suppliers.not_found';
    }
}
