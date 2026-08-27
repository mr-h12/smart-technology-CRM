<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Domain\Reference\ManagedLists;
use App\Modules\Admin\Infrastructure\Eloquent\EnumListEntry;
use App\Support\Seeding\GuardedSeeder;
use Illuminate\Support\Facades\DB;

/**
 * `DB-05`'s four lists as rows: §4.2's sectors, §7.3's units and service types,
 * and delivery terms — which stay empty, deliberately.
 *
 * **Not test data.** `DEV-08` lists sectors and units beside roles and
 * permissions: a production database without them cannot record a customer or
 * a catalog item, so `seedsTestData()` is false.
 *
 * **Creates, never overwrites.** `IdempotentSeeder` defines repeatable as not
 * overwriting an edit somebody made deliberately, and this module's acceptance
 * criterion turns that from good manners into a requirement — a sector added in
 * settings must still be there after the next deployment, and a renamed label
 * must keep its new name. `updateOrCreate` would undo both.
 *
 * **Nothing is deleted.** An entry that disappears from `ManagedLists` is left
 * where it is: `DB-01` forbids physical deletion, and withdrawing a sector
 * customers are already filed under is a decision with consequences, not a
 * side-effect of running a seeder.
 */
final class ManagedListSeeder extends GuardedSeeder
{
    public function seedsTestData(): bool
    {
        // DEV-08 and AP-08: reference data, and production needs it.
        return false;
    }

    protected function seed(): void
    {
        // DB-11: one transaction. A half-seeded set of lists is a customer form
        // with three sectors and no way to tell that three are missing.
        DB::transaction(function (): void {
            foreach (ManagedList::cases() as $list) {
                foreach (ManagedLists::for($list) as $entry) {
                    EnumListEntry::query()->firstOrCreate(
                        ['list' => $list->value, 'code' => $entry->code()],
                        [
                            'label_en' => $entry->labelEn(),
                            'label_ar' => $entry->labelAr(),
                            'position' => $entry->position(),
                        ],
                    );
                }
            }
        });
    }
}
