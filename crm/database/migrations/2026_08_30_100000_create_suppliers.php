<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, Point 1.1 — `suppliers`, the table §7.1 publishes.
 *
 * ── No price column, and that is the point ─────────────────────────────────
 *
 * `D-21`: "Prices live on the supplier quotation — the catalog holds
 * descriptive data only." §7.1 lists no amount either. A supplier record says
 * who they are and how good they have been; what they charge is a property of
 * an offer on a date, which Module 6 stores. `SupplierSchemaMigrationTest`
 * asserts no float column, and `DB-07` would govern the type if one ever
 * arrived — but the correct number of money columns here is zero.
 *
 * ── `linked_quotations` is derived, not stored ─────────────────────────────
 *
 * §7.1 annotates it "Automatic" and §4.1 draws `Supplier ──► Supplier
 * Quotation` with the foreign key on the quotation. Module 6 reads it from
 * `supplier_quotations.supplier_id`; a column here would be a denormalised
 * copy whose only guaranteed property is going stale.
 *
 * ── `color_rating` and `type` are CHECKs, not enum tables ──────────────────
 *
 * `DB-05` names the four lists that must be enum tables — "sectors · units ·
 * service types · delivery terms" — and neither of these is one. §7.1 fixes
 * the four colours by giving each a *meaning*, so a fifth colour is a change
 * to that meaning table and therefore a migration, not a row an administrator
 * adds in settings. The same reading Module 3 applied to `customer_status`.
 *
 * `color_rating` defaults to `white` because §7.1 defines white as "New / not
 * yet rated" — the state of every supplier before anyone forms an opinion.
 * `D-19` lets any employee change it, which is Point 2.2's endpoint, not a
 * property of the column.
 *
 * ── Only the name is required ──────────────────────────────────────────────
 *
 * §7.1 marks no field required, unlike §4.2 which writes "Required" beside the
 * customer's name. So the name carries NOT NULL — a nameless supplier cannot
 * be picked on an offer — and everything else is nullable and validated at the
 * boundary. That direction is deliberate: tightening `type` later is a Form
 * Request, while loosening a NOT NULL after rows exist is a migration.
 *
 * ── `is_active` is not `DB-01`'s soft delete ───────────────────────────────
 *
 * §3.7's write row is "create · edit · deactivate · set colour", and §3.12
 * rule 3 forbids hard-deleting a supplier at all — `catalog.delete` is seeded
 * with an empty grant array, so no role can hold it. §10.4 then describes what
 * a deactivated supplier still does: it stays usable on open quotations with a
 * warning and disappears from new ones. That is a business flag; `deleted_at`
 * is `DB-01`'s and nothing in this module sets it.
 *
 * ── No index beyond the block, and that is measured ────────────────────────
 *
 * `DB-09` requires indexes on "customer · owner · deal status · dates · entity
 * codes". `suppliers` has none of those: §3.7 gives it no owner column (the
 * matrix grants every operational role `Scope::All`, so there is no row
 * scoping to serve), no date, and no document code — §4.7 gives `SQ` to the
 * supplier *quotation*. `standardAudit()` already indexes `created_by`. The
 * list's filters and sort arrive at Point 2.1, and an index for a query that
 * does not exist yet is a guess that costs every write.
 */
return new class extends Migration
{
    /** §7.1's colour meanings table. White is the unrated state. */
    private const COLOURS = ['green', 'yellow', 'red', 'white'];

    /** §7.1: "name · type | supplier / distributor". */
    private const TYPES = ['supplier', 'distributor'];

    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->standardColumns();

            $table->string('name', 255);

            // Nullable: §7.1 marks nothing required. The CHECK below still
            // closes the set for whatever value does arrive.
            $table->string('type', 32)->nullable();

            // `D-19`, set manually by any employee. §7.1: "⚪ White | New / not
            // yet rated" — so an unrated supplier is white, not null.
            $table->string('color_rating', 16)->default('white');

            $table->string('phone', 32)->nullable();
            $table->string('contact_person', 255)->nullable();

            // §7.1: "has_open_account | yes / no". Nobody has an open account
            // with a supplier the moment the record is created.
            $table->boolean('has_open_account')->default(false);

            // §3.7's "deactivate". Distinct from `DB-01`'s `deleted_at`.
            $table->boolean('is_active')->default(true);

            $table->standardActorForeignKeys();
        });

        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_name_not_blank CHECK (btrim(name) <> '')");

        $colours = "'".implode("', '", self::COLOURS)."'";

        DB::statement(
            "ALTER TABLE suppliers ADD CONSTRAINT suppliers_known_colour CHECK (color_rating IN ({$colours}))"
        );

        $types = "'".implode("', '", self::TYPES)."'";

        // `type IS NULL OR` is written out rather than left to three-valued
        // logic. A bare IN already admits NULL, and a reader should not have to
        // recall that to know whether the column is optional.
        DB::statement(
            "ALTER TABLE suppliers ADD CONSTRAINT suppliers_known_type CHECK (type IS NULL OR type IN ({$types}))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
