<?php

declare(strict_types=1);

namespace App\Modules\Deals\Infrastructure;

use App\Modules\Deals\Domain\Contracts\DealFacts;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see DealFactsInterface} over `deals.owner_id` and `deals.customer_id`.
 *
 * Two columns by primary key through the query builder, on
 * {@see \App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemPricing}'s
 * shape: nothing here needs a hydrated model, and `whereNull('deleted_at')` is
 * `DB-01` spelled out where the model's global scope would otherwise do it.
 */
final readonly class EloquentDealFacts implements DealFactsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function factsOf(string $dealId): ?DealFacts
    {
        /** @var object{owner_id: string|null, customer_id: string}|null $row */
        $row = $this->connection->table('deals')
            ->whereNull('deleted_at')
            ->where('id', $dealId)
            ->select('owner_id', 'customer_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new DealFacts(
            ownerId: $row->owner_id === null ? null : (string) $row->owner_id,
            customerId: (string) $row->customer_id,
        );
    }
}
