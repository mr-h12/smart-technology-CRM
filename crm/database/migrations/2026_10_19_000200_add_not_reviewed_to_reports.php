<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `D-136`: J-06 submitted a weekly report without its author's review.
 * NOT NULL, false for every report already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', static function (Blueprint $table): void {
            $table->boolean('not_reviewed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reports', static function (Blueprint $table): void {
            $table->dropColumn('not_reviewed');
        });
    }
};
