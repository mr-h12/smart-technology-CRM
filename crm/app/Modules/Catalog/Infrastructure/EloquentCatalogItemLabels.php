<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use Illuminate\Database\ConnectionInterface;

/** F-16 · 1.1 — `EloquentCustomerNames`' shape over `catalog_items`. */
final readonly class EloquentCatalogItemLabels implements CatalogItemLabelsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function labelsOf(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        $labels = [];
        /** @var object{id: string, label: string|null} $row */
        foreach ($this->connection->table('catalog_items')
            ->whereIn('id', $catalogItemIds)
            ->selectRaw('id, coalesce(name, service_type) as label')
            ->get() as $row) {
            if ($row->label !== null) {
                $labels[(string) $row->id] = (string) $row->label;
            }
        }

        return $labels;
    }
}
