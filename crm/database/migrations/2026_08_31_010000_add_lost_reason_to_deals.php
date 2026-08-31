<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5, Point 2.6 — closes the open question Point 1.1 recorded and
 * deferred: "§4.4 separately requires a reason for a deal reaching `Lost`
 * ... no field in §4.3's table is named for it ... left to whichever point
 * first implements a status transition to `Lost`." This is that point.
 *
 * ── A new column, not a reuse of `rejection_reason` ────────────────────────
 *
 * `rejection_reason` (Point 1.1) is tied by its own CHECK to exactly one
 * rejection — `approval_status = 'rejected'`, Flow 3's pre-pipeline refusal
 * of an employee-entered request. `Lost` is a different fact: a deal that
 * *entered* the pipeline (`approval_status` is `null` or `approved` — never
 * `rejected`, because a rejected request never reaches `Quotation Sent` or
 * `Negotiations`, the only two states §4.4 allows `Lost` from) and did not
 * close. Writing it into `rejection_reason` would make one column answer two
 * unrelated questions, and a query for "why was this rejected" would have to
 * know which kind of rejection it was reading.
 *
 * ── Mandatory only for `Lost`, on `rejection_reason`'s own CHECK shape ─────
 *
 * §4.4: "Lost | Sales (mandatory reason) | Terminal." The CHECK mirrors
 * `deals_rejection_reason_required_when_rejected` exactly, substituting the
 * one status this column is mandatory for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->text('lost_reason')->nullable();
        });

        DB::statement(
            'ALTER TABLE deals ADD CONSTRAINT deals_lost_reason_required_when_lost '
            ."CHECK (status IS DISTINCT FROM 'lost' OR (lost_reason IS NOT NULL AND btrim(lost_reason) <> ''))"
        );
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('lost_reason');
        });
    }
};
