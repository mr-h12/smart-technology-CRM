<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Documents;

use DateTimeImmutable;

/**
 * §7.2's `pdf_file` — "Scan or PDF of the offer" — as this module reports it
 * back after an upload. `DealDocument`'s shape (Module 5 Point 4.1), field for
 * field, because both describe the same `files` row through `D-71`'s pivot and
 * a second vocabulary for one table would be a second thing to keep in step.
 *
 * `scanStatus` is the enum's **value**, not the enum: `ScanStatus` lives in
 * Storage's Domain, and this DTO crosses into Presentation where a payload
 * serialises it. Carrying the string is what `DealDocument` does and it keeps
 * the serialiser from having to know Storage's vocabulary.
 */
final readonly class SupplierQuotationDocument
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public string $scanStatus,
        public DateTimeImmutable $createdAt,
    ) {}
}
