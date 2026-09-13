<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationPage;

/**
 * §6.6's list with `SEC-08`'s reach applied once — `ListDeals::handle()`'s
 * shape: a controller that resolved its own scope would be a second answer to
 * the same question. The list never asks `ShowQuotation::revealsCosts()`
 * because its row (Q6) carries nothing that needs the grant.
 */
final readonly class ListQuotations
{
    public function __construct(private QuotationDirectoryInterface $quotations) {}

    /** @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them */
    public function handle(QuotationListCriteria $criteria, array $heldScopes, string $actorId): QuotationPage
    {
        return $this->quotations->list($criteria, QuotationRowScope::resolve($heldScopes, $actorId));
    }
}
