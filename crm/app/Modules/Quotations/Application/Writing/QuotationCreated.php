<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/** What `CreateQuotation` returns: the quotation. (`D-161` retired the warnings it used to carry.) */
final readonly class QuotationCreated
{
    public function __construct(
        public QuotationSummary $quotation,
    ) {}
}
