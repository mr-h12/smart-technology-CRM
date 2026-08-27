<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2's managed lists — `enum_lists`.
 *
 * **`DB-05`:** *"Enum tables, not hard-coded enums — sectors · units · service
 * types · delivery terms"*, and the module's acceptance criterion says what
 * that buys: a new sector appears in the customer form **without a
 * deployment**. Four lists in one table rather than four tables, because they
 * differ only in what references them and every screen reads them the same way.
 *
 * **The membership is data; the set of lists is not.** `ManagedList` records
 * the reasoning: adding a fifth list means adding the column that points at it,
 * which is a migration either way. So `list` is CHECKed against four literals —
 * written out here because a migration is history and must not change meaning
 * when an enum is edited later, the same rule `create_rbac_tables` follows for
 * its scopes. `ManagedListSchemaMigrationTest` pins these against
 * `ManagedList::cases()`; neither side can read the other.
 *
 * **Both labels are NOT NULL.** §14.2 requires Arabic and English from the
 * first release, and `ListEntry` already takes both as non-nullable strings.
 * `roles.name_ar` is the counter-example this avoids — nullable, and null for
 * all eight rows, which is an English word on an Arabic screen.
 *
 * **`position` is not unique.** Nothing documents what two entries sharing a
 * position should mean, and a constraint invented here would be a rule the
 * documentation never made. Ties order by `code`; the index below is what makes
 * the read cheap.
 */
return new class extends Migration
{
    /** `ManagedList`'s four cases, as literals. See the class docblock. */
    private const LISTS = ['sectors', 'units', 'service_types', 'delivery_terms'];

    public function up(): void
    {
        Schema::create('enum_lists', function (Blueprint $table): void {
            // D-61 UUIDv7 key · DB-02 actor and timestamps · DB-01 soft delete.
            $table->standardColumns();

            // Which of the four. 32 fits `delivery_terms` with room to spare.
            $table->string('list', 32);

            // The machine key a column stores — `customers.sector_id` points at
            // the row, but exports and seeds address the code.
            $table->string('code', 64);

            // §14.2: both languages, both required.
            $table->string('label_en', 128);
            $table->string('label_ar', 128);

            // The order a screen renders. Unsigned would refuse a negative at
            // the type level; the CHECK below says it in a way information_schema
            // and the error message both make legible.
            $table->integer('position');
        });

        $lists = "'".implode("', '", self::LISTS)."'";

        DB::statement("ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_known_list CHECK (list IN ({$lists}))");

        // A blank code is not a code — it would take the one slot the partial
        // unique index below allows and leave an entry nothing can address.
        DB::statement("ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_code_not_blank CHECK (btrim(code) <> '')");

        DB::statement('ALTER TABLE enum_lists ADD CONSTRAINT enum_lists_position_not_negative CHECK (position >= 0)');

        // Unique per list, not globally: `other` is a sector and a unit, and
        // both are correct. Partial because DB-01 soft-deletes, and a plain
        // UNIQUE would let one archived entry reserve its code forever.
        DB::statement(
            'CREATE UNIQUE INDEX enum_lists_code_unique_alive '
            .'ON enum_lists (list, code) WHERE deleted_at IS NULL'
        );

        // The read path is one whole list in display order (DB-09).
        DB::statement('CREATE INDEX enum_lists_ordered ON enum_lists (list, position, code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        // DEV-03. The constraints and both indexes go with the table.
        Schema::dropIfExists('enum_lists');
    }
};
