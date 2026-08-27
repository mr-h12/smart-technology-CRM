<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Infrastructure\Eloquent\EnumListEntry;

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
            $entries[] = new ListEntry($row->code, $row->label_en, $row->label_ar, $row->position);
        }

        return $entries;
    }
}
