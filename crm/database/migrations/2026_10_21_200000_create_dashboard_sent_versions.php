<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 14 · 3.2 — J-09's money projection (`D-141`, `D-144`, `D-149`): one row
 * per live sent quotation version. Kept at version grain because `D-141` picks,
 * per chain, the latest version sent *in the period asked for* — a day total
 * could not answer that. Derived and rebuilt whole by every J-09 run, so it
 * carries no soft-delete or actor columns (DATABASE.md's J-09 exception).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_sent_versions', function (Blueprint $table): void {
            $table->uuid('quotation_id')->primary();
            // The chain's root version (`parent_id` followed to the top).
            $table->uuid('chain_id');
            $table->integer('version');
            $table->date('sent_day');
            $table->uuid('owner_id')->nullable();
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->decimal('net_amount', 18, 6);
            $table->decimal('additional_total', 18, 6);
            $table->decimal('cost', 18, 6);
            // `D-149`: at the rate in force when the version was created; null when the pair had none.
            $table->decimal('base_net_amount', 18, 6)->nullable();
            $table->decimal('base_additional_total', 18, 6)->nullable();
            $table->decimal('base_cost', 18, 6)->nullable();
            $table->foreign('owner_id')->references('id')->on('users');
            // The read: a period's versions, then each chain's latest.
            $table->index(['sent_day', 'chain_id', 'version']);
        });

        DB::statement('ALTER TABLE dashboard_sent_versions ADD CONSTRAINT dashboard_sent_versions_base_together '
            .'CHECK ((base_net_amount IS NULL) = (base_additional_total IS NULL) AND (base_net_amount IS NULL) = (base_cost IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_sent_versions');
    }
};
