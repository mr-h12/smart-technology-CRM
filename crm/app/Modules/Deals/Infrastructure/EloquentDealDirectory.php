<?php

declare(strict_types=1);

namespace App\Modules\Deals\Infrastructure;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\CustomerStatus\DealActivitySnapshot;
use App\Modules\Deals\Domain\Listing\DealListCriteria;
use App\Modules\Deals\Domain\Listing\DealPage;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Deals\Domain\Writing\DealDraft;
use App\Modules\Deals\Infrastructure\Eloquent\Deal;
use App\Support\Search\SearchIndex;
use App\Support\Search\SearchService;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * {@see DealDirectoryInterface} over `deals` — `EloquentCustomerDirectory`'s
 * shape (Module 3 Point 3.2), on the same reasoning.
 *
 * ── The scope is applied by construction, not by remembering ───────────────
 *
 * Every read starts from {@see self::scoped()}, the only builder factory here.
 * `SEC-08` is row-level security and a filter each method remembers to add is a
 * filter one method will forget — and the row that leaks is somebody else's
 * deal.
 *
 * ── `q` goes through `SearchService`, and narrows rather than replaces ─────
 *
 * `D-48` and `OpenAPI §6.2`: the free-text parameter "always passes through
 * `SearchService`". The service answers with **ids**, intersected with the
 * scoped query — a search can only ever shrink what the caller was already
 * allowed to see, never widen it.
 */
