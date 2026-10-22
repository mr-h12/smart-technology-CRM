<?php

declare(strict_types=1);

use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Domain\Reference\ManagedLists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module 14 · 5.0 — `D-147`: a Rejected customer response takes a reason code
 * from the managed list `lost_reasons`, and a deal it makes Lost carries the
 * code beside the note it already copies.
 *
 * The database keeps every older row valid — a reason is "a code **or** a
 * text", and a code of `other` needs its text — while the API requires the code
 * on every new rejection. Requiring the code here as well would refuse the
 * history rows before the backfill could reach them, and every fixture that
 * writes a lost deal the way the system did until today.
 */
return new class extends Migration
{
    private const LISTS = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies', 'contact_titles', 'lost_reasons'];

    private const LISTS_BEFORE = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies', 'contact_titles'];

    public function up(): void
    {
        self::replaceListConstraint(self::LISTS);

        $now = now();
        foreach (ManagedLists::for(ManagedList::LostReasons) as $entry) {
            DB::table('enum_lists')->insert([
                'id' => (string) Str::uuid7(), 'list' => ManagedList::LostReasons->value, 'code' => $entry->code(),
                'label_en' => $entry->labelEn(), 'label_ar' => $entry->labelAr(), 'position' => $entry->position(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('rejection_reason_code', 64)->nullable();
        });
        Schema::table('deals', function (Blueprint $table): void {
            $table->string('lost_reason_code', 64)->nullable();
        });

        // `D-147`: every reason recorded before this is "Other", its text kept as the note.
        DB::table('quotations')->where('status', 'rejected')->update(['rejection_reason_code' => 'other']);
        DB::table('deals')->where('status', 'lost')->update(['lost_reason_code' => 'other']);

        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_reason_required_when_rejected_or_counter');
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_reason_required_when_rejected_or_counter CHECK (
            (status <> 'counter' OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))
            AND (status <> 'rejected' OR rejection_reason_code IS NOT NULL OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))
            AND (rejection_reason_code IS DISTINCT FROM 'other' OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))
        )");

        DB::statement('ALTER TABLE deals DROP CONSTRAINT deals_lost_reason_required_when_lost');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_lost_reason_required_when_lost CHECK (
            (status IS DISTINCT FROM 'lost' OR lost_reason_code IS NOT NULL OR (lost_reason IS NOT NULL AND btrim(lost_reason) <> ''))
            AND (lost_reason_code IS DISTINCT FROM 'other' OR (lost_reason IS NOT NULL AND btrim(lost_reason) <> ''))
        )");
    }

    /**
     * `DEV-03`. Before the old rules return, a coded reason with no note takes its
     * code's English label as its text, so no row breaks the old CHECKs.
     */
    public function down(): void
    {
        foreach (ManagedLists::for(ManagedList::LostReasons) as $entry) {
            DB::table('quotations')->where('rejection_reason_code', $entry->code())
                ->where(fn (Illuminate\Database\Query\Builder $q) => $q->whereNull('rejection_reason')->orWhereRaw("btrim(rejection_reason) = ''"))
                ->update(['rejection_reason' => $entry->labelEn()]);
            DB::table('deals')->where('lost_reason_code', $entry->code())
                ->where(fn (Illuminate\Database\Query\Builder $q) => $q->whereNull('lost_reason')->orWhereRaw("btrim(lost_reason) = ''"))
                ->update(['lost_reason' => $entry->labelEn()]);
        }

        DB::statement('ALTER TABLE quotations DROP CONSTRAINT quotations_reason_required_when_rejected_or_counter');
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_reason_required_when_rejected_or_counter '
            ."CHECK (status NOT IN ('rejected', 'counter') OR (rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))");
        DB::statement('ALTER TABLE deals DROP CONSTRAINT deals_lost_reason_required_when_lost');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_lost_reason_required_when_lost '
            ."CHECK (status IS DISTINCT FROM 'lost' OR (lost_reason IS NOT NULL AND btrim(lost_reason) <> ''))");

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('lost_reason_code');
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('rejection_reason_code');
        });

        DB::table('enum_lists')->where('list', ManagedList::LostReasons->value)->delete();
        self::replaceListConstraint(self::LISTS_BEFORE);
    }

    /** @param  list<string>  $lists */
    private static function replaceListConstraint(array $lists): void
    {
        $literals = "'".implode("', '", $lists)."'";

        DB::statement('ALTER TABLE enum_lists DROP CONSTRAINT enum_lists_known_list');
        DB::statement("ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_known_list CHECK (list IN ({$literals}))");
    }
};
