<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Application\Listing\ReadPurchaseOrders;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderNotFound;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /purchase-orders/{id}/documents/{file}` — D-117: whoever may upload
 * may remove, whenever the order exists (the owner, 2026-10-14). The route
 * carries `quotation.record_customer_response`; reach is
 * `AttachPurchaseOrderDocument`'s. A file that does not hang from this order is
 * the order's own 404 (`SEC-08`).
 */
final readonly class RemovePurchaseOrderDocument
{
    public function __construct(
        private ReadPurchaseOrders $orders,
        private FileWriterInterface $files,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws PurchaseOrderNotFound when the order is absent or out of reach, or the file does not hang from it
     */
    public function handle(string $purchaseOrderId, string $fileId, array $heldScopes, string $actorId): void
    {
        $order = $this->orders->reachable($purchaseOrderId, $heldScopes, $actorId);

        $this->connection->transaction(function () use ($order, $fileId, $actorId): void {
            $name = $this->files->remove(AttachmentParent::PurchaseOrder, $order->id, $fileId, $actorId);

            if ($name === null) {
                throw PurchaseOrderNotFound::of($order->id);
            }

            $this->audit->record(
                AuditEvent::of('PURCHASE_ORDER_DOCUMENT_REMOVED'),
                'purchase_order',
                $order->id,
                ['file_id' => $fileId, 'original_name' => $name],
            );
        });
    }
}