final readonly class EloquentDealDirectory implements DealDirectoryInterface
{
    /** §4.7: "DL-2026-0001". */
    private const CODE_PREFIX = 'DL';

    public function __construct(
        private SearchService $search,
        private ConnectionInterface $connection,
    ) {}

    public function list(DealListCriteria $criteria, DealRowScope $scope): DealPage
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return new DealPage([], 0, $criteria->page, $criteria->perPage);
        }

        $this->applyFilters($query, $criteria);

        // OpenAPI §6.1: "Pagination always happens **after** authorization
        // scoping" — the scope is already on the builder, and the count below
        // is taken from that same one rather than from a fresh query.
        $total = $query->count();

        foreach ($criteria->sorts as $sort) {
            $query->orderBy('deals.'.$sort['field'], $sort['descending'] ? 'desc' : 'asc');
        }

        // A deterministic tiebreak — two deals with the same last_activity_at
        // would otherwise page non-deterministically.
        $query->orderBy('deals.id');

        $rows = $query->offset($criteria->offset())->limit($criteria->perPage)->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new DealPage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function find(string $dealId, DealRowScope $scope): ?DealSummary
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($dealId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function create(DealDraft $draft, string $actorId, ?string $approvalStatus): DealSummary
    {
        $row = new Deal;
        $row->fill($draft->attributes);

        $row->code = $this->nextCode();
        $row->approval_status = $approvalStatus;
        $row->last_activity_at = now();
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        // `status` is filled by the column's `DEFAULT 'lead'` (§4.4, Flow 1
        // step 2) and nothing here sets it — refreshed for the same reason
        // `EloquentCustomerDirectory::create()` refreshes after an insert whose
        // defaults matter to the caller.
        $row->refresh();

        return self::hydrate($row);
    }

    public function update(string $dealId, DealDraft $draft, DealRowScope $scope, string $actorId): ?DealSummary
    {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($dealId)->first();

        if ($row === null) {
            return null;
        }

        $row->fill($draft->attributes);
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row);
    }

    public function reviewApproval(
        string $dealId,
        string $newApprovalStatus,
        ?string $rejectionReason,
        DealRowScope $scope,
        string $actorId,
    ): ?DealSummary {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($dealId)->first();

        if ($row === null) {
            return null;
        }

        $row->approval_status = $newApprovalStatus;
        $row->rejection_reason = $rejectionReason;
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row);
    }

    public function changeStatus(
        string $dealId,
        string $newStatus,
        ?string $lostReason,
        DealRowScope $scope,
        string $actorId,
    ): ?DealSummary {
        $query = $this->scoped($scope);

        if ($query === null) {
            return null;
        }

        $row = $query->whereKey($dealId)->first();

        if ($row === null) {
            return null;
        }

        $row->status = $newStatus;
        $row->lost_reason = $lostReason;
        $row->last_activity_at = now();
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row);
    }

    public function activityForCustomer(string $customerId): array
    {
        return array_values(
            Deal::query()
                ->where('customer_id', $customerId)
                ->get(['status', 'last_activity_at'])
                ->map(static fn (Deal $row): DealActivitySnapshot => new DealActivitySnapshot(
                    status: $row->status,
                    lastActivityAt: new DateTimeImmutable((string) $row->last_activity_at?->toIso8601String()),
                ))
                ->all(),
        );
    }

    /**
     * §4.7's `DL-YYYY-NNNN`, allocated from `document_sequences` (Module 0) —
     * its first consumer.
     *
     * ── Why this is one statement and not a read-then-write ────────────────
     *
     * `SELECT last_value` followed by `UPDATE ... SET last_value = ? + 1` lets
     * two concurrent creates read the same value and both write the same next
     * one — exactly the duplicate `DB-04`'s `UNIQUE` on `deals.code` exists to
     * catch, but catching it there means the second caller's request fails
     * with a constraint violation instead of succeeding with the number it
     * should have gotten. `INSERT … ON CONFLICT … DO UPDATE … RETURNING` is
     * one round trip PostgreSQL executes under a single row lock, so the two
     * callers serialise on that row instead of racing past it — the same
     * "the database enforces it, not a job that repairs it" reasoning `D-71`
     * already gave for the file-attachment keys.
     *
     * Year comes from `now()`, not from the request: §4.7's example is
     * `DL-2026-0001` and `document_sequences` keys on `(prefix, year)`
     * (Module 0), so a boundary crossing midnight on 31 December starts a new
     * count on 1 January rather than the year 2026 counting forever.
     */
    private function nextCode(): string
    {
        $year = (int) now()->format('Y');

        $row = $this->connection->selectOne(
            'insert into document_sequences (prefix, year, last_value) values (?, ?, 1) '
            .'on conflict (prefix, year) do update '
            .'set last_value = document_sequences.last_value + 1 '
            .'returning last_value',
            [self::CODE_PREFIX, $year],
        );

        $value = is_object($row) ? ($row->last_value ?? null) : null;

        if (! is_int($value) && ! is_string($value)) {
            // Unreachable in production — the statement above always returns
            // exactly one row — and refusing loudly here is cheaper than a
            // code silently formatted as "DL-2026-".
            throw new RuntimeException('document_sequences did not return a last_value for DL.');
        }

        return sprintf('%s-%d-%04d', self::CODE_PREFIX, $year, (int) $value);
    }

    /**
     * The scoped builder, or null when the caller's reach selects no rows.
     *
     * Null rather than an always-false builder, so the caller returns early
     * instead of asking PostgreSQL a question whose answer is already known.
     *
     * @return Builder<Deal>|null
     */
    private function scoped(DealRowScope $scope): ?Builder
    {
        if ($scope->permitsNothing()) {
            return null;
        }

        $query = Deal::query();

        if (! $scope->unrestricted) {
            $query->whereIn('deals.owner_id', $scope->ownerIds);
        }

        return $query;
    }

    /** @param  Builder<Deal>  $query */
    private function applyFilters(Builder $query, DealListCriteria $criteria): void
    {
        if ($criteria->status !== null) {
            $query->where('deals.status', $criteria->status);
        }

        if ($criteria->serviceType !== null) {
            $query->where('deals.service_type', $criteria->serviceType);
        }

        if ($criteria->source !== null) {
            $query->where('deals.source', $criteria->source);
        }

        if ($criteria->approvalStatus !== null) {
            $query->where('deals.approval_status', $criteria->approvalStatus);
        }

        if ($criteria->q !== null) {
            $query->whereIn('deals.id', $this->search->search(SearchIndex::Deals, $criteria->q));
        }
    }

    private static function hydrate(Deal $row): DealSummary
    {
        return new DealSummary(
            id: $row->id,
            code: $row->code,
            customerId: $row->customer_id,
            title: $row->title,
            source: $row->source,
            serviceType: $row->service_type,
            status: $row->status,
            ownerId: $row->owner_id,
            approvalStatus: $row->approval_status,
            rejectionReason: $row->rejection_reason,
            lostReason: $row->lost_reason,
            lastActivityAt: new DateTimeImmutable((string) $row->last_activity_at?->toIso8601String()),
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }
}
