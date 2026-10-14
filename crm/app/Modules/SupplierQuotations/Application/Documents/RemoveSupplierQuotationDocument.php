<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /supplier-quotations/{id}/documents/{file}` — D-117: whoever may
 * upload may remove, so the route carries `supplier_quotation.upload_attachment`.
 * A file that does not hang from this offer is the offer's own 404 (`SEC-08`).
 */
final readonly class RemoveSupplierQuotationDocument
{
    public function __construct(
        private SupplierQuotationDirectoryInterface $quotations,
        private FileWriterInterface $files,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @throws SupplierQuotationNotFound when the offer is absent, or the file does not hang from it */
    public function handle(string $quotationId, string $fileId, string $actorId): void
    {
        if ($this->quotations->find($quotationId) === null) {
            throw SupplierQuotationNotFound::of($quotationId);
        }

        $this->connection->transaction(function () use ($quotationId, $fileId, $actorId): void {
            $name = $this->files->remove(AttachmentParent::SupplierQuotation, $quotationId, $fileId, $actorId);

            if ($name === null) {
                throw SupplierQuotationNotFound::of($quotationId);
            }

            $this->audit->record(
                AuditEvent::of('SUPPLIER_QUOTATION_DOCUMENT_REMOVED'),
                'supplier_quotation',
                $quotationId,
                ['file_id' => $fileId, 'original_name' => $name],
            );
        });
    }
}
