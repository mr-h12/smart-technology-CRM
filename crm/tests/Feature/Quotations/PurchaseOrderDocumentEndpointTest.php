<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 10 · 2.3 — `POST /purchase-orders/{id}/documents` (`OpenAPI §7.1`)
 * under `quotation.record_customer_response` (Q10, `D-38`), the order's files
 * on its detail as `documents` (owner, 2.3 A1 — replacing `has_attachment`),
 * several per order (B1), and the download through `GET /files/{id}/download`
 * for whoever may read the order (`quotation.view`, scoped through the deal).
 * §17's upload flow in `AttachDealDocument`'s shape: reach first, then
 * validate, store, and scan after commit.
 */
final class PurchaseOrderDocumentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/purchase-orders';

    private const QUOTATIONS = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    private const DOCUMENT_FIELDS = ['id', 'original_name', 'mime_type', 'size_bytes', 'scan_status', 'created_at'];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, string> one login per role per test — the login limit counts every call */
    private array $tokens = [];

    /** @var list<string> */
    private array $scratch = [];

    private string $customerId;

    private string $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency('EGP');
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine();
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
            foreach (glob($root.'/*/*/purchase_order/*/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────── success

    public function test_the_recorder_attaches_a_file_to_a_purchase_order_and_it_is_listed_and_downloadable(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $response = $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes(), 'po.pdf'))
            ->assertStatus(201)
            ->assertJsonPath('data.original_name', 'po.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size_bytes', strlen(self::pdfBytes()))
            ->assertJsonPath('data.scan_status', 'clean');
        $document = $response->json('data');
        self::assertIsArray($document);
        self::assertEqualsCanonicalizing(self::DOCUMENT_FIELDS, array_keys($document));
        self::assertIsString($document['id']);

        self::assertDatabaseHas('purchase_order_files', ['purchase_order_id' => $order['id'], 'file_id' => $document['id']]);

        // A1: the order's detail lists it, with the upload answer's fields.
        $documents = $this->show($order['id'])->assertStatus(200)->assertJsonMissingPath('data.has_attachment')->json('data.documents');
        self::assertSame([$document], $documents);

        $download = $this->get('/api/v1/files/'.$document['id'].'/download', $this->bearerFor(RoleName::Manager));
        $download->assertOk();
        self::assertSame(self::pdfBytes(), $download->streamedContent());
    }

    /** B1: several files per order, as a deal and an offer take. */
    public function test_several_files_may_be_attached_to_one_order(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes(), 'first.pdf'))->assertStatus(201);
        $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes(), 'second.pdf'))->assertStatus(201);

        $names = $this->show($order['id'])->assertStatus(200)->json('data.documents.*.original_name');
        self::assertIsArray($names);
        self::assertEqualsCanonicalizing(['first.pdf', 'second.pdf'], $names);
    }

    public function test_an_order_without_files_lists_none(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $data = $this->show($order['id'])->assertStatus(200)->json('data');
        self::assertIsArray($data);
        self::assertArrayHasKey('documents', $data);
        self::assertSame([], $data['documents']);
    }

    public function test_the_upload_is_written_to_the_audit_log(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $fileId = $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes(), 'po.pdf'))->assertStatus(201)->json('data.id');

        $row = DB::table('audit_log')->where('event', 'PURCHASE_ORDER_DOCUMENT_ATTACHED')->where('entity_type', 'purchase_order')->where('entity_id', $order['id'])->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        self::assertSame(['file_id' => $fileId, 'original_name' => 'po.pdf'], json_decode($row->new_values, true));
        self::assertSame($this->userWith(RoleName::Manager)->id, $row->user_id);
    }

    // ──────────────────────────────────────────────────────────────── reach

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['Outdoor Sales' => [RoleName::OutdoorSales], 'Indoor Sales' => [RoleName::IndoorSales]];
    }

    /** §3.5 `record_customer_response` · Own, through the quotation's deal. */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_attaches_to_its_own_deals_order(RoleName $role): void
    {
        $order = $this->accepted($this->deal($this->userWith($role)->id), 'REF-1');

        $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes()), $role)->assertStatus(201);
    }

    /**
     * `AttachDealDocument`'s order: reach is decided before the file is read,
     * so a text file — a 422 if it were inspected — answers 404 here, and
     * nothing is written (`OpenAPI §5.1`: no case revealed).
     *
     * @return array<string, array{string, RoleName}>
     */
    public static function unreachable(): array
    {
        return [
            'another owner\'s deal (Own)' => ['theirs', RoleName::IndoorSales],
            'the Team Leader (Team is unbacked, D-a)' => ['theirs', RoleName::TeamLeader],
            'an unknown id' => ['unknown', RoleName::Manager],
            'a malformed id' => ['malformed', RoleName::Manager],
            'a deleted order' => ['deleted', RoleName::Manager],
        ];
    }

    #[DataProvider('unreachable')]
    public function test_an_order_out_of_reach_is_a_404_before_the_file_is_read(string $case, RoleName $role): void
    {
        $order = $this->accepted($this->deal($this->userWith(RoleName::OutdoorSales)->id), 'REF-1');
        if ($case === 'deleted') {
            DB::table('purchase_orders')->where('id', $order['id'])->update(['deleted_at' => now()]);
        }
        $id = match ($case) {
            'unknown' => Uuid::uuid4()->toString(),
            'malformed' => 'not-a-uuid',
            default => $order['id'],
        };

        $this->upload($id, $this->uploadedPdf("plain text, not one of D-40's types\n", 'notes.txt'), $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertSame(0, DB::table('purchase_order_files')->count());
        self::assertSame(0, DB::table('files')->count());
        self::assertSame(0, DB::table('audit_log')->where('event', 'PURCHASE_ORDER_DOCUMENT_ATTACHED')->count());
    }

    /** @return array<string, array{RoleName}> */
    public static function withoutTheGrant(): array
    {
        return ['CEO' => [RoleName::Ceo], 'Procurement' => [RoleName::Procurement], 'Outdoor Supervisor' => [RoleName::OutdoorSupervisor]];
    }

    /** §3.5: no `record_customer_response` cell. */
    #[DataProvider('withoutTheGrant')]
    public function test_a_role_without_the_grant_cannot_attach(RoleName $role): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes()), $role)->assertStatus(403);
        self::assertSame(0, DB::table('purchase_order_files')->count());
    }

    /** No fixture: signing in to write one would leave this test's client authenticated. */
    public function test_an_unauthenticated_caller_cannot_attach(): void
    {
        $this->post(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/documents', ['document' => $this->uploadedPdf(self::pdfBytes())])
            ->assertUnauthorized();
    }

    // ─────────────────────────────────────────────────────────── the file

    public function test_an_unsupported_type_is_refused(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $this->upload($order['id'], $this->uploadedPdf("plain text, not one of D-40's types\n", 'notes.txt'))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'unsupported_type');
        self::assertSame(0, DB::table('purchase_order_files')->count());
    }

    public function test_a_missing_file_is_a_validation_failure(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $this->post(self::ENDPOINT.'/'.$order['id'].'/documents', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'document');
    }

    // ───────────────────────────────────────────────────────── the download

    /** @return array<string, array{RoleName}> */
    public static function readers(): array
    {
        return [
            'the deal owner (Own)' => [RoleName::IndoorSales],
            'Team Leader (D-91)' => [RoleName::TeamLeader],
            'Procurement (D-91, reads but cannot attach)' => [RoleName::Procurement],
            'CEO' => [RoleName::Ceo],
        ];
    }

    /** `D-38`: the file is read under the order's own read — `quotation.view`, through the deal. */
    #[DataProvider('readers')]
    public function test_whoever_reads_the_order_downloads_its_file(RoleName $role): void
    {
        $order = $this->accepted($this->deal($this->userWith(RoleName::IndoorSales)->id), 'REF-1');
        $fileId = $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes()))->assertStatus(201)->json('data.id');
        self::assertIsString($fileId);

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role))->assertOk();
    }

    /** @return array<string, array{RoleName}> */
    public static function nonReaders(): array
    {
        return [
            'another owner (Own)' => [RoleName::OutdoorSales],
            'Outdoor Supervisor (no quotation.view)' => [RoleName::OutdoorSupervisor],
        ];
    }

    /** `OpenAPI §8.3`: re-checked at request time; out of reach is a 404, never a 403. */
    #[DataProvider('nonReaders')]
    public function test_whoever_cannot_read_the_order_cannot_download_its_file(RoleName $role): void
    {
        $order = $this->accepted($this->deal($this->userWith(RoleName::IndoorSales)->id), 'REF-1');
        $fileId = $this->upload($order['id'], $this->uploadedPdf(self::pdfBytes()))->assertStatus(201)->json('data.id');
        self::assertIsString($fileId);

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role))->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────── fixtures

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function upload(string $purchaseOrderId, UploadedFile $file, RoleName $role = RoleName::Manager): TestResponse
    {
        return $this->post(self::ENDPOINT.'/'.$purchaseOrderId.'/documents', ['document' => $file], $this->bearerFor($role));
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function show(string $id): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager));
    }

    /** `UploadValidationTest::pdf()`'s exact shape — proven to pass finfo and the PdfEndMarker check. */
    private static function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
    }

    private function uploadedPdf(string $contents, string $name = 'po.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * A `sent` quotation accepted through 1.6's endpoint.
     *
     * @return array{id: string, po_number: string, quotation_id: string}
     */
    private function accepted(string $dealId, string $reference): array
    {
        $id = $this->postJson(self::QUOTATIONS, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now()]);
        $etag = $this->getJson(self::QUOTATIONS.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        $order = $this->patchJson(self::QUOTATIONS.'/'.$id.'/respond', [
            'response' => 'accepted',
            'customer_po_reference' => $reference,
            'po_date' => '2026-09-23',
        ], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])->assertStatus(200)->json('data.purchase_order');
        self::assertIsArray($order);
        self::assertIsString($order['id']);
        self::assertIsString($order['po_number']);

        return ['id' => $order['id'], 'po_number' => $order['po_number'], 'quotation_id' => $id];
    }

    private function currency(string $code): void
    {
        DB::table('currencies')->insert([
            'id' => Uuid::uuid4()->toString(),
            'code' => $code,
            'rounding_unit' => '1',
            'rounding_enabled' => false,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'status' => 'quotation_sent',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();
        $egp = DB::table('currencies')->where('code', 'EGP')->value('id');

        DB::table('suppliers')->insert([
            'id' => $supplierId, 'name' => 'Alpha Supplies',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            'total_price' => '1',
            'currency_id' => $egp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => '10',
            'quantity' => '100',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
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
        if (isset($this->tokens[$role->value])) {
            return ['Authorization' => 'Bearer '.$this->tokens[$role->value]];
        }

        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);
        $this->tokens[$role->value] = $token;

        return ['Authorization' => 'Bearer '.$token];
    }
}
