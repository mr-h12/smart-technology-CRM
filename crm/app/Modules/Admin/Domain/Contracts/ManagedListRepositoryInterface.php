<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Listing\Page;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ListEntryAlreadyExists;
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

    /**
     * One page of a list, for `GET /api/v1/managed-lists/{list}`.
     *
     * `OpenAPI §4.2` — *"an endpoint must never return an unbounded
     * collection"* — and `DB-05`'s lists are the definition of unbounded:
     * §4.2 and §7.3 both write *"(extendable)"*, which is this module's
     * acceptance criterion rather than a caveat.
     *
     * @return Page<ListEntry>
     */
    public function page(ManagedList $list, ListingQuery $query): Page;

    /**
     * Append one entry — the write that makes *"a new sector appears in the
     * customer form without a deployment"* true.
     *
     * @throws ListEntryAlreadyExists when the list already has that code
     */
    public function add(ManagedList $list, ListEntry $entry): void;
}
