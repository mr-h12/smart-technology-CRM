<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `D-122` (F-50 · 3): a customer's notes are separate entries, each with an
 * optional date. One migration creates `customer_notes` and copies each
 * customer's non-blank `notes` text into one undated note.
 */
final class CustomerNotesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_100000_create_customer_notes.php';

    public function test_that_the_table_has_its_keys_index_and_audit_columns(): void
    {
        foreach (['id', 'customer_id', 'body', 'note_date', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertTrue(Schema::hasColumn('customer_notes', $column), "customer_notes has no {$column}.");
        }

        $nullable = DB::table('information_schema.columns')
            ->where('table_name', 'customer_notes')
            ->whereIn('column_name', ['customer_id', 'body', 'note_date'])
            ->pluck('is_nullable', 'column_name')
            ->all();

        self::assertSame(['body' => 'NO', 'customer_id' => 'NO', 'note_date' => 'YES'], collect($nullable)->sortKeys()->all());

        $foreignKey = DB::selectOne(
            "select ccu.table_name from information_schema.table_constraints tc
             join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
             where tc.table_name = 'customer_notes' and tc.constraint_type = 'FOREIGN KEY'",
        );
        self::assertNotNull($foreignKey, 'customer_notes.customer_id has no foreign key.');
        self::assertSame('customers', $foreignKey->table_name);

        $index = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'customer_notes' and indexdef like '%customer_id%note_date%'",
        );
        self::assertNotNull($index, 'No index on (customer_id, note_date) for the list.');
    }

    /** A blank body is not a note: the CHECK refuses one, as `customers` refuses a blank name. */
    public function test_that_a_blank_body_is_refused_by_the_database(): void
    {
        $customer = $this->customer('Alpha Trading', null);

        try {
            DB::table('customer_notes')->insert([
                'id' => (string) Str::uuid7(),
                'customer_id' => $customer,
                'body' => '   ',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('A blank body was stored.');
        } catch (\Illuminate\Database\QueryException $e) {
            // 23514 is a CHECK violation; a missing table (42P01) must not pass for one.
            self::assertSame('23514', $e->getCode());
        }
    }

    /** The owner's ruling: the old notes become the first, undated note; blank ones are not copied. */
    public function test_that_each_non_blank_old_note_becomes_one_undated_note(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));

        $alpha = $this->customer('Alpha Trading', "Called on the 3rd.\nAsked for a revised offer.");
        $beta = $this->customer('Beta Medical', '   ');
        $gamma = $this->customer('Gamma Hotels', null);

        self::assertSame(0, Artisan::call('migrate'));

        $notes = DB::table('customer_notes')->get(['customer_id', 'body', 'note_date', 'deleted_at']);

        self::assertCount(1, $notes);
        self::assertSame($alpha, $notes[0]->customer_id);
        self::assertSame("Called on the 3rd.\nAsked for a revised offer.", $notes[0]->body);
        self::assertNull($notes[0]->note_date);
        self::assertNull($notes[0]->deleted_at);

        // The old column keeps its text: data is never dropped (`DB-01`).
        $this->assertDatabaseHas('customers', ['id' => $alpha, 'notes' => "Called on the 3rd.\nAsked for a revised offer."]);
        self::assertSame(0, DB::table('customer_notes')->whereIn('customer_id', [$beta, $gamma])->count());
    }

    // ────────────────────────────────────────────────────────────── DEV-03

    public function test_that_the_migration_rolls_back_and_forward(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]));
        self::assertFalse(Schema::hasTable('customer_notes'), 'down() left customer_notes behind (DEV-03).');
        self::assertTrue(Schema::hasColumn('customers', 'notes'), 'down() must not touch the old column.');

        self::assertSame(0, Artisan::call('migrate'));
        self::assertTrue(Schema::hasTable('customer_notes'), 'customer_notes did not come back.');
    }

    private function customer(string $name, ?string $notes): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => 'prospect',
            'notes' => $notes,
            'is_archived' => false,
            'is_incomplete' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
