<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            // The owner's Q2 ruling (2026-09-15, #131, Module 8 Point 1.2): a
            // return moves the **same row** `pending → draft` (§6.4's arrow as
            // the `quotations` docblock reads it — no Returned row), so the
            // moment and the mandatory note live on the row itself. §8's
            // "incomplete" bucket (2.2) is `draft` with `returned_at` set, and
            // the detail (2.1) shows the note to whoever edits next. Both
            // nullable: most quotations are never returned. `timestampTz` as
            // `submitted_at` is — `DB-08`.
            $table->timestampTz('returned_at')->nullable();
            $table->text('return_note')->nullable();
        });
    }

    public function down(): void
    {
        // `DEV-03`: dropping both returns `quotations` to Module 7's shape.
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['returned_at', 'return_note']);
        });
    }
};
