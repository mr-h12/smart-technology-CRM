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
     * @param  bool  $archived  the withdrawn set instead of the live one — the
     *                          collection a restore is chosen from, and the
     *                          only reader of `deleted_at` rows anywhere
     * @return Page<ListEntry>
     */
    public function page(ManagedList $list, ListingQuery $query, bool $archived = false): Page;

    /**
     * Append one entry — the write that makes *"a new sector appears in the
     * customer form without a deployment"* true.
     *
     * @throws ListEntryAlreadyExists when the list already has that code
     */
    public function add(ManagedList $list, ListEntry $entry): void;

    /**
     * Withdraw one entry, so the list stops offering it.
     *
     * **A soft delete and nothing else** (`DB-01`). The row stays, which is why
     * the verb here is *archive*: `entriesFor()` and `page()` already exclude a
     * withdrawn row, so the whole read half of this was true before the write
     * existed — the docblock above has said so since Point 2.2.
     *
     * **Nothing cascades.** There is no foreign key from a column that carries
     * one of these codes back to this table — PostgreSQL refuses one against
     * `(list, code) WHERE deleted_at IS NULL` — and none is wanted. Owner's
     * ruling of 2026-08-31: a row already filed under a code keeps it, and the
     * code merely stops being offered for new ones, which is the line §10.4
     * takes about a deactivated catalog item.
     *
     * The row's id comes back because the audit record needs it and this is the
     * layer that has the row in hand — `audit_log.entity_id` is a `UUID`
     * column, and a caller left to fetch it would have to reach past this
     * interface into `enum_lists` itself.
     *
     * @return string|null null when the list has no live entry with that code —
     *                     the caller turns that into the 404, because an
     *                     already-archived entry and one that never existed are
     *                     the same answer here
     */
    public function archive(ManagedList $list, string $code): ?string;

    /**
     * Put a withdrawn entry back, so the list offers it again.
     *
     * **The mirror of `archive()`, under the same authority.** §3.3 line 223
     * writes the permission row as a single merged `archive / restore`, and
     * §3.12 rule 4 names "restore from archive" among the mandatory audit
     * entries — so the act is one the documentation expects to exist, not a
     * convenience invented here.
     *
     * @return string|null null when the list has no **archived** entry with
     *                     that code — an entry that is already live and one
     *                     that never existed are the same answer, and the
     *                     caller turns both into the 404
     *
     * @throws ListEntryAlreadyExists when the freed code was taken while this
     *                                entry was withdrawn — the partial unique
     *                                index is on the live set, so the row
     *                                cannot come back to an occupied name
     */
    public function restore(ManagedList $list, string $code): ?string;
}
