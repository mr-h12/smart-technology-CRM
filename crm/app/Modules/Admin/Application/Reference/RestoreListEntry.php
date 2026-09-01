<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Reference;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Put a withdrawn entry back. The mirror of {@see ArchiveListEntry}.
 *
 * **This half was documented before it was built.** §3.3 line 223 writes the
 * permission row as a single merged `archive / restore`, §7's Flow 7 gives
 * archiving a **Restore** column, and §3.12 rule 4 names *"restore from
 * archive"* among the mandatory audit entries — a rule that cannot apply to an
 * action nobody can perform. Point 6.1 shipped the withdrawal alone, which left
 * a mistyped company permanent in a different way: the code could be added
 * again, but the row that was withdrawn stayed withdrawn forever. Owner's
 * ruling of 2026-08-31.
 *
 * **One transaction** (`DB-11`) around the restore and its audit entry, and the
 * same authority as the withdrawal — `routes/api.php` already applies §3.3's
 * merged row to `PATCH /customers/{customer}/restore`, which carries
 * `customer.archive` and not a `customer.restore` nobody wrote down.
 *
 * ── What the audit records, and what it deliberately does not ──────────────
 *
 * `old_values` is null and `new_values` carries `{list, code}`: the act, not
 * the entry. A restore does not choose the labels — it clears `deleted_at`, and
 * the labels it uncovers are whatever the archive recorded on the way out. They
 * are readable from the list itself the moment this returns, and copying them
 * here would describe the row rather than what was done to it. The archive's
 * record is where the values live, and its `entity_id` is the same row's.
 */
final readonly class RestoreListEntry
{
    public function __construct(
        private ConnectionInterface $connection,
        private ManagedListRepositoryInterface $lists,
        private AuditRecorderInterface $audit,
    ) {}

    /** @return bool false when the list has no archived entry with that code */
    public function handle(ManagedList $list, string $code): bool
    {
        return $this->connection->transaction(function () use ($list, $code): bool {
            $identifier = $this->lists->restore($list, $code);

            if ($identifier === null) {
                return false;
            }

            $this->audit->record(
                AuditEvent::of('LIST_ENTRY_RESTORED'),
                'enum_lists',
                $identifier,
                null,
                ['list' => $list->value, 'code' => $code],
            );

            return true;
        });
    }
}
