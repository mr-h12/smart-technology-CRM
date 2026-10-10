<?php

declare(strict_types=1);

use App\Support\Database\Precision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-39 · 1.3 — `D-105`: a supplier quotation entered with tax-inclusive prices.
 *
 * `total_price` and `unit_price` keep storing what every reader downstream
 * uses — the net (§5.1). Beside them: the flag, the rate captured when the
 * offer was saved (a later change to `defaults.tax_percent` never alters it,
 * `D-09`'s rule), and the amounts as entered, so an edit recomputes from those
 * and never strips the tax twice. No backfill: every existing offer was taken
 * as recorded (`D-62`), which is what `false` and `NULL` say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->boolean('prices_include_tax')->default(false);
            $table->decimal('included_tax_percent', Precision::PERCENT_TOTAL, Precision::PERCENT_SCALE)->nullable();
            $table->decimal('entered_total_price', Precision::MONEY_TOTAL, Precision::MONEY_SCALE)->nullable();
        });

        // A flagged offer always knows its rate; an offer taken as recorded has none to misread.
        DB::statement('ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_included_tax_with_flag '
            .'CHECK (prices_include_tax = (included_tax_percent IS NOT NULL))');

        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            $table->decimal('entered_unit_price', Precision::MONEY_TOTAL, Precision::MONEY_SCALE)->nullable();
        });
    }

    public function down(): void
    {
        // `DEV-03`: the CHECK goes with its columns.
        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            $table->dropColumn('entered_unit_price');
        });

        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->dropColumn(['prices_include_tax', 'included_tax_percent', 'entered_total_price']);
        });
    }
};
