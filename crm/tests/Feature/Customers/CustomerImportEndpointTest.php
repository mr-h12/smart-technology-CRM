<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

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
 * Module 3, Point 3.6 — `POST /customers/import`.
 *
 * ── CSV, not Excel, and no library ─────────────────────────────────────────
 *
 * The owner narrowed §3.3's `import (Excel)` row to CSV through `fgetcsv` on
 * 2026-08-29; the narrowing is recorded in `CHECKLIST.md` awaiting a `D-xx`,
 * and the permission keeps its documented name. The file must survive a UTF-8
 * BOM, `;` separators and CRLF — measured in this image (PHP 8.4.24), where
 * `fgetcsv` handles CRLF and quoted embedded newlines itself and **does not**
 * strip the BOM.
 *
 * ── `D-31`: incomplete data is accepted, not refused ───────────────────────
 *
 * A row that saves counts as imported even when it is flagged, which is why
 * `import_batches` carries `imported_count` and `incomplete_count` separately
 * and why outright failures are `row_count - imported_count` rather than a
 * fourth column.
 *
 * ⚠️ **What counts as "missing fields" is undocumented.** §4.2 marks only
 * `name` as required, so a literal reading would flag nothing at all and leave
 * `D-31`, its filter and §11's exclusion dead. This takes the conservative
 * reading — **any of §4.2's ten user-entered fields left empty flags the row**
 * — so the flag never claims a record is complete when it is not. The cost,
 * stated rather than hidden: in practice nearly every imported row is flagged.
 * Recorded in `CHECKLIST.md` awaiting a `D-xx`.
 *
 * ── A nameless row is a failure, not an incomplete one ─────────────────────
 *
 * `customers_name_not_blank` is a CHECK, so the row cannot save at all. It is
 * counted in `row_count` and absent from `imported_count`, which is exactly the
 * arithmetic `import_batches` documents.
 */
