<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderDetail;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderListCriteria;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderNotFound;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderPage;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderRecord;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;

/**
 * Module 10 · 2.2 — the purchase orders' read side. An order carries no
 * permission of its own: it is read under `quotation.view`, with that grant's
 * row scope applied through the quotation's deal (Q10, `D-91`) — in the query
 * for the list, and here for the one, as `ShowQuotation::one()` does.
 */
final readonly class ReadPurchaseOrders
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private DealFactsInterface $deals,
        private CustomerNamesInterface $customers,
        private UserFactsInterface $users,
        private FileRepositoryInterface $files,
    ) {}

    /** @param  list<string>  $heldScopes */
    public function page(PurchaseOrderListCriteria $criteria, array $heldScopes, string $actorId): PurchaseOrderPage
    {
        $page = $this->quotations->purchaseOrders($criteria, QuotationRowScope::resolve($heldScopes, $actorId));
        $names = $this->customers->namesOf(array_values(array_unique(array_map(static fn (PurchaseOrderRecord $order): string => $order->customerId, $page->items))));

        return new PurchaseOrderPage($page->items, $page->total, $page->page, $page->perPage, $names);
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws PurchaseOrderNotFound unknown, deleted or out of reach alike (`OpenAPI §5.1`)
     */
    public function one(string $purchaseOrderId, array $heldScopes, string $actorId): PurchaseOrderDetail
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId);
        $order = $this->quotations->findPurchaseOrder($purchaseOrderId);
        $ownerId = $order === null ? null : $this->deals->factsOf($order->dealId)?->ownerId;

        if ($order === null || ! $scope->reaches($ownerId)) {
            throw PurchaseOrderNotFound::of($purchaseOrderId);
        }

        $names = $this->users->namesOf(array_values(array_filter([$ownerId, $order->createdBy], is_string(...))));

        return new PurchaseOrderDetail(
            order: $order,
            customerName: $this->customers->namesOf([$order->customerId])[$order->customerId] ?? null,
            dealCode: $this->deals->codesOf([$order->dealId])[$order->dealId] ?? null,
            dealOwnerId: $ownerId,
            dealOwnerName: $ownerId === null ? null : ($names[$ownerId] ?? null),
            createdByName: $order->createdBy === null ? null : ($names[$order->createdBy] ?? null),
            hasAttachment: $this->files->hasFiles(AttachmentParent::PurchaseOrder, $order->id),
        );
    }
}
