<?php

declare(strict_types=1);

namespace App\Modules\Deals\Infrastructure;

use App\Modules\Deals\Domain\Contracts\DealFacts;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Deals\Domain\Contracts\DealTitlesInterface;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * {@see DealFactsInterface} over `deals.owner_id`, `deals.customer_id` and
 * `deals.code`, and {@see DealTitlesInterface} over `deals.title` (Module 9
 * Point 2.3 — one reader of one table rather than a second class repeating
 * the `DB-01` filter) — the code fragment through `SearchService` (`D-88`).
 *
 * Columns by primary key through the query builder, on
 * {@see \App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemPricing}'s
 * shape: nothing here needs a hydrated model, and `whereNull('deleted_at')` is
 * `DB-01` spelled out where the model's global scope would otherwise do it.
 */
final readonly class EloquentDealFacts implements DealFactsInterface, DealTitlesInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private SearchService $search,
    ) {}

    public function factsOf(string $dealId): ?DealFacts
    {
        /** @var object{owner_id: string|null, customer_id: string}|null $row */
        $row = $this->live()
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

    public function dealIdsOwnedBy(string $ownerId): array
    {
        $ids = [];
        /** @var object{id: string} $row */
        foreach ($this->live()->where('owner_id', $ownerId)->select('id')->get() as $row) {
            $ids[] = (string) $row->id;
        }

        return $ids;
    }

    public function ownersOf(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $owners = [];
        /** @var object{id: string, owner_id: string|null} $row */
        foreach ($this->live()->whereIn('id', $dealIds)->select('id', 'owner_id')->get() as $row) {
            $owners[(string) $row->id] = $row->owner_id === null ? null : (string) $row->owner_id;
        }

        return $owners;
    }

    public function dealIdsMatchingCode(string $fragment): array
    {
        return $this->search->search(SearchIndex::Deals, $fragment);
    }

    public function codesOf(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $codes = [];
        /** @var object{id: string, code: string} $row */
        foreach ($this->live()->whereIn('id', $dealIds)->select('id', 'code')->get() as $row) {
            $codes[(string) $row->id] = (string) $row->code;
        }

        return $codes;
    }

    public function titlesOf(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $titles = [];
        /** @var object{id: string, title: string|null} $row */
        foreach ($this->live()->whereIn('id', $dealIds)->select('id', 'title')->get() as $row) {
            $title = $row->title === null ? '' : trim((string) $row->title);

            if ($title !== '') {
                $titles[(string) $row->id] = $title;
            }
        }

        return $titles;
    }

    /** `deals` minus `DB-01`'s soft-deleted rows — the one filter every read here shares. */
    private function live(): Builder
    {
        return $this->connection->table('deals')->whereNull('deleted_at');
    }
}
