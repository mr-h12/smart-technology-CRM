<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Application\Listing\ReadPurchaseOrders;
use App\Modules\Quotations\Domain\Listing\PurchaseOrderNotFound;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\StoredFile;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Module 10 · 2.3 — `POST /purchase-orders/{id}/documents`, §17's upload flow
 * in `AttachDealDocument`'s shape: reach first (a 404 before the file is
 * read), then validate, store, write the row, link and audit in one commit,
 * and scan after it. Several files per order (owner, B1).
 *
 * The scopes are `quotation.record_customer_response`'s (Q10, `D-38`: a file's
 * permission is its parent's, and the order's file is attached under the
 * response that wrote it). The answer is the stored row, read back, so it is
 * the same shape the order's `documents` list gives.
 */
final readonly class AttachPurchaseOrderDocument
{
    public function __construct(
        private ReadPurchaseOrders $orders,
        private UploadValidatorInterface $validator,
        private StorageServiceInterface $storage,
        private FileWriterInterface $writer,
        private FileRepositoryInterface $files,
        private ScanStoredFile $scan,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws PurchaseOrderNotFound
     */
    public function handle(string $purchaseOrderId, string $sourcePath, string $originalName, array $heldScopes, string $actorId): StoredFile
    {
        $order = $this->orders->reachable($purchaseOrderId, $heldScopes, $actorId);

        $type = $this->validator->validate($sourcePath);
        $sizeBytes = $this->storage->sourceSizeBytes($sourcePath);
        $path = $this->storage->store(AttachmentParent::PurchaseOrder, $order->id, $type, $sourcePath);

        $fileId = $this->connection->transaction(function () use ($path, $originalName, $type, $sizeBytes, $order, $actorId): string {
            $fileId = $this->writer->create($path, $originalName, $type->mimeType(), $sizeBytes, $actorId);
            $this->writer->attach(AttachmentParent::PurchaseOrder, $order->id, $fileId);
            $this->audit->record(
                AuditEvent::of('PURCHASE_ORDER_DOCUMENT_ATTACHED'),
                'purchase_order',
                $order->id,
                null,
                ['file_id' => $fileId, 'original_name' => $originalName],
            );

            return $fileId;
        });

        try {
            $this->scan->scan($fileId);
        } catch (ScannerUnavailable) {
            // The upload committed; the row keeps its `pending` default, as
            // `AttachDealDocument` leaves it.
        }

        return $this->files->find($fileId)
            ?? throw new RuntimeException("files {$fileId} vanished after its own upload.");
    }
}
