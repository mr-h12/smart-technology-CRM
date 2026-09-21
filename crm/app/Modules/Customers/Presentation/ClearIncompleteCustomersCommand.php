<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Application\Writing\SaveCustomer;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use Illuminate\Console\Command;

/**
 * `D-87` ruling 3 (F-11 · 1.4) — the one-off correction, run once by the owner.
 *
 * No logic of its own: an empty edit through `SaveCustomer::update` is exactly
 * the correction — a flagged row that is complete gets `completed()` and its
 * `CUSTOMER_UPDATED` audit row, a row still missing a core field gets no write
 * and no audit row. A second run therefore changes nothing. The actor is
 * null: the system acts on its own behalf, the J-15 shape.
 *
 * ponytail: every flagged id is collected before the first write, because a
 * cleared row leaves the `is_incomplete` filter and would shift the pages
 * under a page-by-page loop. That holds the ids in memory — hundreds at
 * pilot scale, not millions.
 */
final class ClearIncompleteCustomersCommand extends Command
{
    protected $signature = 'customers:clear-incomplete';

    protected $description = 'D-87: clear the incomplete flag on imported customers that are complete today';

    public function handle(CustomerDirectoryInterface $customers, SaveCustomer $save): int
    {
        // Every row: the system holds §3.2's `all`, and nothing narrower exists for it.
        $scope = CustomerRowScope::resolve(['all'], null);
        $ids = [];

        for ($page = 1; ; $page++) {
            $result = $customers->list(new CustomerListCriteria(page: $page, perPage: CustomerListCriteria::MAX_PER_PAGE, isIncomplete: true), $scope);

            foreach ($result->items as $item) {
                $ids[] = $item->id;
            }

            if ($page >= $result->totalPages()) {
                break;
            }
        }

        $cleared = 0;

        foreach ($ids as $id) {
            if (! $save->update($id, [], ['all'], null)->customer->isIncomplete) {
                $cleared++;
            }
        }

        $this->info(sprintf('customers:clear-incomplete — %d of %d flagged customers cleared.', $cleared, count($ids)));

        return self::SUCCESS;
    }
}
