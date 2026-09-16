<?php

declare(strict_types=1);

use App\Support\Database\Precision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            // `D-81` (F-05): `quantity` stays the supplier's original offer
            // (§7.2) and is never edited; what the accepted customer quotation
            // drew from it accumulates here. The two `Precision` constants are
            // exactly what the `quantity()` macro applies — spelled out because
            // the macro is opaque to PHPStan and this column needs a modifier:
            // `default(0)` because nothing is backfilled — every existing line
            // has consumed nothing. No CHECK against `quantity`: exceeding the
            // offer is allowed with a warning (§5.6), and the available balance
            // is `quantity - consumed_quantity`, computed where NUMERIC is
            // exact, never stored.
            $table->decimal('consumed_quantity', Precision::QUANTITY_TOTAL, Precision::QUANTITY_SCALE)->default(0);
        });
    }

    public function down(): void
    {
        // `DEV-03`: dropping the column returns the table to Point 1.2's shape.
        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            $table->dropColumn('consumed_quantity');
        });
    }
};
