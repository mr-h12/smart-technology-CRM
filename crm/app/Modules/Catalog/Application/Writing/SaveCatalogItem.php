<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Writing;

use App\Modules\Admin\Application\Reference\AddListEntry;
use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Listing\CatalogItemNotFound;
use App\Modules\Catalog\Domain\Listing\CatalogItemSummary;
use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §7.3's create and edit, each with `AUD-01`'s record in one transaction.
 *
 * ── The audit is the acceptance criterion, not a side effect ───────────────
 *
 * `D-45` opened catalog and supplier editing to every employee and named the
 * mitigation in the same breath: *"every edit is written to the audit log"*,
 * plus a monthly Team Leader review. The build plan turns that into Module 4's
 * criterion — "Every catalog edit is written to the audit log" — so this
 * record is the control the module rests on rather than a trace of it.
 *
 * `DB-11`: the business write and its audit row commit together or not at all.
 *
 * ── No scope, and no owner ─────────────────────────────────────────────────
 *
 * §3.7 has no ownership and no scope: one permission, `catalog.manage`, held
 * at `Scope::All` by every operational role. The route's middleware is the
 * whole authorisation decision, so nothing is re-decided here.
 *
 * ── Deactivation is an update, deliberately ────────────────────────────────
 *
 * §3.7 puts "deactivate" in the same cell as "edit", so it is a field on the
 * row and arrives through this class like any other. `OpenAPI §7.2` reserves
 * an action suffix for what is "not a normal resource update"; `is_active` is
 * one. What §10.4 makes it *mean* — the item keeps working on open quotations
 * and disappears from new selection lists — belongs to Modules 6 and 7, which
 * own those lists.
 */
