<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Listing;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationDetail;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationListCriteria;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationPage;

/**
 * §7.2's Supplier Quotations screen — the detail (Point 2.3) and the list.
 *
 * `ListSuppliers`'s shape (Module 4), including its name: Modules 3, 4 and 5
 * each put the list and the detail on one `List…` class, and a second class
 * added later for the other half is how two modules end up disagreeing about
 * where a read lives. `handle()` lives here rather than in a new file.
 *
 * **The use case exists so that the 404 is not a controller's decision.**
 * `OpenAPI §5.1` fixes the answer for a row that is absent or out of reach, and
 * `ListSuppliers` states the consequence plainly: a decision in a controller is
 * a decision the next controller makes differently. The directory returns
 * `null`; the translation from `null` to §5.1 happens once, here.
 *
 * Thinner than `ListCustomers` by exactly the row scope §3.6 does not have.
 */
final readonly class ListSupplierQuotations
{
    public function __construct(private SupplierQuotationDirectoryInterface $quotations) {}

    /** @throws SupplierQuotationNotFound when the row is absent or soft-deleted */
    public function one(string $quotationId): SupplierQuotationDetail
    {
        $quotation = $this->quotations->find($quotationId);

        if (! $quotation instanceof SupplierQuotationDetail) {
            throw SupplierQuotationNotFound::of($quotationId);
        }

        return $quotation;
    }

    /**
     * §7.2's screen as a list — headers only, because `OpenAPI §6.2` tells a
     * collection not to return another unrestricted collection inside itself.
     * The lines are {@see self::one()}'s.
     *
     * **One delegation, and no 404.** An empty result is an empty page, not a
     * missing resource: `page=1` of a list with no rows is a valid request and
     * `SupplierQuotationPage::totalPages()` answers `1` for it. The `null`-to-
     * §5.1 translation that `one()` performs has nothing to translate here.
     *
     * No scope argument, unlike `ListCustomers` and `ListDeals`: §3.6 is "a
     * shared screen — not restricted by ownership", so every role that reaches
     * this method holds `Scope::All` and a scope parameter would be the
     * "parameter every caller passes the same value for".
     *
     * The criteria are parsed in Domain rather than by a Form Request, because
     * `OpenAPI §6.1`/§6.2 require `400 invalid_request` for a bad page size or
     * an unknown filter and a Form Request failure is a 422 — the same reading
     * `SupplierController::index()` records.
     */
    public function handle(SupplierQuotationListCriteria $criteria): SupplierQuotationPage
    {
        return $this->quotations->list($criteria);
    }
}
