<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/** One `quotation_additional_items` row (Point 1.4) as `GET /{id}` reads it — never taxed (`D-62`). */
final readonly class QuotationAdditionalLine
{
    public function __construct(
        public string $id,
        public int $lineNo,
        public string $description,
        public string $amount,
    ) {}
}
