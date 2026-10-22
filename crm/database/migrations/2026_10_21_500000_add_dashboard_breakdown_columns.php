<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 14 · 5.2 — what the breakdowns read (`D-144`, `D-147`, `D-150`). J-09's
 * derived tables again: rebuilt whole by every run, no soft-delete or actor columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_sent_versions', function (Blueprint $table): void {
            $table->uuid('customer_id')->nullable();
            // The customer's sector code now (`DB-05`'s `sectors`), null when it has none.
            $table->string('sector', 64)->nullable();
        });

        // Each live line of a sent version: its catalog product and its total, in the
        // version's currency and in the base currency at the version's rate (`D-150`).
        Schema::create('dashboard_sent_lines', function (Blueprint $table): void {
            $table->uuid('quotation_id');
            $table->uuid('catalog_item_id')->nullable();
            $table->decimal('line_total', 18, 6);
            $table->decimal('base_line_total', 18, 6)->nullable();
            $table->index('quotation_id');
        });

        // `D-147`: a lost deal's `lost_reasons` code; null for a won one.
        Schema::table('dashboard_daily_outcomes', function (Blueprint $table): void {
            $table->string('reason_code', 64)->nullable();
        });
        DB::statement('ALTER TABLE dashboard_daily_outcomes DROP CONSTRAINT dashboard_daily_outcomes_key');
        DB::statement('ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_key UNIQUE NULLS NOT DISTINCT (day, owner_id, outcome, reason_code)');
        DB::statement("ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_reason CHECK ((outcome = 'lost') = (reason_code IS NOT NULL))");
    }

    /** Derived rows only: J-09's next run rebuilds what the narrower tables can hold. */
    public function down(): void
    {
        DB::table('dashboard_daily_outcomes')->delete();
        DB::statement('ALTER TABLE dashboard_daily_outcomes DROP CONSTRAINT dashboard_daily_outcomes_reason');
        DB::statement('ALTER TABLE dashboard_daily_outcomes DROP CONSTRAINT dashboard_daily_outcomes_key');
        Schema::table('dashboard_daily_outcomes', function (Blueprint $table): void {
            $table->dropColumn('reason_code');
        });
        DB::statement('ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_key UNIQUE NULLS NOT DISTINCT (day, owner_id, outcome)');

        Schema::dropIfExists('dashboard_sent_lines');

        Schema::table('dashboard_sent_versions', function (Blueprint $table): void {
            $table->dropColumn(['customer_id', 'sector']);
        });
    }
};
