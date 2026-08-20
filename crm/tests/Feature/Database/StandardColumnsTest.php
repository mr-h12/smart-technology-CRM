<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The standard column block, exercised rather than inspected.
 *
 * Asserting that columns exist would pass on a table that cannot actually hold
 * a row. These tests write and read, because DB-01 and DB-02 are behaviours:
 * a delete that removes the row, or a key that is not time-ordered, both look
 * fine in a schema dump.
 */
final class StandardColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('standard_column_probes', function (Blueprint $table): void {
            $table->standardColumns();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('standard_column_probes');
        parent::tearDown();
    }

    public function test_the_key_is_a_time_ordered_uuid(): void
    {
        // D-61: a UUID, and time-ordered so inserts stay sequential and the
        // index does not fragment. Version 7 carries the timestamp in its high
        // bits, which is what makes consecutive values sort in creation order.
        $first = StandardColumnProbe::create(['name' => 'first']);
        usleep(2000);
        $second = StandardColumnProbe::create(['name' => 'second']);

        self::assertTrue(Uuid::isValid($first->id));
        self::assertSame(7, Uuid::fromString($first->id)->getVersion());
        self::assertLessThan($second->id, $first->id,
            'D-61 requires time-ordered keys: a later row must sort after an earlier one.');
    }

    public function test_the_database_stores_the_key_as_a_uuid_type(): void
    {
        // Not a string that happens to look like one — PostgreSQL's uuid type
        // is what keeps it 16 bytes and comparable.
        self::assertSame('uuid', self::columnType('id'));
    }

    public function test_deleting_is_soft_and_the_row_survives(): void
    {
        // DB-01: no physical DELETE. The row must still be in the table.
        $probe = StandardColumnProbe::create(['name' => 'keep me']);
        $probe->delete();

        self::assertSame(0, StandardColumnProbe::count());
        self::assertSame(1, StandardColumnProbe::withTrashed()->count());
        self::assertSame(1, (int) DB::table('standard_column_probes')->count());
        $trashed = StandardColumnProbe::withTrashed()->first();
        self::assertNotNull($trashed);
        self::assertNotNull($trashed->deleted_at);
    }

    public function test_the_audit_columns_exist_and_accept_an_actor(): void
    {
        // DB-02. They are nullable because Module 1 has not built the users
        // table yet and Module 0 step 6 wires the actor in with the audit layer.
        $actor = (string) \Illuminate\Support\Str::uuid7();
        $probe = StandardColumnProbe::create(['name' => 'with actor', 'created_by' => $actor]);

        $stored = $probe->fresh();
        self::assertNotNull($stored);
        self::assertSame($actor, $stored->created_by);
        foreach (['created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertTrue(
                Schema::hasColumn('standard_column_probes', $column),
                "DB-02 requires {$column}."
            );
        }
    }

    public function test_timestamps_are_stored_with_a_timezone(): void
    {
        // DB-08: UTC in storage. timestamptz is what makes that unambiguous;
        // a plain timestamp would silently record a local instant.
        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            self::assertSame('timestamp with time zone', self::columnType($column),
                "{$column} must be timestamptz.");
        }
    }

    /**
     * information_schema through selectOne returns mixed, which level 10 will
     * not let us dereference. Narrowed once here rather than cast at each use.
     */
    private static function columnType(string $column): string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns
             where table_name = ? and column_name = ?',
            ['standard_column_probes', $column],
        );

        self::assertIsObject($row);
        self::assertObjectHasProperty('data_type', $row);

        return (string) $row->data_type;   // @phpstan-ignore-line cast.string
    }
}

/**
 * Larastan reads Eloquent's magic from a model's documented shape, and this
 * table exists only for the duration of the test, so the columns the standard
 * block adds are declared here.
 *
 * @property string $id
 * @property string $name
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class StandardColumnProbe extends Model
{
    use HasStandardColumns;

    protected $table = 'standard_column_probes';

    protected $guarded = [];
}
