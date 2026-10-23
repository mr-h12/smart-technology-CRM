<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QS.2a — migration B. `D-155`: a quotation line owns what was typed.
 *
 * `quotation_items` gains `supplier_id` (NOT NULL, a foreign key), `unit_code`
 * (a `units` code, no foreign key, like `catalog_items.unit`), `entered_unit_price`
 * (NUMERIC(18,6), `>= 0`) and `tax_percent` (NUMERIC(6,3), NULL or `>= 0`), and
 * `supplier_quotation_item_id` becomes nullable provenance. The backfill reads the
 * line's offer line and its header **with no `deleted_at` filter**: an offer edit
 * soft-deletes every line it replaces, and an archived line is still history.
 *
 * `D-165`: `quotations.pricing_mode` — every existing row is `legacy`, the only mode
 * that carries `default_margin` and a quotation tax rate. One CHECK per mode;
 * `quotations_tax_amount_matches_tax_percent` now binds `legacy` only, since a new
 * mode's tax is per line (`D-157`).
 *
 * ponytail: the column keeps its `legacy` DEFAULT — a row written without a mode is
 * a legacy one, and a new save that forgot its mode fails the margin CHECK anyway.
 * No index on `supplier_id`: nothing filters lines by it yet.
 *
 * `DEV-03`: `down()` reverses all of it, and refuses (ponytail ceiling) once a line
 * has no offer line or a quotation no margin — it cannot invent either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->uuid('supplier_id')->nullable();
            $table->string('unit_code', 64)->nullable();
            $table->decimal('entered_unit_price', 18, 6)->nullable();
            $table->decimal('tax_percent', 6, 3)->nullable();
        });

        DB::statement(
            'UPDATE quotation_items AS qi SET '
            .'supplier_id = sq.supplier_id, '
            .'unit_code = ci.unit, '
            .'entered_unit_price = qi.unit_cost_base, '
            .'tax_percent = q.tax_percent '
            .'FROM supplier_quotation_items AS sqi, supplier_quotations AS sq, catalog_items AS ci, quotations AS q '
            .'WHERE sqi.id = qi.supplier_quotation_item_id AND sq.id = sqi.supplier_quotation_id '
            .'AND ci.id = qi.catalog_item_id AND q.id = qi.quotation_id'
        );

        DB::statement('ALTER TABLE quotation_items ALTER COLUMN supplier_id SET NOT NULL');
        DB::statement('ALTER TABLE quotation_items ALTER COLUMN entered_unit_price SET NOT NULL');
        DB::statement('ALTER TABLE quotation_items ALTER COLUMN supplier_quotation_item_id DROP NOT NULL');

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreign('supplier_id')->references('id')->on('suppliers');
        });

        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_entered_unit_price_not_negative CHECK (entered_unit_price >= 0)');
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_tax_percent_not_negative CHECK (tax_percent IS NULL OR tax_percent >= 0)');

        DB::statement("ALTER TABLE quotations ADD COLUMN pricing_mode varchar(16) NOT NULL DEFAULT 'legacy'");
        DB::statement('ALTER TABLE quotations ALTER COLUMN default_margin DROP NOT NULL');

        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_known_pricing_mode CHECK (pricing_mode IN ('legacy', 'tax_included', 'tax_exempt'))");
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_margin_only_in_legacy CHECK ((pricing_mode = 'legacy') = (default_margin IS NOT NULL))");
        DB::statement(
            "ALTER TABLE quotations ADD CONSTRAINT quotations_new_mode_has_no_quotation_rate CHECK (pricing_mode = 'legacy' "
            .'OR (tax_percent IS NULL AND jsonb_array_length(tax_percents) = 0))'
        );
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_exempt_has_no_tax_amount CHECK (pricing_mode <> 'tax_exempt' OR tax_amount IS NULL)");

        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_tax_amount_matches_tax_percent');
        DB::statement(
            "ALTER TABLE quotations ADD CONSTRAINT quotations_tax_amount_matches_tax_percent CHECK (pricing_mode <> 'legacy' "
            .'OR (tax_percent IS NULL) = (tax_amount IS NULL))'
        );
    }

    public function down(): void
    {
        if (DB::table('quotation_items')->whereNull('supplier_quotation_item_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back QS.2a: a quotation line has no supplier_quotation_item_id to restore (it was saved after the line-owned pricing).'
            );
        }

        if (DB::table('quotations')->whereNull('default_margin')->exists()) {
            throw new RuntimeException('Cannot roll back QS.2a: a quotation has no default_margin to restore (it was saved after the line-owned pricing).');
        }

        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_tax_amount_matches_tax_percent');
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_tax_amount_matches_tax_percent CHECK ((tax_percent IS NULL) = (tax_amount IS NULL))');
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_exempt_has_no_tax_amount');
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_new_mode_has_no_quotation_rate');
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_margin_only_in_legacy');
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_known_pricing_mode');
        DB::statement('ALTER TABLE quotations ALTER COLUMN default_margin SET NOT NULL');
        DB::statement('ALTER TABLE quotations DROP COLUMN pricing_mode');

        DB::statement('ALTER TABLE quotation_items DROP CONSTRAINT quotation_items_tax_percent_not_negative');
        DB::statement('ALTER TABLE quotation_items DROP CONSTRAINT quotation_items_entered_unit_price_not_negative');

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'unit_code', 'entered_unit_price', 'tax_percent']);
        });

        DB::statement('ALTER TABLE quotation_items ALTER COLUMN supplier_quotation_item_id SET NOT NULL');
    }
};
