<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1`'s 404: unknown, deleted and out of the caller's reach are
 * one answer — "Do not reveal which case applies" (Module 10 · 2.2).
 */
final class PurchaseOrderNotFound extends RuntimeException
{
    private function __construct(public readonly string $purchaseOrderId)
    {
        parent::__construct('Purchase order '.$purchaseOrderId.' is not visible to this caller.');
    }

    public static function of(string $purchaseOrderId): self
    {
        return new self($purchaseOrderId);
    }

    public function messageKey(): string
    {
        return 'quotations.purchase_order_not_found';
    }
}
