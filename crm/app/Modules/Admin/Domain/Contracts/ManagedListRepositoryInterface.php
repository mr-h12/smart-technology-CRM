<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;

/**
 * `DB-05`'s lists, read from the database.
 *
 * **No implementation may answer from `ManagedLists`.** That class is the
 * canonical source the seeder loads once, and this module's acceptance
 * criterion is that *a new sector added in settings appears in the customer
 * form without a deployment* — which is only true if the reader consults the
 * table. A repository re-deriving its answer from code would return six sectors
 * forever, however many an administrator added.
 *
 * Entries come back in display order, and archived rows do not come back at all
 * (`DB-01`, `D-34`): deactivated means absent from every list that offers a
 * choice.
 */
interface ManagedListRepositoryInterface
{
    /** @return list<ListEntry> */
    public function entriesFor(ManagedList $list): array;
}
