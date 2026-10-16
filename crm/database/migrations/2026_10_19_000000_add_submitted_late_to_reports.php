<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `D-135`: a report submitted after J-04 marked it Missed is stored as late.
 * NOT NULL, false for every report already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', static function (Blueprint $table): void {
            $table->boolean('submitted_late')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reports', static function (Blueprint $table): void {
            $table->dropColumn('submitted_late');
        });
    }
};
