<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Documents;

use DateTimeImmutable;

/**
 * A `files` row, as this module's own write sees it — `DealSummary`'s shape,
 * on the same reasoning: the wire format is this module's to decide, not
 * Storage's `StoredFile` reused wholesale (that type carries `path`, which
 * §17 keeps unexposed, and no `createdAt`, which the response needs).
 */
final readonly class DealDocument
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
