<?php

declare(strict_types=1);

namespace App\Modules\Deals\Infrastructure;

use App\Modules\Deals\Domain\Access\DealStatusTransition;
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
    /**
     * `(created_at AT TIME ZONE ?)::date` — the day a `timestamptz` falls on in the company's zone.
     */
    private const DAY = '(created_at AT TIME ZONE ?)::date';

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

    public function dealIdsOwnedBy(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $ids = [];
        /** @var object{id: string} $row */
        foreach ($this->live()->whereIn('owner_id', $ownerIds)->select('id')->get() as $row) {
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

    public function cardsOf(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $cards = [];
        /** @var object{id: string, code: string, status: string, customer_id: string} $row */
        foreach ($this->live()->whereIn('id', $dealIds)->select('id', 'code', 'status', 'customer_id')->get() as $row) {
            $cards[(string) $row->id] = ['code' => (string) $row->code, 'status' => (string) $row->status, 'customer_id' => (string) $row->customer_id];
        }

        return $cards;
    }

    public function statusCountsCreatedBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        /** @var array<string, int> $counts pdo_pgsql hands `count(*)` back as a native int */
        $counts = $this->live()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->all();

        // In pipeline order (§4.4), so the SPA and the PDF print what the server sends.
        return array_replace(array_intersect_key(array_flip(DealStatusTransition::statuses()), $counts), $counts);
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
    public function createdCountsByDay(string $zone, ?array $days = null): array
    {
        if ($days === []) {
            return [];
        }

        $query = $this->live()
            ->selectRaw('('.self::DAY.')::text AS day, owner_id, status, count(*) AS deals', [$zone])
            ->groupByRaw('1, owner_id, status')
            ->orderByRaw('1')->orderBy('owner_id')->orderBy('status');

        if ($days !== null) {
            $query->whereRaw('('.self::DAY.')::text IN ('.implode(', ', array_fill(0, count($days), '?')).')', [$zone, ...$days]);
        }

        $rows = [];
        /** @var object{day: string, owner_id: ?string, status: string, deals: int} $row */
        foreach ($query->get() as $row) {
            $rows[] = ['day' => $row->day, 'owner_id' => $row->owner_id, 'status' => $row->status, 'deals' => (int) $row->deals];
        }

        return $rows;
    }

    public function creationDaysTouchedSince(\DateTimeInterface $since, string $zone): array
    {
        /** @var list<string> $days */
        $days = $this->connection->table('deals')
            ->where(fn (Builder $q) => $q->where('updated_at', '>', $since)->orWhere('deleted_at', '>', $since))
            ->selectRaw('DISTINCT ('.self::DAY.')::text AS day', [$zone])
            ->orderBy('day')
            ->pluck('day')->all();

        return $days;
    }

    public function creationAndOwnerOf(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        $facts = [];
        /** @var object{id: string, created_at: string, owner_id: ?string} $row */
        foreach ($this->live()->whereIn('id', $dealIds)->select('id', 'created_at', 'owner_id')->get() as $row) {
            $facts[$row->id] = ['created_at' => $row->created_at, 'owner_id' => $row->owner_id];
        }

        return $facts;
    }

    public function openCountsByOwner(): array
    {
        // §4.4: `won` and every status after it, and `lost`, are past the sales decision.
        $statuses = DealStatusTransition::statuses();
        $closed = [...array_slice($statuses, (int) array_search('won', $statuses, true)), 'lost'];

        $rows = [];
        /** @var object{owner_id: string, open_deals: int} $row */
        foreach ($this->live()->whereNotNull('owner_id')->whereNotIn('status', $closed)
            ->groupBy('owner_id')->selectRaw('owner_id, count(*) AS open_deals')->orderBy('owner_id')->get() as $row) {
            $rows[] = ['owner_id' => $row->owner_id, 'open_deals' => (int) $row->open_deals];
        }

        return $rows;
    }

    private function live(): Builder
    {
        return $this->connection->table('deals')->whereNull('deleted_at');
    }
}
