<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-09 · 1.4 — `POST /suppliers/import` (`D-85`, proposed).
 *
 * The customers' import (`CustomerImportEndpointTest`) is the pattern; what
 * differs is `D-85`'s: `catalog.import` for the Manager alone, `color_rating`
 * never imported (`D-19`), an empty `type`/`phone`/`contact_person` flags the
 * row (`D-31`), and a row with no name or an unknown type is counted and not
 * saved (owner's ruling (a), 2026-09-21).
 */
final class SupplierImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/suppliers/import';

    private const PASSWORD = 'Passw0rd123';

    private const HEADER = 'name,type,phone,contact_person,has_open_account';

    private const COMPLETE = 'Alpha Supply,supplier,0100,Sara,yes';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ─────────────────────────────── D-85's `catalog.import`: the Manager alone

    public function test_that_an_unauthenticated_caller_cannot_import(): void
    {
        $this->postJson(self::ENDPOINT)->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
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

    /** `D-45` opens single edits to every operational role; `D-85` keeps the bulk import to the Manager. */
    #[DataProvider('rolesWithoutImport')]
    public function test_that_only_the_manager_may_import(RoleName $role): void
    {
        $this->post(self::ENDPOINT, ['file' => $this->csv(self::HEADER."\n".self::COMPLETE)], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('supplier_import_batches', 0);
    }

    // ─────────────────────────────── the rows

    /** `D-19`: colour is a manual rating, and §7.1's White is "new / not yet rated". */
    public function test_that_a_complete_row_imports_white_and_complete(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE)
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Alpha Supply',
            'type' => 'supplier',
            'phone' => '0100',
            'contact_person' => 'Sara',
            'has_open_account' => true,
            'color_rating' => 'white',
            'is_active' => true,
            'is_incomplete' => false,
            'created_by' => $this->userWith(RoleName::Manager)->id,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function rowsWithAGap(): array
    {
        return [
            'no type' => ['Gap Supply,,0100,Sara,no'],
            'no phone' => ['Gap Supply,supplier,,Sara,no'],
            'no contact person' => ['Gap Supply,distributor,0100,,no'],
            'shorter than the header' => ['Gap Supply,supplier'],
        ];
    }

    /** `D-31` / `D-85`: the row saves, flagged. */
    #[DataProvider('rowsWithAGap')]
    public function test_that_a_row_missing_type_phone_or_contact_is_flagged_and_saved(string $row): void
    {
        $this->import(self::HEADER."\n".$row)
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('suppliers', ['name' => 'Gap Supply', 'is_incomplete' => true]);
    }

    /** `D-85`: an empty `has_open_account` is read as no, not as a missing field. */
    public function test_that_an_empty_open_account_is_no_and_not_flagged(): void
    {
        $this->import(self::HEADER."\nCash Supply,supplier,0100,Sara,")
            ->assertStatus(201)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('suppliers', ['name' => 'Cash Supply', 'has_open_account' => false, 'is_incomplete' => false]);
    }

    /** @return array<string, array{string, bool}> */
    public static function openAccountWords(): array
    {
        return [
            'yes' => ['yes', true], 'YES' => ['YES', true], 'true' => ['true', true], 'one' => ['1', true],
            'no' => ['no', false], 'False' => ['False', false], 'zero' => ['0', false],
        ];
    }

    #[DataProvider('openAccountWords')]
    public function test_that_the_open_account_words_are_read(string $written, bool $expected): void
    {
        $this->import(self::HEADER."\nWord Supply,supplier,0100,Sara,{$written}")->assertStatus(201);

        $this->assertDatabaseHas('suppliers', ['name' => 'Word Supply', 'has_open_account' => $expected]);
    }

    /** `Supplier` is the same word as `supplier`; the closed set is §7.1's. */
    public function test_that_the_type_is_matched_whatever_its_case(): void
    {
        $this->import(self::HEADER."\nCase Supply,Distributor,0100,Sara,no")->assertStatus(201);

        $this->assertDatabaseHas('suppliers', ['name' => 'Case Supply', 'type' => 'distributor']);
    }

    /** @return array<string, array{string}> */
    public static function rowsThatCannotSave(): array
    {
        return [
            'no name' => [',supplier,0100,Sara,yes'],
            'a name of spaces' => ['   ,supplier,0100,Sara,yes'],
            'an unknown type (ruling a)' => ['Odd Supply,wholesaler,0100,Sara,yes'],
            'an unknown open-account word' => ['Odd Supply,supplier,0100,Sara,maybe'],
            'a phone longer than its column' => ['Odd Supply,supplier,'.str_repeat('1', 33).',Sara,yes'],
        ];
    }

    /** Counted in `row_count`, absent from `imported_count`, and nothing written for it. */
    #[DataProvider('rowsThatCannotSave')]
    public function test_that_a_row_that_cannot_save_is_counted_and_not_imported(string $row): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\n".$row)
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseCount('suppliers', 1);
    }

    /** `D-19`: a colour in the file is a column the importer does not accept, not a silent drop. */
    public function test_that_a_colour_column_is_refused(): void
    {
        $this->import("name,color_rating\nAlpha Supply,green")->assertStatus(422);

        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_that_a_file_without_a_name_column_is_refused_in_the_suppliers_words(): void
    {
        $this->import("phone\n0100")
            ->assertStatus(422)
            ->assertJsonFragment([__('suppliers.import.missing_name_column')]);
    }

    // ─────────────────────────────── the record of it

    /** `AUD-01`: an import is many creates, each audited like a hand-typed one. */
    public function test_that_every_imported_row_is_audited(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\nBeta Supply,,,,\n,supplier,,,")->assertStatus(201);

        self::assertSame(2, DB::table('audit_log')->where('event', 'SUPPLIER_CREATED')->count());
    }

    public function test_that_the_batch_is_recorded_with_its_counts_and_its_importer(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\nBeta Supply,,,,\n,supplier,,,", 'august.csv')
            ->assertStatus(201)
            ->assertJsonPath('data.original_filename', 'august.csv');

        $this->assertDatabaseHas('supplier_import_batches', [
            'original_filename' => 'august.csv',
            'row_count' => 3,
            'imported_count' => 2,
            'incomplete_count' => 1,
            'created_by' => $this->userWith(RoleName::Manager)->id,
        ]);
    }

    public function test_that_an_imported_supplier_is_readable_through_the_list(): void
    {
        $this->import(self::HEADER."\nBeta Supply,,,,")->assertStatus(201);

        $this->getJson('/api/v1/suppliers?filter[is_incomplete]=true', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Beta Supply')
            ->assertJsonPath('data.0.is_incomplete', true);
    }

    // ─────────────────────────────── the file

    public function test_that_a_request_with_no_file_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, [], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    /** F-10 · 1.2: the shared upload request keeps calling the field «الملف» / "file". */
    public function test_that_a_missing_file_is_named_in_both_languages(): void
    {
        foreach (['ar' => 'الملف', 'en' => 'file'] as $locale => $word) {
            $message = $this->postJson(self::ENDPOINT, [], $this->bearerFor(RoleName::Manager) + ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'file')
                ->json('error.details.0.message');

            self::assertIsString($message);
            self::assertStringContainsString($word, $message);
            self::assertStringNotContainsString('attributes', $message);
        }
    }

    /** §17 · `D-71`: the ceiling `config('files.max_size_bytes')` carries. */
    public function test_that_a_file_above_the_ceiling_is_refused(): void
    {
        config()->set('files.max_size_bytes', 1024);

        $this->import(self::HEADER."\n".str_repeat(self::COMPLETE."\n", 100))->assertStatus(422);

        $this->assertDatabaseCount('suppliers', 0);
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function import(string $contents, string $name = 'suppliers.csv'): TestResponse
    {
        return $this->post(self::ENDPOINT, ['file' => $this->csv($contents, $name)], $this->bearerFor(RoleName::Manager));
    }

    private function csv(string $contents, string $name = 'suppliers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
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
