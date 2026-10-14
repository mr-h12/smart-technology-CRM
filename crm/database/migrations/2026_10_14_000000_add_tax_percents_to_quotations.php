<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-48 · 1 — `D-118`: a quotation carries the tax rates as entered, and
 * `tax_percent` keeps their sum, so §5's formulas and the three money CHECKs
 * read one rate as before. An existing rate becomes a one-rate list.
 *
 * ponytail: the CHECK ties one way only (rates ⇒ a sum). A sum with an empty
 * list is not refused, so rows written straight to the table keep inserting;
 * every save through the app writes both.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE quotations ADD COLUMN tax_percents jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement('UPDATE quotations SET tax_percents = jsonb_build_array(tax_percent::text) WHERE tax_percent IS NOT NULL');
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_tax_percents_have_a_sum '
            .'CHECK (jsonb_array_length(tax_percents) = 0 OR tax_percent IS NOT NULL)');
    }

    public function down(): void
    {
        // `DEV-03`: the CHECK goes with its column.
        DB::statement('ALTER TABLE quotations DROP COLUMN tax_percents');
    }
};
