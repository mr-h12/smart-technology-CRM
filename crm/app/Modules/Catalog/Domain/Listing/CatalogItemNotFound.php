<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1` — 404 for a catalog item that is not there.
 *
 * Only one of §5.1's two cases — "does not exist **or** is not visible to the
 * caller" — can arise. §3.7 gives every role in both its columns `Scope::All`,
 * so an item is never invisible to a caller who reached this far; the only way
 * here is a row that is absent or soft-deleted.
 *
 * The response is identical to Module 3's and to the supplier one on purpose.
 * If §3.7 ever gains a scope, the second case starts arriving at a handler
 * already shaped for it, rather than at one that would have to learn not to leak.
 */
final class CatalogItemNotFound extends RuntimeException
{
    private function __construct(public readonly string $catalogItemId)
    {
        parent::__construct('Catalog item '.$catalogItemId.' was not found.');
    }

    public static function of(string $catalogItemId): self
    {
        return new self($catalogItemId);
    }

    /** Coding Standards §11: the sentence a caller reads lives in a lang file. */
    public function messageKey(): string
    {
        return 'catalog.not_found';
    }
}
