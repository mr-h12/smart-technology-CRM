<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 13, slice 2 — the receiving facts, per version on the version row
 * (the owner, 2026-10-16; §11.4, `D-127`): who viewed, acknowledged and
 * returned it, when, and why it was returned. All nullable: a version starts with none.
 */
return new class extends Migration
{
    private const ACTORS = ['viewed_by', 'acknowledged_by', 'returned_by'];

    public function up(): void
    {
        Schema::table('report_versions', function (Blueprint $table): void {
            $table->uuid('viewed_by')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('returned_by')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->text('return_reason')->nullable();

            foreach (self::ACTORS as $column) {
                $table->foreign($column)->references('id')->on('users');
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_versions', function (Blueprint $table): void {
            foreach (self::ACTORS as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn([
                'viewed_by', 'viewed_at', 'acknowledged_by', 'acknowledged_at', 'returned_by', 'returned_at', 'return_reason',
            ]);
        });
    }
};
