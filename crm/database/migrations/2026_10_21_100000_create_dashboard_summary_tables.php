<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 14 · 2.1b — J-09's summary tables (`D-145` "Freshness", `D-146`, §15,
 * PRF-05). Derived data that J-09 deletes and rebuilds, so none carries the
 * soft-delete and actor columns of a business table (DATABASE.md lists the
 * "dashboard summary tables (J-09)" among the exceptions). A day is the
 * company's (`CompanyToday`), not UTC's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_daily_deals', function (Blueprint $table): void {
            $table->date('day');
            $table->uuid('owner_id')->nullable();
            $table->string('status', 32);
            $table->integer('deals');
            $table->foreign('owner_id')->references('id')->on('users');
        });

        Schema::create('dashboard_daily_customers', function (Blueprint $table): void {
            $table->date('day');
            $table->uuid('owner_id')->nullable();
            $table->integer('customers');
            $table->foreign('owner_id')->references('id')->on('users');
        });

        Schema::create('dashboard_daily_self_approvals', function (Blueprint $table): void {
            $table->date('day');
            $table->uuid('approver_id');
            $table->integer('approvals');
            $table->foreign('approver_id')->references('id')->on('users');
            $table->primary(['day', 'approver_id']);
        });

        Schema::create('dashboard_live_counts', function (Blueprint $table): void {
            $table->uuid('owner_id')->primary();
            $table->integer('pending_approvals');
            $table->integer('returned');
            $table->foreign('owner_id')->references('id')->on('users');
        });

        Schema::create('dashboard_refreshes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            // "Last updated" and the hourly run's starting point both read the newest run.
            $table->index('finished_at');
        });

        // One row per day, owner and status — an unowned deal is still one key (PostgreSQL 15+).
        DB::statement('ALTER TABLE dashboard_daily_deals ADD CONSTRAINT dashboard_daily_deals_key UNIQUE NULLS NOT DISTINCT (day, owner_id, status)');
        DB::statement('ALTER TABLE dashboard_daily_customers ADD CONSTRAINT dashboard_daily_customers_key UNIQUE NULLS NOT DISTINCT (day, owner_id)');
        DB::statement('ALTER TABLE dashboard_daily_deals ADD CONSTRAINT dashboard_daily_deals_positive CHECK (deals > 0)');
        DB::statement('ALTER TABLE dashboard_daily_customers ADD CONSTRAINT dashboard_daily_customers_positive CHECK (customers > 0)');
        DB::statement('ALTER TABLE dashboard_daily_self_approvals ADD CONSTRAINT dashboard_daily_self_approvals_positive CHECK (approvals > 0)');
        DB::statement('ALTER TABLE dashboard_live_counts ADD CONSTRAINT dashboard_live_counts_not_negative CHECK (pending_approvals >= 0 AND returned >= 0)');
        DB::statement("ALTER TABLE dashboard_refreshes ADD CONSTRAINT dashboard_refreshes_kind CHECK (kind IN ('hourly', 'nightly'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_refreshes');
        Schema::dropIfExists('dashboard_live_counts');
        Schema::dropIfExists('dashboard_daily_self_approvals');
        Schema::dropIfExists('dashboard_daily_customers');
        Schema::dropIfExists('dashboard_daily_deals');
    }
};
