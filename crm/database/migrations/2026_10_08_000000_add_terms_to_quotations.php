<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-37 · 1.2 — `D-103`: a quotation's terms are one list of up to 15
 * `{key, title, body}`, `key` naming one of the three ready terms
 * (`payment_terms`, `warranty`, `delivery_terms`) or null for one the employee
 * added. A `jsonb` column on the quotation and not a child table: a new
 * version's `replicate()` copies it with the rest of the document.
 *
 * The three term columns and `show_delivery_terms` stay, unwritten — nothing
 * is deleted (`DB-01`), and dropping them is a debt-register row. Each
 * existing quotation gets its old terms in their order, untitled so the PDF
 * prints its own label; the delivery term only where the flag was on, so no
 * existing quotation prints differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->jsonb('terms')->default('[]');
        });

        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_terms_list CHECK (jsonb_typeof(terms) = 'array' AND jsonb_array_length(terms) <= 15)");

        DB::statement(<<<'SQL'
            UPDATE quotations q SET terms = COALESCE((
                SELECT jsonb_agg(jsonb_build_object('key', t.key, 'title', NULL, 'body', t.body) ORDER BY t.position)
                FROM (VALUES
                    (1, 'payment_terms', q.payment_terms),
                    (2, 'warranty', q.warranty),
                    (3, 'delivery_terms', CASE WHEN q.show_delivery_terms THEN q.delivery_terms END)
                ) AS t(position, key, body)
                WHERE btrim(COALESCE(t.body, '')) <> ''
            ), '[]'::jsonb)
            SQL);
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('terms');
        });
    }
};
