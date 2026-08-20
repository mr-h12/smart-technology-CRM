<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §4.7 numbers every document as PREFIX-YEAR-NNNN. The format is documented; the
 * part that breaks is not — two users creating a quotation in the same second
 * must not receive the same code.
 *
 * MAX(sequence)+1 inside a transaction hands out duplicates under concurrent
 * inserts unless the whole table is locked. This table lets allocation be a
 * single atomic upsert instead, with UNIQUE(code) on each entity table as the
 * backstop DB-04 asks for.
 *
 * It is infrastructure, not a business entity, so DATABASE.md §1 exempts it from
 * the standard column block: there is no actor and nothing to soft-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->string('prefix', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value')->default(0);

            $table->primary(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
