<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Deals\Domain\Documents\DealDocument;

/**
 * How an attached document appears on the wire — `DealPayload`'s shape, on
 * the same reasoning. No `storage_path`: §17 keeps the layout unreachable
 * from outside Storage, and this is the boundary that would leak it.
 */
final class DealDocumentPayload
{
    /** @return array<string, mixed> */
    public static function of(DealDocument $document): array
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
