<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Reference;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Withdraw one entry from a `DB-05` list. The mirror of {@see AddListEntry}.
 *
 * **Why this exists at all.** `routes/api.php` used to say, in as many words,
 * that this module had "no `PATCH` and no `DELETE`", because `DB-01` forbids
 * physical deletion and withdrawing a sector customers are filed under has
 * consequences. The first half of that was always about a *hard* delete, which
 * this is not; the second half was a decision, and the owner has now made the
 * other one (2026-08-31, recorded in `CHECKLIST.md` awaiting a `D-xx`). Point
 * 5.1 is what forced the question: `companies` is seeded empty and filled by
 * hand, so a typo in a company name was, until this point, permanent.
 *
 * **One transaction** (`DB-11`) around the withdrawal and its audit entry.
 * `LIST_ENTRY_ARCHIVED` is not one of §3.12 rule 4's nine and is recorded
 * anyway (`AUD-01`), for the reason `AddListEntry` gives about the create and
 * more so: adding a choice is reversible by not using it, while withdrawing one
 * changes what every form may offer from that moment on.
 *
 * **Nothing cascades** — owner's ruling, option (أ). A catalog item already
 * filed under a company keeps it. See the interface for why there is no foreign
 * key to cascade along in the first place.
 */
final readonly class ArchiveListEntry
{
    public function __construct(
        private ConnectionInterface $connection,
        private ManagedListRepositoryInterface $lists,
        private AuditRecorderInterface $audit,
    ) {}

    /** @return bool false when the list has no live entry with that code */
    public function handle(ManagedList $list, string $code): bool
    {
        return $this->connection->transaction(function () use ($list, $code): bool {
            // Read before the write, and inside the transaction: the audit
            // record is *what was withdrawn*, and after the update there is
            // nothing left in the live set to describe.
            $entry = $this->liveEntry($list, $code);

            if ($entry === null) {
                return false;
            }

            $identifier = $this->lists->archive($list, $code);

            if ($identifier === null) {
                return false;
            }

            $this->audit->record(
                AuditEvent::of('LIST_ENTRY_ARCHIVED'),
                'enum_lists',
                $identifier,
                [
                    'list' => $list->value,
                    'code' => $entry->code(),
                    'label_en' => $entry->labelEn(),
                    'label_ar' => $entry->labelAr(),
                    'position' => $entry->position(),
                ],
                // `AuditRecorderInterface` documents new values as "absent on a
                // delete" — the mirror of the create's null old values. An
                // empty array would read as "it now holds nothing".
                null,
            );

            return true;
        });
    }

    /**
     * The live entry, read through the repository so it comes back typed.
     *
     * `entriesFor()` already excludes archived rows, which is the whole
     * definition of "live" here. The list is `DB-05`-sized — the largest seeded
     * one has six entries — so scanning it costs less than a second query path
     * that would then need its own guarantee of agreeing with the first.
     */
    private function liveEntry(ManagedList $list, string $code): ?ListEntry
    {
        foreach ($this->lists->entriesFor($list) as $entry) {
            if ($entry->code() === $code) {
                return $entry;
            }
        }

        return null;
    }
}
