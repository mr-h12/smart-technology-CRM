<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Quotations\Domain\Listing\QuotationDetail;

/**
 * What an edit hands back — the re-read quotation, because the client's
 * preview is not the source of truth (§5) and the new `etag` is what its next
 * `If-Match` must carry; plus §5.6's warnings, as the create reports them.
 */
final readonly class QuotationUpdated
{
    /** @param  list<int>  $quantityWarnings  1-based line numbers */
    public function __construct(
        public QuotationDetail $quotation,
        public array $quantityWarnings,
    ) {}
}
