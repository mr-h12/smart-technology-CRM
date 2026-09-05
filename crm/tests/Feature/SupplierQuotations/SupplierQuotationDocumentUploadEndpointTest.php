<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 6, Point 5.3 — `POST /api/v1/supplier-quotations/{id}/documents`.
 *
 * ── The third grant, and why it is not `view` or `create` ──────────────────
 *
 * §3.6 fills three columns, not two. `upload_attachment` is seeded in
 * `PermissionMatrix` beside `view` and `create / edit`, with five ✅ — Manager,
 * Team Leader, Outdoor Sales, Indoor Sales, Procurement — and **no cell for the
 * CEO**, who holds `view` as `All` and nothing else. So the CEO can read an
 * offer (Point 2.3) and cannot attach a file to it, and that 403 is the case
 * that proves this route carries its own grant rather than borrowing `view`'s.
 *
 * ── No scope argument, on this module's standing reading ───────────────────
 *
 * §3.6 is "a shared screen — not restricted by ownership": every grant it makes
 * is `Scope::All`, so there is no `heldScopes` to pass and
 * `AttachSupplierQuotationDocument::handle()` takes four parameters where
 * `AttachDealDocument` takes five. `SupplierQuotationController`'s own docblock
 * records the same reading for the four routes that came before this one.
 *
 * ── What is deliberately not tested here ──────────────────────────────────
 *
 * §17's rules themselves belong to `FinfoUploadValidator` and are owned by
 * `UploadValidationTest`'s 26 cases. Point 5.3 asserts that the route exists
 * and is gated by the right grant; **Point 5.4 asserts that this endpoint is
 * actually wired to those rules and that the file it stores can be got back
 * out** — the module's acceptance criterion, which is about this parent's
 * journey and not about the validator's logic a second time.
 */
final class SupplierQuotationDocumentUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supplier-quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    /** @var list<string> */
    private array $scratch = [];

    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplierId = (string) Str::uuid7();

        DB::table('suppliers')->insert([
            'id' => $this->supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $root = config('filesystems.disks.secure_uploads.root');

        if (is_string($root)) {
            foreach (glob($root.'/*/*/supplier_quotation/*/*') ?: [] as $stored) {
                if (is_file($stored)) {
                    unlink($stored);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * §3.6's `upload_attachment` row — five ✅, and two dashes tested below.
     *
     * @return array<string, array{RoleName}>
     */
    public static function uploaders(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
        ];
    }

    // ────────────────────────────────────────────────────────── authorisation

    /** The only request this method makes — trap: the test client stays authenticated. */
    public function test_that_an_unauthenticated_caller_cannot_upload(): void
    {
        $this->post(self::ENDPOINT.'/'.Str::uuid7().'/documents', ['document' => $this->pdfUpload()])
            ->assertStatus(401);
    }

    #[DataProvider('uploaders')]
    public function test_that_every_role_section_3_6_grants_upload_to_can_upload(RoleName $role): void
    {
        $this->upload($this->created(), $role)->assertStatus(201);
    }

    /**
     * §3.6 gives the CEO `view` as `All` and no cell at all under
     * `upload_attachment`. This is the assertion that separates the third grant
     * from the first: were the route to carry `supplier_quotation.view`, the
     * CEO would be served and this would go green for the wrong reason.
     */
    public function test_that_the_read_only_ceo_cannot_upload(): void
    {
        $this->upload($this->created(), RoleName::Ceo)->assertStatus(403);
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column. */
    public function test_that_the_outdoor_supervisor_cannot_upload(): void
    {
        $this->upload($this->created(), RoleName::OutdoorSupervisor)->assertStatus(403);
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        $id = $this->created();

        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'upload_attachment')
            ->delete();

        $this->upload($id, RoleName::Manager)->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────── the 201

    /** §17's fields as `DealDocumentPayload` already reports them for the first parent. */
    public function test_that_the_201_reports_section_17_fields(): void
    {
        $bytes = self::pdf();

        $response = $this->upload($this->created(), RoleName::Manager, $this->pdfUpload($bytes, 'offer.pdf'));

        $response->assertStatus(201)
            ->assertJsonPath('data.original_name', 'offer.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size_bytes', strlen($bytes))
            // eicar (the test-env scanner) calls everything but the EICAR
            // signature clean — asserted for itself in VirusScanningTest.
            ->assertJsonPath('data.scan_status', 'clean');

        $created = $response->json('data.created_at');
        self::assertIsString($created);
        self::assertNotFalse(strtotime($created));
    }

    /** §17 keeps the storage layout unreachable from outside Storage. */
    public function test_that_the_201_does_not_leak_the_storage_path(): void
    {
        $this->upload($this->created(), RoleName::Manager)
            ->assertStatus(201)
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.path');
    }

    /** `D-71`: the `files` row and this parent's pivot row are both written. */
    public function test_that_the_file_row_and_the_pivot_row_are_written(): void
    {
        $id = $this->created();

        $fileId = $this->upload($id, RoleName::Manager)->assertStatus(201)->json('data.id');
        self::assertIsString($fileId);

        self::assertDatabaseHas('files', ['id' => $fileId, 'original_name' => 'offer.pdf']);
        self::assertDatabaseHas('supplier_quotation_files', [
            'supplier_quotation_id' => $id,
            'file_id' => $fileId,
        ]);
    }

    // ─────────────────────────────────────────────────────────── the refusals

    public function test_that_a_missing_file_is_a_validation_failure(): void
    {
        $response = $this->post(
            self::ENDPOINT.'/'.$this->created().'/documents',
            [],
            $this->bearerFor(RoleName::Manager),
        );

        $response->assertStatus(422)->assertJsonPath('error.details.0.field', 'document');
    }

    public function test_that_uploading_to_an_unknown_offer_is_not_found(): void
    {
        $this->upload((string) Str::uuid7(), RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `DB-01` and `OpenAPI §5.1` — the two cases answer identically. */
    public function test_that_uploading_to_a_soft_deleted_offer_is_not_found(): void
    {
        $id = $this->created();

        DB::table('supplier_quotations')->where('id', $id)->update(['deleted_at' => now()]);

        $this->upload($id, RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertSame(0, DB::table('supplier_quotation_files')->where('supplier_quotation_id', $id)->count());
    }

    // ──────────────────────────────────── Point 5.4 — §17 at this endpoint

    /**
     * The criterion's **"true MIME"**, and the only case that can tell the
     * difference between a validator that reads bytes and one that reads names.
     * A real ELF header called `offer.pdf` is refused, and `D-40` never sees it
     * as a PDF.
     */
    public function test_that_an_executable_wearing_a_pdf_name_is_refused(): void
    {
        $id = $this->created();

        $response = $this->upload($id, RoleName::Manager, $this->pdfUpload(self::executable(), 'offer.pdf'));

        $response->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'unsupported_type')
            ->assertJsonPath('error.details.0.field', 'document');

        self::assertSame(0, DB::table('supplier_quotation_files')->where('supplier_quotation_id', $id)->count());
        self::assertSame(0, DB::table('files')->count());
    }

    /** The criterion's **"type"** — plain text is none of `D-40`'s six. */
    public function test_that_an_unsupported_type_is_refused(): void
    {
        $response = $this->upload(
            $this->created(),
            RoleName::Manager,
            $this->pdfUpload("just plain text, not one of D-40's six types\n", 'notes.txt'),
        );

        $response->assertStatus(422)->assertJsonPath('error.details.0.code', 'unsupported_type');
    }

    /**
     * The criterion's **"size"**, one byte over. The ceiling is read from
     * configuration rather than written here, so this test cannot disagree with
     * the validator the way a hard-coded number would.
     */
    public function test_that_a_file_over_the_configured_ceiling_is_refused(): void
    {
        $ceiling = config('files.max_size_bytes');
        self::assertIsInt($ceiling);

        $response = $this->upload(
            $this->created(),
            RoleName::Manager,
            $this->pdfUpload(str_repeat('a', $ceiling + 1), 'big.pdf'),
        );

        $response->assertStatus(422)->assertJsonPath('error.details.0.code', 'too_large');
    }

    /**
     * The criterion's **"stored under a UUID name"**. §17 puts the caller's
     * filename in the database as a label and never on disk, so the stored
     * basename is a UUID plus the type's extension — asserted against the
     * original name explicitly, because a path that merely *contains* a UUID
     * would pass a laxer check while still leaking `offer.pdf`.
     */
    public function test_that_the_stored_file_is_named_with_a_uuid_and_not_the_callers_name(): void
    {
        $fileId = $this->upload($this->created(), RoleName::Manager, $this->pdfUpload(null, 'supplier offer.pdf'))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($fileId);

        $stored = DB::table('files')->where('id', $fileId)->value('storage_path');
        self::assertIsString($stored);

        $basename = basename($stored);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/',
            $basename,
            'the stored basename is not a UUIDv7 plus the type extension',
        );
        self::assertStringNotContainsString('supplier offer', $stored);
        // §17's own layout, so the UUID is not the only thing keeping two
        // parents' files apart.
        self::assertStringContainsString('/supplier_quotation/', $stored);
    }

    /**
     * The journey, and the case `D-38` exists for: the **CEO**, who §3.6 gives
     * `view` as `All` and no `upload_attachment` at all, downloads a file they
     * could never have uploaded. `SupplierQuotationAttachmentPermission` gates
     * on `view` precisely so this works.
     */
    public function test_that_a_clean_upload_is_downloadable_by_a_reader_who_may_not_upload(): void
    {
        $bytes = self::pdf();

        $fileId = $this->upload($this->created(), RoleName::Manager, $this->pdfUpload($bytes))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($fileId);

        $download = $this->get("/api/v1/files/{$fileId}/download", $this->bearerFor(RoleName::Ceo));

        $download->assertOk();
        self::assertSame($bytes, $download->streamedContent());
    }

    /** §3.6 gives the Outdoor Supervisor no `view`, and `OpenAPI §5.1` makes the refusal a 404. */
    public function test_that_a_caller_without_view_cannot_download(): void
    {
        $fileId = $this->upload($this->created(), RoleName::Manager)->assertStatus(201)->json('data.id');
        self::assertIsString($fileId);

        $this->get("/api/v1/files/{$fileId}/download", $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertNotFound();
    }

    /**
     * The other end of the journey. `SEC-15` gates the download on `scan_status`
     * and not on who owns the offer, so the caller who uploaded the infected
     * file cannot get it back either — the same actor, the same bearer token.
     */
    public function test_that_an_infected_upload_is_not_downloadable_even_by_its_uploader(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $response = $this->post(
            self::ENDPOINT.'/'.$this->created().'/documents',
            ['document' => $this->pdfUpload(self::pdfBytesWithEicarSignature())],
            $headers,
        );

        $response->assertStatus(201)->assertJsonPath('data.scan_status', 'infected');

        $fileId = $response->json('data.id');
        self::assertIsString($fileId);

        $this->get("/api/v1/files/{$fileId}/download", $headers)->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────── helpers

    /** @return TestResponse<\Illuminate\Http\Response> */
    private function upload(string $offerId, RoleName $role, ?UploadedFile $file = null): TestResponse
    {
        return $this->post(
            self::ENDPOINT.'/'.$offerId.'/documents',
            ['document' => $file ?? $this->pdfUpload()],
            $this->bearerFor($role),
        );
    }

    /**
     * §7.2's minimum offer: only `supplier_id` is required — `total_price` and
     * `currency_id` are a nullable pair and `items` is optional — so nothing is
     * seeded that this point does not need.
     */
    private function created(): string
    {
        $id = $this->postJson(self::ENDPOINT, ['supplier_id' => $this->supplierId], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        return $id;
    }

    /** A whole, if minimal, PDF — `AttachSupplierQuotationDocumentTest::pdf()`'s bytes. */
    private static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
    }

    /**
     * A whole PDF that also carries the EICAR test signature — `EicarSignatureScanner`'s
     * one detectable pattern. Assembled from parts at run time and never written
     * whole, because a source file holding that literal can trip a scanner
     * reading this repository itself.
     */
    private static function pdfBytesWithEicarSignature(): string
    {
        $signature = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'.'-STANDARD-ANTIVIRUS-TEST-'.'FILE!$H+H*';

        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$signature."\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** A real ELF header — not one of `D-40`'s six types, whatever it is named. */
    private static function executable(): string
    {
        return "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 8).str_repeat("\x00", 200);
    }

    private function pdfUpload(?string $contents = null, string $name = 'offer.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-sq-endpoint-');
        self::assertIsString($path);
        file_put_contents($path, $contents ?? self::pdf());
        $this->scratch[] = $path;

        return new UploadedFile($path, $name, 'application/pdf', null, true);
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
