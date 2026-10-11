<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-41 · 1.4 — `details`, `D-108`'s optional internal free text on a deal:
 * typed in the deal form, never on a customer PDF.
 *
 * `text` with a CHECK rather than `varchar(2000)`: the limit is the column's as
 * well as the request's (owner, 2026-10-11, «أ»), and `char_length` counts
 * characters, so 2000 Arabic characters fit exactly as 2000 Latin ones do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->text('details')->nullable();
        });

        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_details_at_most_2000_characters CHECK (char_length(details) <= 2000)');
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
