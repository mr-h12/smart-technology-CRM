<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Presentation;

use App\Modules\SupplierQuotations\Domain\Documents\SupplierQuotationDocument;

/**
 * How an attached offer document appears on the wire.
 *
 * `DealDocumentPayload`'s six fields, because both describe the same `files`
 * row through `D-71`'s pivot and a second vocabulary for one table would be a
 * second thing to keep in step. **No `storage_path`**: §17 keeps the storage
 * layout unreachable from outside Storage, and a payload is exactly where it
 * would leak.
 *
 * The two payloads are not shared for the reason deptrac enforces — Module 5's
 * Presentation is not reachable from Module 6's, and a cross-module import to
 * save six lines is the coupling that isolation exists to prevent.
 */
final class SupplierQuotationDocumentPayload
{
    /** @return array<string, mixed> */
    public static function of(SupplierQuotationDocument $document): array
    {
        return [
            'id' => $document->id,
            'original_name' => $document->originalName,
            'mime_type' => $document->mimeType,
            'size_bytes' => $document->sizeBytes,
            'scan_status' => $document->scanStatus,
            'created_at' => $document->createdAt->format(DATE_ATOM),
        ];
    }
}
