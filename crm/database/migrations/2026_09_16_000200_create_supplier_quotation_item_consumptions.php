<?php

declare(strict_types=1);

use App\Support\Database\Precision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `D-81` (F-05 · 1.3): the idempotency guard behind
        // `SupplierItemQuantityInterface::consume()`. One row per key — the
        // key is the accepted customer-quotation line — so a replayed
        // transition inserts nothing and moves nothing. A technical table like
        // `idempotency_keys` (owner's ruling 2026-09-16): no `DB-01`/`DB-02`
        // block, because a guard row is never edited or deleted. Unlike that
        // table it carries no actor either — `D-81` puts the actor and the
        // old/new balance on the transition's own audit entry. `quantity` is
        // kept so the row says what it guarded; `Precision` spelled out for
        // 1.2's reason (the macro is `mixed` to PHPStan).
        Schema::create('supplier_quotation_item_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_quotation_item_id');
            $table->string('idempotency_key', 255);
            $table->decimal('quantity', Precision::QUANTITY_TOTAL, Precision::QUANTITY_SCALE);
            $table->timestampTz('created_at');

            $table->foreign('supplier_quotation_item_id')->references('id')->on('supplier_quotation_items');
            $table->unique('idempotency_key');
        });

        DB::statement(
            'ALTER TABLE supplier_quotation_item_consumptions '
            .'ADD CONSTRAINT supplier_quotation_item_consumptions_quantity_positive CHECK (quantity > 0)',
        );
    }

    public function down(): void
    {
        // `DEV-03`: no other table references this one.
        Schema::dropIfExists('supplier_quotation_item_consumptions');
    }
};
