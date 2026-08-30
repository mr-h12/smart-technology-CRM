<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Listing;

use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Listing\SupplierListCriteria;
use App\Modules\Suppliers\Domain\Listing\SupplierNotFound;
use App\Modules\Suppliers\Domain\Listing\SupplierPage;
use App\Modules\Suppliers\Domain\Listing\SupplierSummary;

/**
 * §8's Suppliers screen — the list and the detail.
 *
 * Thinner than `ListCustomers` by exactly the row scope §3.7 does not have.
 * The use case still exists rather than letting the controller hold the
 * directory: `OpenAPI §5.1`'s 404 rule is a decision, and a decision in a
 * controller is a decision the next controller makes differently.
 */
final readonly class ListSuppliers
{
    public function __construct(private SupplierDirectoryInterface $suppliers) {}

    public function handle(SupplierListCriteria $criteria): SupplierPage
    {
        return $this->suppliers->list($criteria);
    }

    /** @throws SupplierNotFound when the row is absent or soft-deleted */
    public function one(string $supplierId): SupplierSummary
    {
        $supplier = $this->suppliers->find($supplierId);

        if (! $supplier instanceof SupplierSummary) {
            throw SupplierNotFound::of($supplierId);
        }

        return $supplier;
    }
}
