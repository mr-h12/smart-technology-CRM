<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

/**
 * What {@see PriceQuotation::price()} hands back: §5's computed header fields
 * for {@see \App\Modules\Quotations\Domain\Writing\QuotationDraft::withComputed()},
 * the two child tables' rows for `withLines()`, and §5.6's warnings.
 */
final readonly class PricedQuotation
{
    /**
     * @param  array<string, mixed>  $computed
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $additionalItems
     * @param  list<int>  $quantityWarnings  1-based line numbers
     */
    public function __construct(
        public array $computed,
        public array $items,
        public array $additionalItems,
        public array $quantityWarnings,
    ) {}
}
