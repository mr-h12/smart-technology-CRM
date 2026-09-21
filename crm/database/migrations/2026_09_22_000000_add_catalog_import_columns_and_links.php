<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-10 · 1.3 — the catalog's import and its supplier link (`D-86`).
 *
 * ── `catalog_items.is_incomplete` ─────────────────────────────────────────
 *
 * `D-31`'s flag, set by the importer when a row lacks what the form requires
 * (a product's unit, a service's type, the company). It defaults to false, so
 * no existing item is flagged and no correction is owed (unlike F-11 · 1.4).
 *
 * ── `catalog_item_suppliers` ──────────────────────────────────────────────
 *
 * Which suppliers carry an item — many per item, **no price and no quantity**:
 * §7.3 keeps the catalog descriptive, and `supplier_quotation_items` already
 * holds the priced relation. One live row per pair, the `role_permissions`
 * shape; unlinking is a soft delete (`DB-01`), so linking again is a new row.
 *
 * The foreign keys keep PostgreSQL's default (no action), not a cascade: rows
 * here are never hard-deleted, and an accidental hard delete of an item or a
 * supplier should fail loudly rather than take its links with it.
 *
 * ponytail: no index on `supplier_id` alone — the pair index leads with the
 * item and serves the one planned read, "this item's suppliers". Add one when
 * "the items this supplier carries" is asked for.
 *
 * ── `catalog_import_batches` ──────────────────────────────────────────────
 *
 * F-09's `supplier_import_batches`, owned by Catalog: modules do not share
 * tables. Same four CHECKs, the arithmetic being the database's (`DB-04`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->boolean('is_incomplete')->default(false);
        });

        Schema::create('catalog_item_suppliers', function (Blueprint $table): void {
            $table->standardColumns();

            $table->uuid('catalog_item_id');
            $table->uuid('supplier_id');

            $table->foreign('catalog_item_id')->references('id')->on('catalog_items');
            $table->foreign('supplier_id')->references('id')->on('suppliers');

            $table->standardActorForeignKeys();
        });

        DB::statement(
            'CREATE UNIQUE INDEX catalog_item_suppliers_pair_unique_alive '
            .'ON catalog_item_suppliers (catalog_item_id, supplier_id) WHERE deleted_at IS NULL'
        );

        Schema::create('catalog_import_batches', function (Blueprint $table): void {
            $table->standardColumns();

            $table->string('original_filename', 255);
            $table->integer('row_count')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('incomplete_count')->default(0);

            $table->standardActorForeignKeys();
        });

        foreach ([
            'filename_not_blank' => "btrim(original_filename) <> ''",
            'counts_not_negative' => 'row_count >= 0 AND imported_count >= 0 AND incomplete_count >= 0',
            'imported_within_read' => 'imported_count <= row_count',
            'incomplete_within_imported' => 'incomplete_count <= imported_count',
        ] as $name => $check) {
            DB::statement("ALTER TABLE catalog_import_batches ADD CONSTRAINT catalog_import_batches_{$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_batches');
        Schema::dropIfExists('catalog_item_suppliers');

        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropColumn('is_incomplete');
        });
    }
};
