<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * F-10 · 1.5 — `POST /catalog-items/import` (`D-86`, rulings 2–6; owner's
 * answers of 2026-09-22: the URL, `CATALOG_ITEM_SUPPLIER_LINKED`, the
 * `is_active` words, a list value stored as its code with the code winning).
 */
final class CatalogImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/catalog-items/import';

    private const PASSWORD = 'Passw0rd123';

    private const HEADER = 'kind,product_code,name,category,unit,service_type,description,company,notes,is_active,supplier';

    /** Every field present, no supplier. */
    private const PRODUCT = 'product,P-1,Cable,Wiring,pcs,,Copper,alpha,,,';

    private const SERVICE = 'service,,Fitting,,,install,,alpha,,,';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->listEntry('units', 'pcs', 'Piece', 'قطعة');
        $this->listEntry('service_types', 'install', 'Installation', 'تركيب');
        $this->listEntry('companies', 'alpha', 'Alpha Co', 'شركة ألفا');
    }

    // ── who may import ──────────────────────────────────────────────────────

    public function test_an_unauthenticated_caller_cannot_import(): void
    {
        $this->postJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * Every seeded role but the Manager — `catalog.manage`'s holders among them,
     * the grant a copy-paste in `PermissionMatrix` would widen this one to.
     *
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutImport(): array
    {
        return [
            'team leader' => [RoleName::TeamLeader],
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
            'the CEO' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('rolesWithoutImport')]
    public function test_a_role_without_catalog_import_is_refused(RoleName $role): void
    {
        $this->post(self::ENDPOINT, ['file' => $this->csv(self::HEADER."\n".self::PRODUCT)], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseCount('catalog_items', 0);
        $this->assertDatabaseCount('catalog_import_batches', 0);
    }

    // ── saved complete ──────────────────────────────────────────────────────

    public function test_a_complete_product_and_service_are_saved_unflagged(): void
    {
        $this->import(self::HEADER."\n".self::PRODUCT."\n".self::SERVICE)
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('catalog_items', [
            'kind' => 'product', 'product_code' => 'P-1', 'name' => 'Cable', 'category' => 'Wiring',
            'unit' => 'pcs', 'description' => 'Copper', 'company' => 'alpha', 'is_active' => true,
            'is_incomplete' => false, 'created_by' => $this->userWith(RoleName::Manager)->id,
        ]);
        $this->assertDatabaseHas('catalog_items', ['kind' => 'service', 'name' => 'Fitting', 'service_type' => 'install', 'is_incomplete' => false]);
    }

    public function test_every_row_creates_a_new_item(): void
    {
        $this->import(self::HEADER."\n".self::PRODUCT."\n".self::PRODUCT)->assertJsonPath('data.imported_count', 2);

        self::assertSame(2, DB::table('catalog_items')->where('name', 'Cable')->count());
    }

    // ── rejected and counted ────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function rowsThatAreRejected(): array
    {
        return [
            'a missing kind' => [',P-1,Cable,,pcs,,,alpha,,,'],
            'an unknown kind' => ['bundle,P-1,Cable,,pcs,,,alpha,,,'],
            'a product without a name' => ['product,P-1,,,pcs,,,alpha,,,'],
            'an off-list unit' => ['product,P-1,Cable,,metre,,,alpha,,,'],
            'an off-list service type' => ['service,,Fitting,,,painting,,alpha,,,'],
            'an off-list company' => ['product,P-1,Cable,,pcs,,,Globex,,,'],
            'a name longer than its column' => ['product,P-1,'.str_repeat('n', 256).',,pcs,,,alpha,,,'],
            'a product code longer than its column' => ['product,'.str_repeat('c', 65).',Cable,,pcs,,,alpha,,,'],
            'a category longer than its column' => ['product,P-1,Cable,'.str_repeat('c', 129).',pcs,,,alpha,,,'],
            'an unknown is_active word' => ['product,P-1,Cable,,pcs,,,alpha,,maybe,'],
            'a supplier matching none' => ['product,P-1,Cable,,pcs,,,alpha,,,Nobody'],
            'a supplier matching two' => ['product,P-1,Cable,,pcs,,,alpha,,,Twin'],
        ];
    }

    #[DataProvider('rowsThatAreRejected')]
    public function test_a_rejected_row_is_counted_and_not_saved(string $row): void
    {
        $this->supplier('Twin');
        $this->supplier('twin ');

        $this->import(self::HEADER."\n".$row."\n".self::SERVICE)
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseCount('catalog_items', 1);
        $this->assertDatabaseCount('catalog_item_suppliers', 0);
    }

    // ── saved and flagged ───────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function rowsThatAreFlagged(): array
    {
        return [
            'a product without a unit' => ['product,P-1,Cable,,,,,alpha,,,'],
            'a service without a service type' => ['service,,Fitting,,,,,alpha,,,'],
            'a product without a company' => ['product,P-1,Cable,,pcs,,,,,,'],
            'a service without a company' => ['service,,Fitting,,,install,,,,,'],
        ];
    }

    #[DataProvider('rowsThatAreFlagged')]
    public function test_a_row_missing_what_the_form_requires_is_saved_and_flagged(string $row): void
    {
        $this->import(self::HEADER."\n".$row)
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('catalog_items', ['is_incomplete' => true]);
    }

    public function test_a_service_without_a_name_is_saved(): void
    {
        $this->import(self::HEADER."\nservice,,,,,install,,alpha,,,")
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('catalog_items', ['kind' => 'service', 'name' => null]);
    }

    // ── matching ────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function spellingsOfTheUnit(): array
    {
        return [
            'the code' => ['pcs'],
            'the code in capitals' => ['PCS'],
            'the English label, spaced and cased' => ['  pIECE '],
            'the Arabic label' => ['قطعة'],
        ];
    }

    #[DataProvider('spellingsOfTheUnit')]
    public function test_a_list_value_is_stored_as_its_code(string $unit): void
    {
        $this->import(self::HEADER."\nproduct,P-1,Cable,,\"{$unit}\",,,Alpha Co,,,")
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('catalog_items', ['name' => 'Cable', 'unit' => 'pcs', 'company' => 'alpha']);
    }

    /** Owner, 2026-09-22: a value that is one entry's code and another's label means the code. */
    public function test_the_code_wins_over_another_entrys_label(): void
    {
        $this->listEntry('units', 'box', 'Carton', 'كرتون');
        $this->listEntry('units', 'carton', 'Box', 'علبة');

        $this->import(self::HEADER."\nproduct,P-1,Cable,,box,,,alpha,,,")->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('catalog_items', ['name' => 'Cable', 'unit' => 'box']);
    }

    public function test_no_managed_list_row_is_added(): void
    {
        $before = DB::table('enum_lists')->count();

        $this->import(self::HEADER."\nproduct,P-1,Cable,,metre,,,Globex,,,\n".self::PRODUCT)->assertStatus(201);

        self::assertSame($before, DB::table('enum_lists')->count());
    }

    /** @return array<string, array{string, bool}> */
    public static function isActiveWords(): array
    {
        return [
            'empty' => ['', true], 'yes' => ['yes', true], 'TRUE' => ['TRUE', true], 'one' => ['1', true],
            'no' => ['no', false], 'False' => ['False', false], 'zero' => ['0', false],
        ];
    }

    #[DataProvider('isActiveWords')]
    public function test_the_is_active_words_are_read(string $written, bool $expected): void
    {
        $this->import(self::HEADER."\nproduct,P-1,Cable,,pcs,,,alpha,,{$written},")->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('catalog_items', ['name' => 'Cable', 'is_active' => $expected]);
    }

    // ── the link ────────────────────────────────────────────────────────────

    public function test_a_linked_row_writes_a_link_and_its_audit_row(): void
    {
        $supplierId = $this->supplier('Acme Trading', ['is_active' => false]);

        $this->import(self::HEADER."\nproduct,P-1,Cable,,pcs,,,alpha,,,  acme TRADING ")->assertJsonPath('data.imported_count', 1);

        $itemId = DB::table('catalog_items')->where('name', 'Cable')->value('id');
        $link = DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->where('supplier_id', $supplierId)->first();
        self::assertNotNull($link);
        self::assertSame($this->userWith(RoleName::Manager)->id, $link->created_by);

        $audit = DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_LINKED')->first();
        self::assertNotNull($audit);
        self::assertSame('catalog_item_supplier', $audit->entity_type);
        self::assertSame($link->id, $audit->entity_id);
        self::assertIsString($audit->new_values);
        // assertEquals, not assertSame: jsonb stores its keys in its own order.
        self::assertEquals(['catalog_item_id' => $itemId, 'supplier_id' => $supplierId], json_decode($audit->new_values, true));
    }

    public function test_an_unlinked_row_writes_no_link(): void
    {
        $this->import(self::HEADER."\n".self::PRODUCT)->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseCount('catalog_item_suppliers', 0);
        self::assertSame(0, DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_LINKED')->count());
    }

    // ── audit, batch, atomicity ─────────────────────────────────────────────

    public function test_every_saved_item_is_audited(): void
    {
        $this->import(self::HEADER."\n".self::PRODUCT."\n".self::SERVICE."\nbundle,,X,,,,,alpha,,,");

        self::assertSame(2, DB::table('audit_log')->where('event', 'CATALOG_ITEM_CREATED')->where('entity_type', 'catalog_item')->count());
    }

    public function test_the_batch_is_recorded_with_its_counts_and_its_importer(): void
    {
        $response = $this->import(self::HEADER."\n".self::PRODUCT."\nproduct,P-2,Gap,,,,,alpha,,,\nbundle,,X,,,,,alpha,,,", 'items.csv')
            ->assertStatus(201)
            ->assertJsonPath('data.original_filename', 'items.csv')
            ->assertJsonPath('data.row_count', 3)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('catalog_import_batches', [
            'id' => $response->json('data.id'),
            'original_filename' => 'items.csv',
            'row_count' => 3,
            'imported_count' => 2,
            'incomplete_count' => 1,
            'created_by' => $this->userWith(RoleName::Manager)->id,
        ]);
    }

    public function test_a_failure_part_way_writes_nothing(): void
    {
        $this->app->instance(SupplierLookupInterface::class, new class implements SupplierLookupInterface
        {
            public function idsNamed(string $name): array
            {
                throw new RuntimeException('lookup down');
            }

            public function namesFor(array $ids): array
            {
                return [];
            }
        });

        $this->import(self::HEADER."\n".self::PRODUCT."\nproduct,P-2,Linked,,pcs,,,alpha,,,Acme")->assertStatus(500);

        $this->assertDatabaseCount('catalog_items', 0);
        $this->assertDatabaseCount('catalog_item_suppliers', 0);
        $this->assertDatabaseCount('catalog_import_batches', 0);
        self::assertSame(0, DB::table('audit_log')->where('event', 'CATALOG_ITEM_CREATED')->count());
    }

    public function test_a_file_without_a_name_column_is_refused_in_the_catalogs_words(): void
    {
        $this->import("kind,unit\nproduct,pcs")
            ->assertStatus(422)
            ->assertJsonFragment([__('catalog.import.missing_name_column')]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function import(string $contents, string $name = 'items.csv'): TestResponse
    {
        return $this->post(self::ENDPOINT, ['file' => $this->csv($contents, $name)], $this->bearerFor(RoleName::Manager));
    }

    private function csv(string $contents, string $name = 'items.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function listEntry(string $list, string $code, string $en, string $ar): void
    {
        DB::table('enum_lists')->insert([
            'id' => (string) Str::uuid7(),
            'list' => $list,
            'code' => $code,
            'label_en' => $en,
            'label_ar' => $ar,
            'position' => DB::table('enum_lists')->where('list', $list)->count(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $columns */
    private function supplier(string $name, array $columns = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('suppliers')->insert($columns + ['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
