<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 14 · 4.2 — J-09's pipeline tables (`D-144`, `D-150`). Derived, rebuilt
 * whole by every run, so no soft-delete or actor columns (DATABASE.md's J-09 exception).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deals by the company day they first moved into `won` or `lost`; for won, the days from creation summed.
        Schema::create('dashboard_daily_outcomes', function (Blueprint $table): void {
            $table->date('day');
            $table->uuid('owner_id')->nullable();
            $table->string('outcome', 8);
            $table->integer('deals');
            $table->integer('cycle_days');
            $table->foreign('owner_id')->references('id')->on('users');
        });

        // "Now": open deals (before `won`, not `lost`) per owner.
        Schema::create('dashboard_live_workload', function (Blueprint $table): void {
            $table->uuid('owner_id')->primary();
            $table->integer('open_deals');
            $table->foreign('owner_id')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_key UNIQUE NULLS NOT DISTINCT (day, owner_id, outcome)');
        DB::statement("ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_outcome CHECK (outcome IN ('won', 'lost'))");
        DB::statement("ALTER TABLE dashboard_daily_outcomes ADD CONSTRAINT dashboard_daily_outcomes_counts CHECK (deals > 0 AND cycle_days >= 0 AND (outcome = 'won' OR cycle_days = 0))");
        DB::statement('ALTER TABLE dashboard_live_workload ADD CONSTRAINT dashboard_live_workload_positive CHECK (open_deals > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_live_workload');
        Schema::dropIfExists('dashboard_daily_outcomes');
    }
};
