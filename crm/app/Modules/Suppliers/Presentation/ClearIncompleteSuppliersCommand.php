<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Presentation;

use App\Modules\Suppliers\Application\Writing\SaveSupplier;
use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Listing\SupplierListCriteria;
use Illuminate\Console\Command;

/**
 * `D-87` ruling 3 (F-11 · 1.4) — the one-off correction, run once by the owner.
 *
 * No logic of its own: an empty edit through `SaveSupplier::update` is exactly
 * the correction — a flagged row that is complete gets `completed()` and its
 * `SUPPLIER_UPDATED` audit row, a row still missing a core field gets no write
 * and no audit row. A second run therefore changes nothing. The actor is
 * null: the system acts on its own behalf, the J-15 shape.
 *
 * ponytail: every flagged id is collected before the first write, because a
 * cleared row leaves the `is_incomplete` filter and would shift the pages
 * under a page-by-page loop. That holds the ids in memory — hundreds at
 * pilot scale, not millions.
 */
final class ClearIncompleteSuppliersCommand extends Command
{
    protected $signature = 'suppliers:clear-incomplete';

    protected $description = 'D-87: clear the incomplete flag on imported suppliers that are complete today';

    public function handle(SupplierDirectoryInterface $suppliers, SaveSupplier $save): int
    {
        $ids = [];

        for ($page = 1; ; $page++) {
            $result = $suppliers->list(new SupplierListCriteria(page: $page, perPage: SupplierListCriteria::MAX_PER_PAGE, isIncomplete: true));

            foreach ($result->items as $item) {
                $ids[] = $item->id;
            }

            if ($page >= $result->totalPages()) {
                break;
            }
        }

        $cleared = 0;

        foreach ($ids as $id) {
            if (! $save->update($id, [], null)->isIncomplete) {
                $cleared++;
            }
        }

        $this->info(sprintf('suppliers:clear-incomplete — %d of %d flagged suppliers cleared.', $cleared, count($ids)));

        return self::SUCCESS;
    }
}
