<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module 5 Point 4.1 — `POST /deals/{id}/documents`, §17's upload flow with
 * `AttachmentParent::Deal` as the parent.
 *
 * `UploadValidationTest`'s `pdf()` fixture is copied rather than shared: it is
 * a private helper on a Storage-module test, and this module reaching for it
 * would be the exact cross-module test coupling `CLAUDE.md`'s module
 * isolation exists to prevent, paid in a fixture instead of in `app/`.
 */
final class DealDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENTS_URL = '/api/v1/deals/%s/documents';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $root = config('filesystems.disks.secure_uploads.root');

        if (is_string($root)) {
            foreach (glob($root.'/*/*/deal/*/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    /** `UploadValidationTest::pdf()`'s exact shape — proven to pass finfo and the PdfEndMarker check. */
    private static function pdfBytes(): string
    {
        $head = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n";
        $tail = "%%EOF\n";

        return $head.$tail;
    }

    /**
     * A whole PDF that also carries the EICAR test signature — `EicarSignatureScanner`'s
     * one detectable pattern — so the "infected" path can be exercised for
     * real rather than mocked.
     *
     * Assembled from parts at run time, never written whole: the literal
     * string is designed to be detected, and a source file containing it can
     * trip a scanner reading this repository itself — `EicarSignatureScanner`'s
     * own docblock names the same risk for the same reason.
     */
    private static function pdfBytesWithEicarSignature(): string
    {
        $signature = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'.'-STANDARD-ANTIVIRUS-TEST-'.'FILE!$H+H*';
        $head = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n";
        $tail = "%%EOF\n";

        return $head.$signature."\n".$tail;
    }

    private function uploadedPdf(string $contents, string $name = 'offer.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    // --------------------------------------------------------------- actors

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

    // -------------------------------------------------------------- fixtures

    private function customer(): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedDeal(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /** @return TestResponse<\Illuminate\Http\Response> */
    private function upload(string $dealId, UploadedFile $file, RoleName $role): TestResponse
    {
        return $this->post(
            sprintf(self::DOCUMENTS_URL, $dealId),
            ['document' => $file],
            $this->bearerFor($role),
        );
    }

    // ------------------------------------------------------------- success

    public function test_that_an_uploaded_document_is_stored_and_reported(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $response = $this->upload($dealId, $this->uploadedPdf(self::pdfBytes()), RoleName::Manager);

        $response->assertStatus(201);
        $response->assertJsonPath('data.original_name', 'offer.pdf');
        $response->assertJsonPath('data.mime_type', 'application/pdf');
        $response->assertJsonPath('data.size_bytes', strlen(self::pdfBytes()));
        // eicar (the test-env scanner) reports everything but the EICAR
        // signature clean — asserted for itself in VirusScanningTest.
        $response->assertJsonPath('data.scan_status', 'clean');

        $fileId = $response->json('data.id');
        self::assertIsString($fileId);

        self::assertDatabaseHas('files', [
            'id' => $fileId,
            'original_name' => 'offer.pdf',
            'scan_status' => 'clean',
        ]);

        self::assertDatabaseHas('deal_files', [
            'deal_id' => $dealId,
            'file_id' => $fileId,
        ]);
    }

    public function test_that_the_upload_is_written_to_the_audit_log(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $this->upload($dealId, $this->uploadedPdf(self::pdfBytes()), RoleName::Manager)->assertStatus(201);

        self::assertSame(
            1,
            DB::table('audit_log')->where('event', 'DEAL_DOCUMENT_ATTACHED')->where('entity_id', $dealId)->count(),
        );
    }

    public function test_that_an_infected_file_still_uploads_and_is_reported_infected(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $response = $this->upload($dealId, $this->uploadedPdf(self::pdfBytesWithEicarSignature()), RoleName::Manager);

        $response->assertStatus(201);
        $response->assertJsonPath('data.scan_status', 'infected');
    }

    public function test_that_an_infected_upload_stays_undownloadable(): void
    {
        $dealId = $this->seedDeal($this->customer());
        $headers = $this->bearerFor(RoleName::Manager);

        $response = $this->post(
            sprintf(self::DOCUMENTS_URL, $dealId),
            ['document' => $this->uploadedPdf(self::pdfBytesWithEicarSignature())],
            $headers,
        )->assertStatus(201);

        $fileId = $response->json('data.id');
        self::assertIsString($fileId);

        // D-38, answered for real for the first time: the same actor who just
        // uploaded it cannot download it, because SEC-15 gates on scan_status
        // and not on who owns the deal.
        $this->get("/api/v1/files/{$fileId}/download", $headers)->assertNotFound();
    }

    public function test_that_a_clean_upload_is_downloadable_by_a_caller_in_reach(): void
    {
        $dealId = $this->seedDeal($this->customer());
        $headers = $this->bearerFor(RoleName::Manager);

        $bytes = self::pdfBytes();

        $response = $this->post(
            sprintf(self::DOCUMENTS_URL, $dealId),
            ['document' => $this->uploadedPdf($bytes)],
            $headers,
        )->assertStatus(201);

        $fileId = $response->json('data.id');
        self::assertIsString($fileId);

        // `DealAttachmentPermission` answering D-38 for real, exercised
        // end-to-end through the actual download endpoint rather than a stub.
        $download = $this->get("/api/v1/files/{$fileId}/download", $headers);

        $download->assertOk();
        self::assertSame($bytes, $download->streamedContent());
    }

    public function test_that_a_clean_upload_is_not_downloadable_by_a_caller_out_of_reach(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $response = $this->post(
            sprintf(self::DOCUMENTS_URL, $dealId),
            ['document' => $this->uploadedPdf(self::pdfBytes())],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(201);

        $fileId = $response->json('data.id');
        self::assertIsString($fileId);

        // Indoor Sales holds `own` on `deal.view` (§3.4) and did not own this
        // deal — out of reach, the same 404 `DealAttachmentPermission` gives
        // `AttachDealDocument` itself for an out-of-reach upload.
        $this->get("/api/v1/files/{$fileId}/download", $this->bearerFor(RoleName::IndoorSales))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- refusal

    public function test_that_uploading_to_an_unknown_deal_is_not_found(): void
    {
        $this->upload((string) Str::uuid7(), $this->uploadedPdf(self::pdfBytes()), RoleName::Manager)
            ->assertNotFound();
    }

    public function test_that_uploading_outside_the_callers_reach_is_not_found(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $dealId = $this->seedDeal($this->customer(), ['owner_id' => $manager->id]);

        // Indoor Sales holds `own` (§3.4) and does not own this deal.
        $this->upload($dealId, $this->uploadedPdf(self::pdfBytes()), RoleName::IndoorSales)
            ->assertNotFound();

        self::assertSame(0, DB::table('deal_files')->where('deal_id', $dealId)->count());
    }

    public function test_that_an_oversized_file_is_refused(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $ceiling = config('files.max_size_bytes');
        self::assertIsInt($ceiling);

        // One byte over — content does not matter, since §17's size check
        // runs before the type is ever inspected.
        $oversized = $this->uploadedPdf(str_repeat('a', $ceiling + 1), 'big.pdf');

        $response = $this->upload($dealId, $oversized, RoleName::Manager);

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.0.code', 'too_large');
        $response->assertJsonPath('error.details.0.field', 'document');
    }

    public function test_that_an_unsupported_type_is_refused(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $file = $this->uploadedPdf("just plain text, not one of D-40's six types\n", 'notes.txt');

        $response = $this->upload($dealId, $file, RoleName::Manager);

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.0.code', 'unsupported_type');
    }

    public function test_that_a_missing_file_is_a_validation_failure(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $response = $this->post(sprintf(self::DOCUMENTS_URL, $dealId), [], $this->bearerFor(RoleName::Manager));

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.0.field', 'document');
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $dealId = $this->seedDeal($this->customer());

        $this->post(sprintf(self::DOCUMENTS_URL, $dealId), ['document' => $this->uploadedPdf(self::pdfBytes())])
            ->assertUnauthorized();
    }
}
