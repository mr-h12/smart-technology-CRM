<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `D-138` (Module 13 · 7.5a) — a user has at most one Team Leader. Nullable, so
 * every existing account stays valid; `nullOnDelete` so a removed leader's row
 * leaves the member with no leader rather than a dangling id. Indexed because
 * the Team scope reads "everyone whose leader is X".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('team_leader_id')->nullable()->index();
            $table->foreign('team_leader_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['team_leader_id']);
            $table->dropIndex(['team_leader_id']);
            $table->dropColumn('team_leader_id');
        });
    }
};
