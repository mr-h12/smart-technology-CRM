<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Listing\Page;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ListEntryAlreadyExists;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Infrastructure\Eloquent\EnumListEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * `enum_lists`, mapped back into `ListEntry`.
 *
 * The ordering is `(position, code)` — the same pair the migration's index
 * covers. `position` is deliberately not unique (Point 1.3: no document says
 * what a tie means), so `code` is what makes the order total instead of leaving
 * two entries to swap places between requests.
 */
final readonly class EloquentManagedListRepository implements ManagedListRepositoryInterface
{
    public function entriesFor(ManagedList $list): array
    {
        $entries = [];

        $rows = EnumListEntry::query()
            ->where('list', $list->value)
            ->orderBy('position')
            ->orderBy('code')
            ->get();

        foreach ($rows as $row) {
            $entries[] = $this->map($row);
        }

        return $entries;
    }

    public function page(ManagedList $list, ListingQuery $query, bool $archived = false): Page
    {
        $rows = $this->scope($list, $archived)
            ->orderBy('position')
            ->orderBy('code')
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $this->map($row);
        }

        return new Page(
            items: $entries,
            total: $this->scope($list, $archived)->count(),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    /**
     * One list, live or withdrawn.
     *
     * Built twice per page — once for the rows and once for the total — because
     * an Eloquent builder is stateful and `count()` on the one that already
     * carries `offset`/`limit` counts the page rather than the list.
     *
     * @return Builder<EnumListEntry>
     */
    private function scope(ManagedList $list, bool $archived): Builder
    {
        $query = EnumListEntry::query()->where('list', $list->value);

        // `SoftDeletes` excludes withdrawn rows by default, so only the
        // archived view has to say anything at all.
        return $archived ? $query->onlyTrashed() : $query;
    }

    public function add(ManagedList $list, ListEntry $entry): void
    {
        $row = new EnumListEntry;
        $row->fill([
            'list' => $list->value,
            'code' => $entry->code(),
            'label_en' => $entry->labelEn(),
            'label_ar' => $entry->labelAr(),
            'position' => $entry->position(),
        ]);

        try {
            $row->save();
        } catch (QueryException $refused) {
            // 23505 is `unique_violation`, which on this table can only be
            // `enum_lists_code_unique_alive`. Read from the database's own
            // refusal rather than from a read-then-write check, which two
            // administrators adding the same sector at once would both pass.
            if ($refused->getCode() === '23505') {
                throw new ListEntryAlreadyExists($list, $entry->code());
            }

            throw $refused;
        }
    }

    public function archive(ManagedList $list, string $code): ?string
    {
        // The global scope `SoftDeletes` installs means an already-archived row
        // is not found here at all, so a second call reports absent rather than
        // re-stamping `deleted_at` and losing when the withdrawal happened.
        $row = EnumListEntry::query()
            ->where('list', $list->value)
            ->where('code', $code)
            ->first();

        if ($row === null) {
            return null;
        }

        $id = $row->id;

        // A soft delete: an UPDATE of `deleted_at` and nothing else (`DB-01`).
        $row->delete();

        // The id is returned rather than left for the caller to fetch, because
        // the caller would have to reach past this class into `enum_lists` to
        // get it — which is how `AddListEntry` ended up with a raw query in the
        // Application layer, and how this class nearly ended up with a second
        // copy of it.
        return $id;
    }

    public function restore(ManagedList $list, string $code): ?string
    {
        // `onlyTrashed()` is what makes "already live" and "never existed" the
        // same answer here: neither is in this set, so both fall out as null
        // and the caller gives one 404 for both — `OpenAPI §5.1`'s "do not
        // reveal which case applies".
        $row = EnumListEntry::onlyTrashed()
            ->where('list', $list->value)
            ->where('code', $code)
            ->first();

        if ($row === null) {
            return null;
        }

        $id = $row->id;

        // Clears `deleted_at` — and the partial unique index is
        // `(list, code) WHERE deleted_at IS NULL`, so this collides when the
        // freed code was taken while this row was withdrawn. Read from the
        // database's own refusal rather than a read-then-write check, exactly
        // as `add()` does and for the same reason: a check is a race.
        try {
            $row->restore();
        } catch (QueryException $refused) {
            if ($refused->getCode() === '23505') {
                throw new ListEntryAlreadyExists($list, $code);
            }

            throw $refused;
        }

        return $id;
    }

    private function map(EnumListEntry $row): ListEntry
    {
        return new ListEntry($row->code, $row->label_en, $row->label_ar, $row->position);
    }
}
