<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QS.1 — `D-155`: a quotation line names its own product.
 *
 * `quotation_items` gains `catalog_item_id`, NOT NULL and a foreign key to
 * `catalog_items`. This supersedes the "No `catalog_item_id`" note of
 * `2026_09_07_000200_create_quotation_items.php` (that applied migration is
 * not edited): the line, not its supplier line, now owns the product.
 *
 * The backfill copies each line's supplier line's item **with no `deleted_at`
 * filter on either side**: a supplier-offer edit soft-deletes every line it
 * replaces, and an archived quotation line is still history worth naming.
 * `supplier_quotation_item_id` and `supplier_quotation_items.catalog_item_id`
 * are both NOT NULL, so every row is filled before the column is closed.
 *
 * ponytail: no index — no query filters lines by product; add one when one does.
 *
 * `DEV-03`: `down()` drops the foreign key and the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->uuid('catalog_item_id')->nullable();
        });

        DB::statement(
            'UPDATE quotation_items AS qi SET catalog_item_id = sqi.catalog_item_id '
            .'FROM supplier_quotation_items AS sqi WHERE sqi.id = qi.supplier_quotation_item_id'
        );

        DB::statement('ALTER TABLE quotation_items ALTER COLUMN catalog_item_id SET NOT NULL');

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreign('catalog_item_id')->references('id')->on('catalog_items');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['catalog_item_id']);
            $table->dropColumn('catalog_item_id');
        });
    }
};
