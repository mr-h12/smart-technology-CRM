<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4, Point 1.2 — `catalog_items`, the table §7.3 publishes.
 *
 * ── One table, because the build plan names one ────────────────────────────
 *
 * `MVP_Build_Plan_EN.md` Module 4 lists "Tables: `catalog_items` · `suppliers`".
 * A product and a service are two **tabs** of one catalog, not two tables, so
 * `kind` carries the split. Every column belonging to only one tab is nullable
 * and the conditional rules — a product needs a unit, a service needs a type —
 * are Point 3.2's Form Request. A CHECK enforcing them here would duplicate a
 * validation rule in a place no error message can reach.
 *
 * ── No price column, and that is the requirement ───────────────────────────
 *
 * §7.3 opens "Descriptive data only — **no prices**" and `D-21` puts every
 * price on the supplier quotation, where a price belongs to an offer on a date
 * rather than to the thing itself. There is deliberately **no numeric column
 * of any kind** here; `CatalogItemSchemaMigrationTest` asserts that absence,
 * which is what turns the checklist line into a check.
 *
 * ── `company` is shared across both tabs (owner's decision, 2026-08-30) ────
 *
 * §7.3's field table writes "Providing team / company" against **Service**
 * only, but its own prose groups the whole catalog "by company/team name" and
 * the build plan's acceptance criterion requires a **product** to appear
 * "grouped by company". Two sources require it of a product and one is merely
 * silent, so the column is shared. Recorded in `CHECKLIST.md` awaiting a
 * `D-xx`; `docs/` is untouched.
 *
 * ── `unit` and `service_type` carry no foreign key, and that was measured ──
 *
 * Both are `enum_lists` codes (`DB-05`: "units · service types"), and that
 * table's uniqueness is a **partial** index — `enum_lists_code_unique_alive
 * ... WHERE deleted_at IS NULL`, written that way by Module 2 Point 1.3 so an
 * archived code can be taken again. PostgreSQL refuses a foreign key against a
 * partial index (`SQLSTATE[42830]`), the same wall Module 3 Point 1.1 hit on
 * `customers.sector`. `DB-04` is therefore honoured where a key is possible —
 * the two actor columns — and these two are validated at the boundary. The
 * test pins both halves.
 *
 * ── No index yet, and §4.7 is why `product_code` is not one ────────────────
 *
 * `DB-09` requires indexes on "customer · owner · deal status · dates · entity
 * codes". `catalog_items` has no customer, no owner (§3.7 grants every
 * operational role `Scope::All`, so there is no row scoping to serve), no
 * status and no date. **`product_code` is not an entity code**: §4.7's codes
 * are the generated document numbers `DL`, `QT`, `SQ`, `PO` and `RPT-*`, and
 * it assigns none to a catalog item — `product_code` is whatever the
 * manufacturer prints on the box. The list's grouping, filters and sort are
 * defined at Point 3.1, and an index for a query that does not exist yet is a
 * guess that costs every write.
 */
return new class extends Migration
{
    /** §7.3: "Two tabs: Product · Service". */
    private const KINDS = ['product', 'service'];

    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->standardColumns();

            // Which tab the row appears under. Not nullable: a row in neither
            // tab appears on no screen §7.3 describes.
            $table->string('kind', 16);

            // §7.3 "Product name". A service is identified by its type, so the
            // name is nullable here and required for a product at the boundary.
            $table->string('name', 255)->nullable();

            // §7.3 "Product code" — the manufacturer's, not a §4.7 document number.
            $table->string('product_code', 64)->nullable();

            // §7.3 "Category (for search)".
            $table->string('category', 128)->nullable();

            // A `code` from `enum_lists` where `list = 'units'` (`DB-05`).
            $table->string('unit', 64)->nullable();

            // A `code` from `enum_lists` where `list = 'service_types'` (`DB-05`).
            $table->string('service_type', 64)->nullable();

            // §7.3 "Providing team / company" — shared by both tabs, which is
            // the owner's decision rather than the document's silence.
            $table->string('company', 255)->nullable();

            // §7.3 "Description" and "Service description (optional)".
            $table->text('description')->nullable();

            // §7.3 "Notes".
            $table->text('notes')->nullable();

            // §7.3 "active product" / "Active service (yes/no)". §3.7 grants
            // "deactivate" and §10.4 describes what a deactivated item does:
            // it keeps working on open quotations and disappears from new
            // selection lists. Distinct from `DB-01`'s `deleted_at`.
            $table->boolean('is_active')->default(true);

            $table->standardActorForeignKeys();
        });

        $kinds = "'".implode("', '", self::KINDS)."'";

        DB::statement(
            "ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_known_kind CHECK (kind IN ({$kinds}))"
        );

        // The name is optional, but a name of spaces is not a name. Written as
        // `IS NULL OR` rather than left to three-valued logic so the column's
        // optionality is legible without recalling that a NULL CHECK passes.
        DB::statement(
            'ALTER TABLE catalog_items ADD CONSTRAINT catalog_items_name_not_blank '
            ."CHECK (name IS NULL OR btrim(name) <> '')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