final readonly class SaveCatalogItem
{
    public function __construct(
        private CatalogItemDirectoryInterface $items,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private ManagedListRepositoryInterface $lists,
        private AddListEntry $companies,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public function create(array $validated, string $actorId): CatalogItemSummary
    {
        return $this->connection->transaction(function () use ($validated, $actorId): CatalogItemSummary {
            $draft = CatalogItemDraft::of($this->withListedCompany($validated));

            $item = $this->items->create($draft, $actorId);

            // No old values: `AuditRecorderInterface` documents them as "absent
            // on a create", and an empty array would read as "it used to be
            // nothing", which is a different claim.
            $this->audit->record(
                AuditEvent::of('CATALOG_ITEM_CREATED'),
                'catalog_item',
                $item->id,
                null,
                $draft->attributes,
            );

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws CatalogItemNotFound when the row is absent or soft-deleted
     */
    public function update(string $catalogItemId, array $validated, string $actorId): CatalogItemSummary
    {
        return $this->connection->transaction(function () use ($catalogItemId, $validated, $actorId): CatalogItemSummary {
            $draft = CatalogItemDraft::of($this->withListedCompany($validated));

            // Read inside the transaction: the old values `AUD-02` requires must
            // be the ones this write actually replaced, not the ones some
            // earlier read happened to see.
            $before = $this->items->find($catalogItemId);

            if (! $before instanceof CatalogItemSummary) {
                throw CatalogItemNotFound::of($catalogItemId);
            }

            $after = $this->items->update($catalogItemId, $draft, $actorId);

            if (! $after instanceof CatalogItemSummary) {
                // A row that vanished between two statements of one transaction
                // is a defect, and a silent 200 would hide it.
                throw CatalogItemNotFound::of($catalogItemId);
            }

            if (! $draft->isEmpty()) {
                $this->audit->record(
                    AuditEvent::of('CATALOG_ITEM_UPDATED'),
                    'catalog_item',
                    $catalogItemId,
                    self::changedFrom($before, $draft),
                    $draft->attributes,
                );
            }

            return $after;
        });
    }

    /**
     * The values this write replaced, limited to the fields it actually touched.
     *
     * `AUD-02` wants the old value beside the new one. Recording the whole row
     * would bury the one field that changed among nine that did not.
     *
     * @return array<string, mixed>
     */
    private static function changedFrom(CatalogItemSummary $before, CatalogItemDraft $draft): array
    {
        $previous = [
            'kind' => $before->kind,
            'name' => $before->name,
            'product_code' => $before->productCode,
            'category' => $before->category,
            'unit' => $before->unit,
            'service_type' => $before->serviceType,
            'company' => $before->company,
            'description' => $before->description,
            'notes' => $before->notes,
            'is_active' => $before->isActive,
        ];

        $old = [];

        foreach (array_keys($draft->attributes) as $field) {
            $old[$field] = $previous[$field] ?? null;
        }

        return $old;
    }

    /**
     * Replace the typed company with its **code**, adding it to `DB-05`'s
     * companies list when it is not there yet. Owner's ruling, 2026-08-31.
     *
     * ── Why the module crosses into Admin at all ──────────────────────────
     *
     * Point 5.1 made `company` a managed list precisely so that "Acme", "acme"
     * and "Acme Ltd" stop being three companies on one screen — `R-03`'s
     * recorded free-text risk, arriving for a value that §7.3 both **groups**
     * and (since Point 5.2) **filters** by. Point 5.2 then made it required,
     * which left every catalog edit waiting on a Super Admin to add the
     * company first. This closes that: the list fills from use.
     *
     * The alternative — Catalog keeping its own idea of a company — is the
     * exact duplication `DB-05` exists to prevent, so the crossing goes through
     * `AdminContract` and never touches `enum_lists` itself.
     *
     * ── The permission this does not ask for ──────────────────────────────
     *
     * A list write carries `admin.system_settings`; this route carries
     * `catalog.manage`. Owner's ruling: adding the company is a **system
     * consequence of a permitted action**, not the actor exercising a
     * permission it does not hold. {@see AddListEntry} still writes
     * `LIST_ENTRY_ADDED` against the acting user, so it is visible rather than
     * silent. Recorded in `CHECKLIST.md` awaiting a `D-xx`.
     *
     * ── Normalising is the feature, not a side effect ─────────────────────
     *
     * Three spellings of one company collapse to one code and therefore one
     * entry. That is the whole point; two genuinely different companies whose
     * names reduce to the same code would share an entry, which is the stated
     * ceiling of deriving a code from words at all.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withListedCompany(array $validated): array
    {
        $typed = $validated['company'] ?? null;

        // Absent is not blank: a `PATCH` that does not name the column is not
        // asking to change it, the reading `SaveCatalogItemRequest` applies.
        if (! is_string($typed) || trim($typed) === '') {
            return $validated;
        }

        $typed = trim($typed);
        $code = self::codeFor($typed);
        $validated['company'] = $code;

        $listed = $this->lists->entriesFor(ManagedList::Companies);

        foreach ($listed as $entry) {
            if ($entry->code() === $code) {
                return $validated;
            }
        }

        try {
            $this->companies->handle(
                ManagedList::Companies,
                // Both labels take the typed text. §13's own hint says an entry
                // with one wording shows blank in the other, and the person
                // saving a catalog item has given exactly one. A Super Admin
                // corrects the Arabic on the lists screen; owner accepted that
                // on 2026-08-31.
                new ListEntry($code, $typed, $typed, self::nextPosition($listed)),
            );
        } catch (ValidationException) {
            // `AddListEntry` answers a duplicate code this way. Reaching it
            // means somebody added the same company between the read above and
            // this write — which is the state this method wanted anyway.
        }

        return $validated;
    }

    /**
     * A `code` for words a person typed, matching `^[a-z][a-z0-9_]*$`.
     *
     * **Measured rather than assumed** (2026-08-31): `Str::slug` transliterates
     * Arabic — `شركة ألفا` becomes `shrk_alfa` — which is ugly but stable, and
     * the code is internal ("a short internal name … never shown to anyone",
     * §13's own hint) while both labels carry the real text. Two cases it does
     * **not** answer, and both are handled here rather than left to fail
     * validation at the boundary:
     *
     * - `3M` slugs to `3m`, which the pattern refuses for its leading digit.
     * - A name of nothing but punctuation slugs to the empty string. `company`
     *   is `required` with `regex:/\S/`, so `"..."` reaches here and would
     *   otherwise produce no code at all — and every such name would collide on
     *   one, filing unrelated companies together. A digest keeps them apart and
     *   keeps the same name mapping to the same code every time.
     *
     * ⚠️ Ceiling: two names longer than 64 characters that agree on their first
     * 64 share a code. `code` is `max:64` and that is the column's own limit.
     */
    private static function codeFor(string $typed): string
    {
        $slug = Str::slug($typed, '_');

        if ($slug === '') {
            return 'c_'.substr(md5($typed), 0, 8);
        }

        if (preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'c_'.$slug;
        }

        return substr($slug, 0, 64);
    }

    /**
     * The end of the list. `position` is a sort key and `AddListEntryRequest`
     * requires at least 1, so an empty list starts at 1 rather than 0.
     *
     * @param  list<ListEntry>  $listed
     */
    private static function nextPosition(array $listed): int
    {
        $highest = 0;

        foreach ($listed as $entry) {
            $highest = max($highest, $entry->position());
        }

        return $highest + 1;
    }
}
