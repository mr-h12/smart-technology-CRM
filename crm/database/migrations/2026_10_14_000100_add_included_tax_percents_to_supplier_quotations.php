<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-48 · 2 — `D-118`: a tax-inclusive supplier offer keeps the rates as typed;
 * `included_tax_percent` stays their sum, the one rate the strip divides by.
 * An offer flagged before keeps its captured rate as a one-rate list.
 *
 * ponytail: the CHECK ties one way only (rates ⇒ the flag), on
 * `quotations_tax_percents_have_a_sum`'s terms; the flag already demands a sum
 * (`supplier_quotations_included_tax_with_flag`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE supplier_quotations ADD COLUMN included_tax_percents jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement('UPDATE supplier_quotations SET included_tax_percents = jsonb_build_array(included_tax_percent::text) '
            .'WHERE included_tax_percent IS NOT NULL');
        DB::statement('ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_included_tax_percents_with_flag '
            .'CHECK (jsonb_array_length(included_tax_percents) = 0 OR prices_include_tax)');
    }

    public function down(): void
    {
        // `DEV-03`: the CHECK goes with its column.
        DB::statement('ALTER TABLE supplier_quotations DROP COLUMN included_tax_percents');
    }
};
