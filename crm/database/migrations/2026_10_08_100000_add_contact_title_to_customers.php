<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `D-104` (F-38 · 1.2): a customer's contact person carries an optional title
 * from the managed list `contact_titles` (`DB-05`).
 *
 * `enum_lists_known_list` gains a sixth literal, the way
 * `extend_enum_lists_with_companies` added the fifth; the two titles are
 * literals too, because a migration is history and must not change meaning
 * when `ManagedLists` is edited later. The column is nullable (ruling 2), and
 * is a code, not a label, like `customers.sector`.
 */
return new class extends Migration
{
    private const LISTS = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies', 'contact_titles'];

    private const LISTS_BEFORE = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies'];

    public function up(): void
    {
        self::replaceConstraint(self::LISTS);

        $now = now();

        foreach ([['mr', 'Mr.', 1], ['mrs', 'Mrs.', 2]] as [$code, $en, $position]) {
            DB::table('enum_lists')->insert([
                'id' => (string) Str::uuid7(),
                'list' => 'contact_titles',
                'code' => $code,
                'label_en' => $en,
                'label_ar' => 'أ.',
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('contact_title', 64)->nullable();
        });
    }

    /**
     * `DEV-03`. The rows go before the constraint narrows, or it refuses them.
     * They are reference rows this migration inserted, not business data
     * (`DB-01`), and the column that pointed at them goes with them.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('contact_title');
        });

        DB::table('enum_lists')->where('list', 'contact_titles')->delete();

        self::replaceConstraint(self::LISTS_BEFORE);
    }

    /** @param  list<string>  $lists */
    private static function replaceConstraint(array $lists): void
    {
        $literals = "'".implode("', '", $lists)."'";

        DB::statement('ALTER TABLE enum_lists DROP CONSTRAINT enum_lists_known_list');
        DB::statement("ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_known_list CHECK (list IN ({$literals}))");
    }
};