final class CustomerImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customers/import';

    private const PASSWORD = 'Passw0rd123';

    private const HEADER = 'name,sector,region,contact_person,phone,phone2,whatsapp,email,start_date,notes';

    /** Every one of §4.2's ten user-entered fields filled — the only shape that is not "incomplete". */
    private const COMPLETE = 'Alpha Trading,Medical,Cairo,Ahmed,0100,0101,0102,a@example.test,2026-01-01,ok';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    private function csv(string $contents, string $name = 'customers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    // ─────────────────────────────── §3.3's import row: the Manager alone

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

    /**
     * §3.3 gives `import (Excel)` to the Manager and a dash to every other role
     * — the Team Leader included, which is the one row where `All · Team` does
     * not apply. §3.12 rule 1: refused at the API.
     */
    #[DataProvider('rolesWithoutImport')]
    public function test_that_only_the_manager_may_import(RoleName $role): void
    {
        $this->post(self::ENDPOINT, ['file' => $this->csv(self::HEADER."\n".self::COMPLETE)], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    // ─────────────────────────────── the happy path

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function import(string $contents, string $name = 'customers.csv'): TestResponse
    {
        return $this->post(self::ENDPOINT, ['file' => $this->csv($contents, $name)], $this->bearerFor(RoleName::Manager));
    }

    public function test_that_a_manager_imports_two_rows(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\nBeta Trading,,,,,,,,,")
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.original_filename', 'customers.csv');

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'sector' => 'Medical']);
        $this->assertDatabaseHas('customers', ['name' => 'Beta Trading']);
    }

    public function test_that_a_utf8_bom_does_not_corrupt_the_first_column(): void
    {
        // Measured: fgetcsv does not strip it, so the header cell would be
        // "\xEF\xBB\xBFname" and the name column would go missing.
        $this->import("\xEF\xBB\xBF".self::HEADER."\n".self::COMPLETE)
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading']);
    }

    public function test_that_semicolons_and_crlf_are_accepted(): void
    {
        $header = str_replace(',', ';', self::HEADER);
        $row = str_replace(',', ';', self::COMPLETE);

        $this->import($header."\r\n".$row."\r\n")
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'region' => 'Cairo']);
    }

    public function test_that_arabic_survives_the_round_trip(): void
    {
        $this->import(self::HEADER."\n\"أحمد للتجارة\",Medical,,,,,,,,")
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['name' => 'أحمد للتجارة']);
    }

    public function test_that_a_quoted_field_may_contain_a_newline(): void
    {
        $this->import(self::HEADER."\nAlpha Trading,,,,,,,,,\"first\nsecond\"")
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'notes' => "first\nsecond"]);
    }

    public function test_that_a_header_only_file_imports_nothing_and_is_not_an_error(): void
    {
        $this->import(self::HEADER)
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 0)
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.incomplete_count', 0);
    }

    // ─────────────────────────────── `D-31`

    public function test_that_a_row_with_every_field_is_not_flagged(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE)
            ->assertStatus(201)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'is_incomplete' => false]);
    }

    /**
     * `D-87` ruling 1: complete means the five core fields, not §4.2's ten.
     * A row that fills them and leaves `email` (or any other field) empty is
     * not what `D-31`'s flag is for.
     */
    public function test_that_a_row_missing_only_a_non_core_field_is_not_flagged(): void
    {
        $this->import(self::HEADER."\nAlpha Trading,Medical,Cairo,Ahmed,0100,,,,,")
            ->assertStatus(201)
            ->assertJsonPath('data.incomplete_count', 0);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'is_incomplete' => false]);
    }

    public function test_that_a_row_missing_a_core_field_is_flagged(): void
    {
        $this->import(self::HEADER."\nAlpha Trading,Medical,,Ahmed,0100,0101,0102,a@example.test,2026-01-01,ok")
            ->assertStatus(201)
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'is_incomplete' => true]);
    }

    public function test_that_a_row_with_a_missing_field_is_flagged_and_still_saved(): void
    {
        $this->import(self::HEADER."\nBeta Trading,Medical,,,,,,,,")
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 1);

        // `D-31`: it saves. The flag is not a refusal.
        $this->assertDatabaseHas('customers', ['name' => 'Beta Trading', 'is_incomplete' => true]);
    }

    public function test_that_a_row_shorter_than_the_header_is_flagged_rather_than_refused(): void
    {
        $this->import(self::HEADER."\nGamma Trading,Medical")
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Gamma Trading', 'is_incomplete' => true]);
    }

    // ─────────────────────────────── failures are row_count − imported_count

    public function test_that_a_nameless_row_is_counted_and_not_imported(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\n,Medical,,,,,,,,")
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_that_a_blank_name_is_refused_the_way_the_check_constraint_would(): void
    {
        $this->import(self::HEADER."\n\"   \",Medical,,,,,,,,")
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 0);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_that_a_blank_line_is_not_a_row(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\n\n")
            ->assertStatus(201)
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.imported_count', 1);
    }

    // ─────────────────────────────── the batch, the owner, the audit

    public function test_that_the_batch_is_recorded_with_its_counts_and_its_importer(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\nBeta Trading,,,,,,,,,", 'august.csv')
            ->assertStatus(201);

        $this->assertDatabaseHas('import_batches', [
            'original_filename' => 'august.csv',
            'row_count' => 2,
            'imported_count' => 2,
            'incomplete_count' => 1,
            'created_by' => $this->userWith(RoleName::Manager)->id,
        ]);
    }

    /**
     * The importer is the actor (`DB-02`); nobody is made the sales owner. The
     * import format carries no owner column — §3.3 makes `assign` its own
     * permission and Point 3.5 its own route.
     */
    public function test_that_imported_customers_have_an_actor_but_no_owner(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE)->assertStatus(201);

        $this->assertDatabaseHas('customers', [
            'name' => 'Alpha Trading',
            'sales_owner_id' => null,
            'created_by' => $this->userWith(RoleName::Manager)->id,
            'customer_status' => 'prospect',
        ]);
    }

    /** `AUD-01` names create explicitly — an import is many creates, not one silent write. */
    public function test_that_every_imported_row_is_audited(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE."\nBeta Trading,,,,,,,,,\n,Medical,,,,,,,,")
            ->assertStatus(201);

        self::assertSame(2, DB::table('audit_log')->where('event', 'CUSTOMER_CREATED')->count());
    }

    public function test_that_an_imported_customer_is_readable_through_the_list(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE)->assertStatus(201);

        $this->getJson('/api/v1/customers', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Alpha Trading')
            ->assertJsonPath('data.0.is_incomplete', false);
    }

    // ─────────────────────────────── the boundary

    public function test_that_a_request_with_no_file_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
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

    public function test_that_an_unknown_column_is_refused_rather_than_ignored(): void
    {
        // `OpenAPI §6.2` takes this line about unknown query parameters, and
        // Point 3.3 took it about unknown body fields: refusing beats ignoring.
        $this->import(self::HEADER.",margin\n".self::COMPLETE.',30')
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'file');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_that_a_file_without_a_name_column_is_refused(): void
    {
        $this->import("sector,region\nMedical,Cairo")
            ->assertStatus(422);

        $this->assertDatabaseCount('customers', 0);
    }

    // ──────────────────── the header a spreadsheet actually writes (Point 3.7)

    /**
     * A header is matched by the *word*, not by its punctuation.
     *
     * §4.2 names the field `start_date`; a person exporting from a spreadsheet
     * writes `Start date`. Nothing in the sources says the file must repeat the
     * column identifier character for character, and a refusal over a space is
     * a refusal nobody can act on without being told the schema.
     */
    public function test_that_a_capitalised_spaced_header_is_read_as_the_field(): void
    {
        $this->import("Name,Start date\nAlpha Trading,2026-01-01")
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'start_date' => '2026-01-01']);
    }

    /** A hyphen is the other thing a spreadsheet puts between two words. */
    public function test_that_a_hyphenated_header_is_read_as_the_field(): void
    {
        $this->import("name,Contact-Person\nAlpha Trading,Ahmed")
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'contact_person' => 'Ahmed']);
    }

    /**
     * §4.2 describes `contact_person` as "Single contact (`D-18`)", so a column
     * headed `Contact` is that field under the document's own shorter word.
     */
    public function test_that_contact_is_read_as_the_contact_person(): void
    {
        $this->import("name,Contact\nAlpha Trading,Ahmed")
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'contact_person' => 'Ahmed']);
    }

    /**
     * `customers.attributes.phone2` is already the words **"second phone"** —
     * the application's own name for the field, shown in every validation
     * message. A file using it is not inventing a vocabulary.
     */
    public function test_that_second_phone_is_read_as_phone2(): void
    {
        $this->import("name,Second phone\nAlpha Trading,0101")
            ->assertStatus(201);

        $this->assertDatabaseHas('customers', ['name' => 'Alpha Trading', 'phone2' => '0101']);
    }

    /**
     * The regression: the exact header line of the file that was refused in the
     * running application on 2026-08-30, which is what this point exists for.
     */
    public function test_that_the_header_a_real_export_carried_is_accepted(): void
    {
        $this->import(
            "Name,Sector,Region,Contact,Phone,Second phone,WhatsApp,Email,Start date\n"
            .'Plan international,,,Abdalla.raslan,01069924445,01222588615,,a@example.test,'
        )
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1)
            // `sector` and `region` — two of `D-87`'s five core fields — are
            // empty, so `D-31` flags the row rather than refusing it.
            ->assertJsonPath('data.incomplete_count', 1);

        $this->assertDatabaseHas('customers', [
            'name' => 'Plan international',
            'contact_person' => 'Abdalla.raslan',
            'phone' => '01069924445',
            'phone2' => '01222588615',
        ]);
    }

    /**
     * Two headers that mean one field are refused, never merged.
     *
     * This is the failure the aliases make possible, so it is the one they owe
     * a guard for: whichever column won would be a silent choice between two
     * columns the person filled in on purpose.
     */
    public function test_that_two_headers_naming_one_field_are_refused(): void
    {
        $message = $this->import("name,contact,contact_person\nAlpha Trading,Ahmed,Mona")
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'file')
            ->json('error.details.0.message');

        // The **field** that was named twice, not the headers that named it —
        // which is also what makes this test discriminate. Before the aliases
        // existed this file was refused too, but for the wrong reason:
        // `contact` was simply an unknown column. Asserting the mapped field
        // name is what tells the two refusals apart.
        self::assertIsString($message);
        self::assertStringContainsString('contact_person', $message);

        $this->assertDatabaseCount('customers', 0);
    }

    /** The same collision without any alias involved — one guard covers both. */
    public function test_that_the_same_column_twice_is_refused(): void
    {
        $this->import("name,name\nAlpha Trading,Beta Trading")
            ->assertStatus(422);

        $this->assertDatabaseCount('customers', 0);
    }

    /**
     * An unknown column is still refused, and named **as the person wrote it**.
     *
     * Reporting the normalised form would answer a complaint about `Sales rep`
     * with the word `sales_rep`, which is the importer describing its own
     * internals to somebody looking for their own spreadsheet column.
     */
    public function test_that_an_unknown_column_is_named_as_the_person_wrote_it(): void
    {
        $message = $this->import("name,Sales rep\nAlpha Trading,Mona")
            ->assertStatus(422)
            ->json('error.details.0.message');

        self::assertIsString($message);
        self::assertStringContainsString('Sales rep', $message);
    }

    public function test_that_an_empty_file_is_refused(): void
    {
        $this->import('')->assertStatus(422);
    }

    /** §17 · `D-71`'s 30 MB, the ceiling `config('files.max_size_bytes')` already carries. */
    public function test_that_a_file_above_the_configured_ceiling_is_refused(): void
    {
        config()->set('files.max_size_bytes', 1024);

        $this->import(self::HEADER."\n".str_repeat(self::COMPLETE."\n", 100))
            ->assertStatus(422);

        $this->assertDatabaseCount('customers', 0);
    }

    /** Nothing is kept: `import_batches` has no path column, by Point 1.2's decision. */
    public function test_that_the_uploaded_file_is_not_stored(): void
    {
        $this->import(self::HEADER."\n".self::COMPLETE)->assertStatus(201);

        self::assertSame([], glob(storage_path('app/**/*.csv')) ?: []);
    }
}
