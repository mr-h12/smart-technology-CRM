<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `enum_lists_known_list` gains a fifth literal: `companies`.
 *
 * ── Why this migration exists at all ───────────────────────────────────────
 *
 * `create_enum_lists` CHECKs `list` against four literals, "written out here
 * because a migration is history and must not change meaning when an enum is
 * edited later". That is exactly what happened: Point 5.1 added
 * `ManagedList::Companies`, and the first insert answered
 * `SQLSTATE[23514] … violates check constraint "enum_lists_known_list"`.
 *
 * The original migration is not edited — `DEV-03` and `CLAUDE.md` both forbid
 * it — so the constraint is dropped and re-added with the fifth name.
 *
 * ── The reasoning it corrects ──────────────────────────────────────────────
 *
 * Both `ManagedList` and `create_enum_lists` argued that "adding a fifth list
 * means adding the column that points at it, which is a migration either way".
 * `companies` is the counter-example: **the column already existed.**
 * `catalog_items.company` has been a `string(255)` since Point 1.2, filled as
 * free text. What changed is where its values come from, not where they are
 * stored — so the pointing column needed nothing, and this one CHECK is the
 * entire schema change.
 *
 * ── The owner's ruling, not `DB-05` ────────────────────────────────────────
 *
 * `DB-05` names four lists and does not name companies. The fifth is the
 * owner's ruling of 2026-08-31: §7.3 groups the catalog "by company/team name"
 * and the owner asked to filter by it too, and a value that is both grouped and
 * filtered cannot stay free text without fragmenting — "Acme", "acme" and
 * "Acme Ltd" become three companies on one screen, which is `R-03`'s recorded
 * free-text risk arriving a second time. Recorded in `CHECKLIST.md` awaiting a
 * `D-xx`.
 */
return new class extends Migration
{
    /** After this migration. Literals, for the reason `create_enum_lists` gives. */
    private const LISTS = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies'];

    /** Before it — what `down()` restores. */
    private const LISTS_BEFORE = ['sectors', 'units', 'service_types', 'delivery_terms'];

    public function up(): void
    {
        self::replaceConstraint(self::LISTS);
    }

    /**
     * `DEV-03`: `migrate:rollback` must actually work.
     *
     * ⚠️ Rolling back with a `companies` row present fails, and that is the
     * correct behaviour rather than a flaw: the rows would otherwise survive a
     * constraint that forbids them. The archive path is `DB-01`'s soft delete,
     * not a rollback.
     */
    public function down(): void
    {
        self::replaceConstraint(self::LISTS_BEFORE);
    }

    /** @param  list<string>  $lists */
    private static function replaceConstraint(array $lists): void
    {
        $literals = "'".implode("', '", $lists)."'";

        DB::statement('ALTER TABLE enum_lists DROP CONSTRAINT enum_lists_known_list');
        DB::statement("ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_known_list CHECK (list IN ({$literals}))");
    }
};
