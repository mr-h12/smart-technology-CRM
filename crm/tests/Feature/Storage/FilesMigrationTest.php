<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 5.1 — the `files` table and its attachment pivots.
 *
 * `D-71` closed `Q-2` by rejecting the polymorphic column the draft carried.
 * The reason is the thing this file exists to keep true: **PostgreSQL cannot
 * constrain one column against five tables**, so `entity_id` would have been
 * free to name a deal that never existed, and nothing in the database would
 * have objected. `J-11` — a weekly job for files linked to nothing — was the
 * specification admitting in advance that this would happen.
 *
 * So the constraints are the feature. A schema dump showing the right column
 * names proves nothing: what matters is whether the database refuses the wrong
 * row, and these tests make it refuse.
 *
 * ── One constraint per parent is deliberately absent ───────────────────────
 *
 * `deals`, `supplier_quotations`, `purchase_orders` and `reports` are Modules
 * 5, 6, 10 and 13. A foreign key cannot reference a table that does not exist,
 * so the pivots carry the column and the index now and the constraint arrives
 * with the parent — the same shape `standardActorForeignKeys()` already uses
 * for `created_by`, and for the same reason. PENDING_PARENT_KEYS below is that
 * debt written where a machine can read it rather than in a comment.
 */
final class FilesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** `§17` and `D-71`. */
    private const PIVOTS = [
        'deal_files' => ['parent' => 'deals', 'column' => 'deal_id'],
        'supplier_quotation_files' => ['parent' => 'supplier_quotations', 'column' => 'supplier_quotation_id'],
        'purchase_order_files' => ['parent' => 'purchase_orders', 'column' => 'purchase_order_id'],
        'report_files' => ['parent' => 'reports', 'column' => 'report_id'],
        // Module 9, Point 1.3 — the fifth pivot. Module 0 shipped four and not
        // this one; `quotations` already existed, so it arrives with its key.
        'quotation_files' => ['parent' => 'quotations', 'column' => 'quotation_id'],
    ];

    /** `D-71`: 30 MB, superseding the 10 MB in `D-39`. */
    private const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;

    // ─────────────────────────────────────────────────────── the files table

    public function test_the_files_table_carries_the_documented_columns(): void
    {
        self::assertTrue(Schema::hasTable('files'));

        // §17 and DATABASE.md. Asserted as a whole set so a surplus column is
        // as visible as a missing one — a column nobody documented is a column
        // nobody maintains.
        $expected = [
            'id', 'original_name', 'mime_type', 'size_bytes', 'storage_path',
            'scan_status', 'scanned_at',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ];
        sort($expected);

        $actual = Schema::getColumnListing('files');
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function test_the_files_table_carries_the_standard_column_block(): void
    {
        // §4.8, DB-01 and DB-02. A business table without an actor cannot answer
        // "who uploaded this", and without deleted_at an attachment disappears
        // instead of being archived.
        self::assertSame('uuid', self::columnType('files', 'id'));
        self::assertSame('uuid', self::columnType('files', 'created_by'));
        self::assertSame('uuid', self::columnType('files', 'updated_by'));
        self::assertSame('timestamp with time zone', self::columnType('files', 'created_at'));
        self::assertSame('timestamp with time zone', self::columnType('files', 'deleted_at'));
    }

    public function test_the_storage_path_cannot_be_claimed_twice(): void
    {
        // §17 names the file on disk by its UUID, so two rows sharing a path
        // means two rows owning one file — and deleting either orphans or
        // destroys the other's bytes.
        $path = '2026/08/deals/'.Uuid::uuid7()->toString().'/'.Uuid::uuid7()->toString().'.pdf';

        self::insertFile(['storage_path' => $path]);

        try {
            self::insertFile(['storage_path' => $path]);
        } catch (QueryException $e) {
            // 23505 — unique_violation, not merely "something went wrong".
            self::assertSame('23505', $e->getCode());

            return;
        }

        self::fail('Two rows claimed the same file on disk.');
    }

    public function test_a_file_row_survives_deletion(): void
    {
        // DB-01: no physical deletion of business data.
        $id = self::insertFile();

        DB::table('files')->where('id', $id)->update(['deleted_at' => now()]);

        self::assertSame(1, DB::table('files')->where('id', $id)->count());
    }

    public function test_a_file_cannot_have_a_meaningless_size(): void
    {
        // DB-04 asks for database constraints, and this one is not decoration:
        // size_bytes is what the 30 MB limit is checked against, and a zero or
        // negative value would pass every limit check there is.
        self::assertCheckViolation(fn () => self::insertFile(['size_bytes' => 0]),
            'files accepted a size of zero.');
    }

    public function test_the_scan_status_is_constrained_to_the_documented_three(): void
    {
        // SEC-15 makes scanning mandatory, and 5.5 will refuse to serve anything
        // that is not `clean`. A typo'd status would read as "not clean" and
        // quarantine a good file, or — worse, if the comparison is ever
        // inverted — serve an unscanned one.
        //
        // A CHECK rather than an enum table, and that is provisional: DB-05 says
        // enum tables, Q-6 is still open about which columns qualify, and its
        // suggested split keeps state a machine depends on in a constrained
        // column. Revisit when Q-6 closes.
        self::assertCheckViolation(fn () => self::insertFile(['scan_status' => 'quarantined']),
            'files accepted a scan status that is not one of the documented three.');
    }

    // ──────────────────────────────────────────────────────────── the pivots

    /** @return array<string, array{0: string, 1: string}> */
    public static function pivots(): array
    {
        $cases = [];

        foreach (self::PIVOTS as $table => $meta) {
            $cases[$table] = [$table, $meta['column']];
        }

        return $cases;
    }

    #[DataProvider('pivots')]
    public function test_the_pivot_exists_with_exactly_two_columns(string $table, string $column): void
    {
        self::assertTrue(Schema::hasTable($table), "{$table} does not exist (D-71).");

        $actual = Schema::getColumnListing($table);
        sort($actual);

        $expected = [$column, 'file_id'];
        sort($expected);

        // No entity_type. That was the polymorphic draft D-71 rejected, and its
        // reappearance here would mean the constraint had been given up on.
        self::assertSame($expected, $actual);
    }

    #[DataProvider('pivots')]
    public function test_the_pivot_cannot_attach_one_file_twice(string $table, string $column): void
    {
        // The composite primary key, doing the work an application-level check
        // would do worse: two concurrent requests can both pass a "does it
        // already exist" query and only one can win a primary key.
        $file = self::insertFile();
        $parent = self::parentId($table);

        DB::table($table)->insert([$column => $parent, 'file_id' => $file]);

        $this->expectException(QueryException::class);
        DB::table($table)->insert([$column => $parent, 'file_id' => $file]);
    }

    #[DataProvider('pivots')]
    public function test_the_pivot_refuses_a_file_that_does_not_exist(string $table, string $column): void
    {
        // This is the whole of D-71 in one assertion. Under the polymorphic
        // draft this insert would have succeeded.
        //
        // The table check is not ceremony. Written as a bare
        // expectException(QueryException::class), this test passed before the
        // migration existed — "relation does not exist" is a QueryException
        // too — so it reported success while proving nothing. Naming the
        // SQLSTATE is what makes it a test of the constraint rather than of
        // the table's absence.
        self::assertTrue(Schema::hasTable($table), "{$table} does not exist yet.");

        try {
            DB::table($table)->insert([
                $column => Uuid::uuid7()->toString(),
                'file_id' => Uuid::uuid7()->toString(),
            ]);
        } catch (QueryException $e) {
            // 23503 — foreign_key_violation.
            self::assertSame('23503', $e->getCode(), 'The insert failed, but not on the foreign key.');

            return;
        }

        self::fail("{$table} accepted a file_id that references nothing (D-71).");
    }

    #[DataProvider('pivots')]
    public function test_deleting_a_file_row_takes_its_links_with_it(string $table, string $column): void
    {
        // ON DELETE CASCADE. DB-01 means this should never run in production —
        // but a repair or a migration can, and when it does the alternative is
        // a pivot row pointing at nothing, which is the state D-71 exists to
        // make impossible.
        $file = self::insertFile();
        DB::table($table)->insert([$column => self::parentId($table), 'file_id' => $file]);

        DB::table('files')->where('id', $file)->delete();

        self::assertSame(0, DB::table($table)->where('file_id', $file)->count());
    }

    #[DataProvider('pivots')]
    public function test_the_pivot_indexes_the_reverse_lookup(string $table, string $column): void
    {
        // Every permission check reads a parent's attachments (D-38), which the
        // primary key serves. J-11 asks the opposite question — which files have
        // no parent — and a key on (parent_id, file_id) cannot answer it: a
        // B-tree is only searchable from its leading column.
        //
        // Which is why this asserts the *leading* column and not membership.
        // The first version asked whether any index mentioned file_id, and the
        // composite primary key mentions it — so deleting the real index left
        // all 28 tests green. Found by deleting it on purpose.
        self::assertContains(
            'file_id',
            self::leadingIndexColumns($table),
            "{$table} has no index led by file_id; J-11 would scan it weekly.",
        );
    }

    public function test_the_parent_foreign_keys_that_are_still_owed_are_recorded(): void
    {
        // The debt, machine-readable. When the parent module creates its table,
        // this test fails until the constraint is added and the entry removed —
        // which is the point: a comment would not. `deals` closed the first
        // entry (Module 5, Point 1.1); `supplier_quotations` closed the second
        // (Module 6, Point 1.1) and `purchase_orders` the third (Module 10,
        // Point 1.6), each in the migration that created the parent.
        $pending = [];

        foreach (self::PIVOTS as $table => $meta) {
            if (! Schema::hasTable($meta['parent'])) {
                $pending[] = $table.'.'.$meta['column'].' → '.$meta['parent'];
            }
        }

        self::assertSame([
            'report_files.report_id → reports',
        ], $pending, 'A parent table now exists; add its foreign key and drop it from this list.');
    }

    // ───────────────────────────────────────────────────── the 30 MB ceiling

    public function test_php_accepts_an_upload_at_the_documented_limit(): void
    {
        // D-71 raises the limit to 30 MB and says plainly that the application
        // limit is not the only ceiling. PHP cuts first, and it cuts with a
        // failure that explains nothing — the request simply arrives empty.
        self::assertGreaterThanOrEqual(
            self::MAX_UPLOAD_BYTES,
            self::iniBytes('upload_max_filesize'),
            'upload_max_filesize is below the 30 MB D-71 allows.',
        );

        // post_max_size caps the whole body, so it has to clear the file plus
        // its multipart overhead rather than merely equal it.
        self::assertGreaterThan(
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
            'post_max_size must leave room for the multipart envelope around the file.',
        );
    }

    public function test_the_configured_limit_is_the_documented_thirty_megabytes(): void
    {
        // D-71's number, in the one place the application will read it. It lives
        // in config rather than as a constant because §17 says "configurable"
        // and CLAUDE.md keeps limits out of code — and it lives in config rather
        // than the settings table only because that table is Module 2. Module 2
        // moves it and this test moves with it.
        self::assertSame(self::MAX_UPLOAD_BYTES, config('files.max_size_bytes'));

        // And it must stay under the ceilings above it, or the documented limit
        // is not the one that applies.
        self::assertLessThanOrEqual(self::iniBytes('upload_max_filesize'), self::MAX_UPLOAD_BYTES);
    }

    // ───────────────────────────────────────────────────────────── mechanics

    /**
     * Assert a callable fails on a CHECK constraint specifically.
     *
     * Written because the first version of the two tests above used a bare
     * expectException(QueryException::class) and passed before the table
     * existed — "relation does not exist" is a QueryException too. Twice in one
     * point, which is why every negative assertion here names its SQLSTATE.
     */
    private static function assertCheckViolation(callable $insert, string $message): void
    {
        self::assertTrue(Schema::hasTable('files'), 'files does not exist yet.');

        try {
            $insert();
        } catch (QueryException $e) {
            // 23514 — check_violation.
            self::assertSame('23514', $e->getCode(), 'The insert failed, but not on a check constraint.');

            return;
        }

        self::fail($message);
    }

    /**
     * A parent id the pivot's foreign key will actually accept.
     *
     * Three of the four parents still do not exist (Module 5 closed only
     * `deals` here), so a random id is indistinguishable from a real one to
     * a constraint that cannot be written yet. Once a parent exists its key
     * is real, and a random id is now a foreign-key violation rather than an
     * unconstrained value — this inserts a genuine row instead of asserting
     * against the debt that just closed.
     */
    private static function parentId(string $table): string
    {
        if ($table === 'deal_files') {
            return self::insertDeal();
        }

        // Module 6, Point 1.1 added the parent key, so a fabricated id no
        // longer satisfies the pivot — which is the whole reason the key was
        // added; Module 10, Point 1.6 did the same for `purchase_order_files`.
        // `report_files` keeps the fabricated id until Module 13 creates its parent.
        if ($table === 'supplier_quotation_files') {
            return self::insertSupplierQuotation();
        }

        if ($table === 'purchase_order_files') {
            return self::insertPurchaseOrder();
        }

        // Module 9, Point 1.3: created with its parent key, so it never had a
        // fabricated-id phase.
        if ($table === 'quotation_files') {
            return self::insertQuotation();
        }

        return Uuid::uuid7()->toString();
    }

    private static function insertSupplierQuotation(): string
    {
        $supplierId = Uuid::uuid7()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Test Supplier for a File Pivot',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quotationId = Uuid::uuid7()->toString();

        DB::table('supplier_quotations')->insert([
            'id' => $quotationId,
            'code' => 'SQ-2026-'.substr(str_replace('-', '', $quotationId), -4),
            'supplier_id' => $supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $quotationId;
    }

    /** A purchase order on an `accepted` quotation — the chain its key now requires. */
    private static function insertPurchaseOrder(): string
    {
        $dealId = self::insertDeal();
        $customerId = DB::table('deals')->where('id', $dealId)->value('customer_id');
        $currencyId = Uuid::uuid7()->toString();
        $quotationId = Uuid::uuid7()->toString();
        $orderId = Uuid::uuid7()->toString();

        DB::table('currencies')->insert([
            'id' => $currencyId, 'code' => 'XPO', 'rounding_unit' => '1', 'rounding_enabled' => false,
            'is_base' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('quotations')->insert([
            'id' => $quotationId, 'code' => 'QT-2026-'.substr(str_replace('-', '', $quotationId), -4),
            'deal_id' => $dealId, 'customer_id' => $customerId, 'currency_id' => $currencyId,
            'status' => 'accepted', 'quotation_date' => '2026-09-23',
            'default_margin' => '0', 'discount_percent' => '0', 'rounding_unit' => '1', 'rounding_enabled' => false,
            'subtotal' => '10', 'additional_total' => '0', 'discount_amount' => '0', 'tax_base' => '10',
            'net_amount' => '10', 'total_before_round' => '10', 'final_total' => '10', 'rounding_diff' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_orders')->insert([
            'id' => $orderId, 'quotation_id' => $quotationId,
            'po_number' => 'PO-2026-'.substr(str_replace('-', '', $orderId), -4),
            'customer_po_reference' => '4500123987', 'po_date' => '2026-09-23',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    private static function insertDeal(): string
    {
        $customerId = Uuid::uuid7()->toString();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Test Customer for a File Pivot',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dealId = Uuid::uuid7()->toString();

        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $dealId), -4),
            'customer_id' => $customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dealId;
    }

    private static function insertQuotation(): string
    {
        $dealId = self::insertDeal();
        $currencyId = Uuid::uuid7()->toString();

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quotationId = Uuid::uuid7()->toString();

        DB::table('quotations')->insert([
            'id' => $quotationId,
            'code' => 'QT-2026-'.substr(str_replace('-', '', $quotationId), -4),
            'deal_id' => $dealId,
            'customer_id' => DB::table('deals')->where('id', $dealId)->value('customer_id'),
            'currency_id' => $currencyId,
            'default_margin' => '20',
            'discount_percent' => '0',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'subtotal' => '0',
            'additional_total' => '0',
            'discount_amount' => '0',
            'tax_base' => '0',
            'net_amount' => '0',
            'total_before_round' => '0',
            'final_total' => '0',
            'rounding_diff' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $quotationId;
    }

    /** @param  array<string, mixed>  $overrides */
    private static function insertFile(array $overrides = []): string
    {
        $id = Uuid::uuid7()->toString();

        DB::table('files')->insert(array_merge([
            'id' => $id,
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_path' => '2026/08/deals/'.Uuid::uuid7()->toString().'/'.$id.'.pdf',
            'scan_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private static function columnType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column],
        );

        self::assertIsObject($row, "{$table}.{$column} does not exist.");
        self::assertObjectHasProperty('data_type', $row);

        return (string) $row->data_type;   // @phpstan-ignore-line cast.string
    }

    /**
     * The first column of every index on a table.
     *
     * Only the leading column makes an index usable for a lookup on that column
     * alone, so this is the question worth asking. Membership is not: a
     * composite key mentions its second column without being able to search by
     * it.
     *
     * @return list<string>
     */
    private static function leadingIndexColumns(string $table): array
    {
        $rows = DB::select('select indexdef from pg_indexes where tablename = ?', [$table]);
        $leading = [];

        foreach ($rows as $row) {
            // DB::select gives back mixed rows; level 10 will not take the
            // property access on faith, and it is right not to.
            self::assertIsObject($row);
            self::assertObjectHasProperty('indexdef', $row);
            $definition = (string) $row->indexdef;   // @phpstan-ignore-line cast.string

            if (preg_match('/\(([^)]*)\)/', $definition, $matches) === 1) {
                // explode always returns at least one element, so a ?? here
                // would be dead code claiming something untrue about the data.
                $leading[] = trim(explode(',', $matches[1])[0]);
            }
        }

        return array_values(array_unique($leading));
    }

    private static function iniBytes(string $directive): int
    {
        $value = ini_get($directive);

        self::assertIsString($value, "{$directive} is not set at all.");

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
