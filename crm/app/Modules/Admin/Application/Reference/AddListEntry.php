<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Reference;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ListEntryAlreadyExists;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * **The write this whole module was built around.**
 *
 * `DB-05` — *"Enum tables, not hard-coded enums"* — and the acceptance
 * criterion it exists for: *"A new sector added in settings appears in the
 * customer form **without a deployment**"*. Point 1.3 built the table, Point
 * 2.2 seeded it and gave it a repository forbidden from answering out of code,
 * and Point 3.4's `GET` is what the customer form calls. This is the missing
 * half: the way a sector gets there in the first place.
 *
 * **One transaction** (`DB-11`) around the insert and its audit entry.
 * `LIST_ENTRY_ADDED` is not one of §3.12 rule 4's nine and is recorded anyway
 * (`AUD-01`): a sector is what customers are filed under and a unit is what
 * quantities are priced in, so *who added this, and when* is a question the
 * first disagreement about a report will ask.
 *
 * **The duplicate is answered as a validation failure**, because from the
 * caller's side that is what it is: a submitted `code` that cannot be used.
 * `OpenAPI §5.1`'s 422 `validation_failed`, with the field named.
 */
final readonly class AddListEntry
{
    public function __construct(
        private ConnectionInterface $connection,
        private ManagedListRepositoryInterface $lists,
        private AuditRecorderInterface $audit,
    ) {}

    /** @throws ValidationException when the list already has that code */
    public function handle(ManagedList $list, ListEntry $entry): ListEntry
    {
        return $this->connection->transaction(function () use ($list, $entry): ListEntry {
            try {
                $this->lists->add($list, $entry);
            } catch (ListEntryAlreadyExists) {
                throw ValidationException::withMessages([
                    'code' => __('admin.managed_list.duplicate_code'),
                ]);
            }

            $this->audit->record(
                AuditEvent::of('LIST_ENTRY_ADDED'),
                'enum_lists',
                $this->identifierOf($list, $entry),
                null,
                [
                    'list' => $list->value,
                    'code' => $entry->code(),
                    'label_en' => $entry->labelEn(),
                    'label_ar' => $entry->labelAr(),
                    'position' => $entry->position(),
                ],
            );

            return $entry;
        });
    }

    /**
     * The row's UUID, read back after the insert.
     *
     * `ListEntry` deliberately has no identifier — it is a value object about a
     * code — and `audit_log.entity_id` is a `UUID` column, so the id is fetched
     * rather than invented. Point 3.1 measured what passing a name to that
     * column costs: `SQLSTATE[22P02]`. The read is inside the same transaction
     * as the insert, so there is no window in which it could be missing.
     */
    private function identifierOf(ManagedList $list, ListEntry $entry): string
    {
        $id = $this->connection->table('enum_lists')
            ->where('list', $list->value)
            ->where('code', $entry->code())
            ->whereNull('deleted_at')
            ->value('id');

        return is_string($id) ? $id : '';
    }
}
