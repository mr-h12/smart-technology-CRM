<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 10 · 2.2 — `GET /purchase-orders` and `GET /purchase-orders/{id}`
 * (`OpenAPI §7.1`), and the quotation detail naming its order.
 *
 * A purchase order has no permission of its own: it is read by whoever may
 * view its quotation, scoped through the deal (Q10, `D-91`). `q` finds it by
 * either number (§4.6 "Search works on both numbers", `D-53`). The fields,
 * the sort and the absent tax line are the owner's answers during 2.2's
 * questions (`CHECKLIST.md`, Module 10); never cost, margin or suppliers.
 */
final class PurchaseOrderReadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/purchase-orders';

    private const QUOTATIONS = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    private const LIST_FIELDS = ['id', 'po_number', 'customer_po_reference', 'po_date', 'quotation_id', 'quotation_code', 'customer_id', 'customer_name', 'final_total', 'currency'];

    private const DETAIL_FIELDS = [
        ...self::LIST_FIELDS,
        'created_at', 'created_by', 'created_by_name', 'documents',
        'deal_id', 'deal_code', 'deal_owner_id', 'deal_owner_name', 'quotation_status',
        'subtotal', 'additional_total', 'discount_amount',
    ];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, string> one login per role per test — the login limit counts every call */
    private array $tokens = [];

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

    // ──────────────────────────────────────────────────────────────── search

    /** The module's criterion: "Search works on both the internal PO number and the customer's reference". */
    public function test_q_finds_a_purchase_order_by_its_po_number_and_by_the_customers_reference(): void
    {
        $deal = $this->deal(null);
        $first = $this->accepted($deal, '4500123987');
        $second = $this->accepted($deal, 'ABC-77');

        $byNumber = $this->list(['q' => substr($first['po_number'], -4)])
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
        self::assertSame([$first['id']], $byNumber->json('data.*.id'));

        $byReference = $this->list(['q' => 'abc'])
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
        self::assertSame([$second['id']], $byReference->json('data.*.id'));
    }

    // ────────────────────────────────────────────────────────────────── list

    public function test_a_list_row_carries_its_quotation_customer_and_total_and_no_cost_field(): void
    {
        $order = $this->accepted($this->deal(null), '4500123987', '2026-09-20');

        $response = $this->list()
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.per_page', 25)
            ->assertJsonPath('meta.pagination.page', 1);

        $row = $response->json('data.0');
        self::assertIsArray($row);
        self::assertEqualsCanonicalizing(self::LIST_FIELDS, array_keys($row));

        $quotation = $this->quotationRead($order['quotation_id']);
        self::assertSame($order['id'], $row['id']);
        self::assertSame($order['po_number'], $row['po_number']);
        self::assertSame('4500123987', $row['customer_po_reference']);
        self::assertSame('2026-09-20', $row['po_date']);
        self::assertSame($order['quotation_id'], $row['quotation_id']);
        self::assertSame($quotation['code'], $row['quotation_code']);
        self::assertSame($this->customerId, $row['customer_id']);
        self::assertSame('Nile Trading', $row['customer_name']);
        self::assertSame($quotation['final_total'], $row['final_total']);
        self::assertSame('EGP', $row['currency']);
    }

    /**
     * Created A, B, C (so `po_number` ascends A, B, C); `po_date` and
     * `created_at` are set so that every sort gives a different order.
     *
     * @return array<string, array{?string, list<string>}>
     */
    public static function sorts(): array
    {
        return [
            'the default, -created_at' => [null, ['A', 'C', 'B']],
            'created_at' => ['created_at', ['B', 'C', 'A']],
            'po_number' => ['po_number', ['A', 'B', 'C']],
            '-po_number' => ['-po_number', ['C', 'B', 'A']],
            'po_date' => ['po_date', ['C', 'A', 'B']],
            '-po_date' => ['-po_date', ['B', 'A', 'C']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('sorts')]
    public function test_the_list_sorts_by_the_three_declared_fields(?string $sort, array $expected): void
    {
        $deal = $this->deal(null);
        $ids = [
            'A' => $this->accepted($deal, 'REF-A', '2026-09-02')['id'],
            'B' => $this->accepted($deal, 'REF-B', '2026-09-03')['id'],
            'C' => $this->accepted($deal, 'REF-C', '2026-09-01')['id'],
        ];
        DB::table('purchase_orders')->where('id', $ids['A'])->update(['created_at' => now()->subHour()]);
        DB::table('purchase_orders')->where('id', $ids['B'])->update(['created_at' => now()->subHours(3)]);
        DB::table('purchase_orders')->where('id', $ids['C'])->update(['created_at' => now()->subHours(2)]);

        $rows = $this->list($sort === null ? [] : ['sort' => $sort])->assertStatus(200)->json('data.*.id');

        self::assertSame(array_map(static fn (string $letter): string => $ids[$letter], $expected), $rows);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function refusedQueries(): array
    {
        return [
            'a page size above §6.1 maximum' => [['per_page' => '101'], 'per_page', 'above_maximum'],
            'a page of zero' => [['page' => '0'], 'page', 'not_a_positive_integer'],
            'a page size that is not a number' => [['per_page' => 'lots'], 'per_page', 'not_a_positive_integer'],
            'a filter (none besides q)' => [['filter' => ['status' => 'accepted']], 'filter', 'unknown_parameter'],
            'an include (none declared)' => [['include' => 'quotation'], 'include', 'unknown_parameter'],
            'a group (none declared)' => [['group_by' => 'customer'], 'group_by', 'unknown_parameter'],
            'a q that is not text' => [['q' => ['PO']], 'q', 'not_a_string'],
            'a sort field this resource does not declare' => [['sort' => 'final_total'], 'sort', 'unknown_sort_field'],
            'the same sort field twice' => [['sort' => '-po_date,po_date'], 'sort', 'repeated_sort_field'],
        ];
    }

    /**
     * `OpenAPI §6.1`–`§6.2`: never ignored silently.
     *
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('refusedQueries')]
    public function test_a_refused_query_is_a_400(array $query, string $parameter, string $detailCode): void
    {
        $this->list($query)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', $parameter)
            ->assertJsonPath('error.details.0.code', $detailCode);
    }

    public function test_the_page_size_cap_is_accepted_at_the_cap(): void
    {
        $this->list(['per_page' => '100'])
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 100);
    }

    // ───────────────────────────────────────────────────────────────── scope

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['Outdoor Sales' => [RoleName::OutdoorSales], 'Indoor Sales' => [RoleName::IndoorSales]];
    }

    /** §3.5 `view` · Own, reached through the quotation's deal (`SEC-08`); counted after scoping (§6.1). */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_lists_only_its_own_deals_orders(RoleName $role): void
    {
        $mine = $this->accepted($this->deal($this->userWith($role)->id), 'MINE-1');
        $this->accepted($this->deal($this->userWith(RoleName::Manager)->id), 'THEIRS-1');
        $this->accepted($this->deal(null), 'NOBODY-1');

        $response = $this->list([], $role)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
        self::assertSame([$mine['id']], $response->json('data.*.id'));

        // The search is scoped too: another owner's reference finds nothing.
        $this->list(['q' => 'THEIRS'], $role)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 0);
    }

    /** @return array<string, array{RoleName}> */
    public static function allScoped(): array
    {
        return [
            'Manager' => [RoleName::Manager],
            'CEO' => [RoleName::Ceo],
            'Team Leader (D-91)' => [RoleName::TeamLeader],
            'Procurement (D-91)' => [RoleName::Procurement],
        ];
    }

    #[DataProvider('allScoped')]
    public function test_an_all_scoped_role_reads_every_order(RoleName $role): void
    {
        $owned = $this->accepted($this->deal($this->userWith(RoleName::IndoorSales)->id), 'OWNED-1');
        $this->accepted($this->deal(null), 'NOBODY-1');

        $this->list([], $role)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 2);

        $this->show($owned['id'], $role)->assertStatus(200)->assertJsonPath('data.id', $owned['id']);
    }

    /** §3.5: the Outdoor Supervisor holds no `quotation.view`. */
    public function test_a_role_without_quotation_view_is_refused(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');

        $this->list([], RoleName::OutdoorSupervisor)->assertStatus(403);
        $this->show($order['id'], RoleName::OutdoorSupervisor)->assertStatus(403);
    }

    /** No fixture: signing in to write one would leave this test's client authenticated. */
    public function test_an_unauthenticated_caller_cannot_read(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
        $this->getJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString())->assertStatus(401);
    }

    /**
     * `OpenAPI §5.1`: "Do not reveal which case applies" — out of reach, unknown
     * and deleted answer alike. A malformed id is refused by the route since
     * F-17 · 1.2: the same `404 resource_not_found`, in the router's words; it
     * names no order, so it reveals nothing (the owner's ruling A, 2026-09-24).
     */
    public function test_an_order_out_of_reach_unknown_malformed_or_deleted_is_the_same_404(): void
    {
        $theirs = $this->accepted($this->deal($this->userWith(RoleName::OutdoorSales)->id), 'THEIRS-1');
        $deleted = $this->accepted($this->deal(null), 'GONE-1');
        DB::table('purchase_orders')->where('id', $deleted['id'])->update(['deleted_at' => now()]);

        $messages = [];
        foreach ([
            [$theirs['id'], RoleName::IndoorSales],
            [Uuid::uuid4()->toString(), RoleName::Manager],
            [$deleted['id'], RoleName::Manager],
        ] as [$id, $role]) {
            $message = $this->show($id, $role)
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found')
                ->json('error.message');
            self::assertIsString($message);
            $messages[] = $message;
        }

        self::assertCount(1, array_unique($messages));

        $this->show('not-a-uuid', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    public function test_a_deleted_order_is_not_listed(): void
    {
        $deleted = $this->accepted($this->deal(null), 'GONE-1');
        DB::table('purchase_orders')->where('id', $deleted['id'])->update(['deleted_at' => now()]);

        $this->list()->assertStatus(200)->assertJsonPath('meta.pagination.total', 0);
        $this->list(['q' => 'GONE'])->assertStatus(200)->assertJsonPath('meta.pagination.total', 0);
    }

    // ──────────────────────────────────────────────────────────────── detail

    /** The owner's detail: the list's fields, who and when, the deal, the status and the breakdown — no cost side. */
    public function test_the_detail_carries_the_deal_the_recorder_and_the_breakdown_and_no_cost_field(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $deal = $this->deal($owner->id);
        $order = $this->accepted($deal, '4500123987', '2026-09-20', ['discount_percent' => '10', 'tax_percent' => '14']);

        $data = $this->show($order['id'])->assertStatus(200)->json('data');
        self::assertIsArray($data);
        self::assertEqualsCanonicalizing([...self::DETAIL_FIELDS, 'tax_percent', 'tax_amount'], array_keys($data));

        $quotation = $this->quotationRead($order['quotation_id']);
        self::assertSame($order['po_number'], $data['po_number']);
        self::assertSame($quotation['code'], $data['quotation_code']);
        self::assertSame('Nile Trading', $data['customer_name']);
        self::assertSame('accepted', $data['quotation_status']);
        self::assertSame($deal, $data['deal_id']);
        self::assertSame(DB::table('deals')->where('id', $deal)->value('code'), $data['deal_code']);
        self::assertSame($owner->id, $data['deal_owner_id']);
        self::assertSame($owner->name, $data['deal_owner_name']);
        self::assertSame($this->userWith(RoleName::Manager)->id, $data['created_by']);
        self::assertSame($this->userWith(RoleName::Manager)->name, $data['created_by_name']);
        self::assertIsString($data['created_at']);
        self::assertSame([], $data['documents']);

        // The quotation's own numbers, as its detail gives them — the order
        // carries the accepted quotation's snapshot, it computes nothing.
        foreach (['subtotal', 'additional_total', 'discount_amount', 'tax_percent', 'tax_amount', 'final_total'] as $field) {
            self::assertSame($quotation[$field], $data[$field], $field);
        }
        self::assertNotSame('0', $data['discount_amount']);

        foreach ([...QuotationLine::COST_FIELDS, 'default_margin', 'lines', 'items', 'supplier_id', 'supplier_name'] as $absent) {
            self::assertArrayNotHasKey($absent, $data);
        }
    }

    /** `D-63`: an exempt quotation renders no tax line at all — the keys are absent, not null. */
    public function test_an_exempt_quotations_order_has_no_tax_keys(): void
    {
        $order = $this->accepted($this->deal(null), 'REF-1');
        self::assertNull(DB::table('quotations')->where('id', $order['quotation_id'])->value('tax_percent'));

        $data = $this->show($order['id'])->assertStatus(200)->json('data');
        self::assertIsArray($data);
        self::assertEqualsCanonicalizing(self::DETAIL_FIELDS, array_keys($data));
    }

    /** `documents` reads the order's files through Storage (owner, 2.2 A; 2.3 A1 replaced `has_attachment`). */
    public function test_documents_lists_the_orders_files(): void
    {
        $with = $this->accepted($this->deal(null), 'WITH-1');
        $without = $this->accepted($this->deal(null), 'WITHOUT-1');
        $deletedFile = $this->accepted($this->deal(null), 'DELETED-1');
        $this->attachFile($with['id']);
        DB::table('files')->where('id', $this->attachFile($deletedFile['id']))->update(['deleted_at' => now()]);

        $this->show($with['id'])->assertStatus(200)->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.original_name', 'po.pdf');
        $this->show($without['id'])->assertStatus(200)->assertJsonCount(0, 'data.documents');
        // `FileRepositoryInterface`: "Soft-deleted rows are invisible here" (DB-01).
        $this->show($deletedFile['id'])->assertStatus(200)->assertJsonCount(0, 'data.documents');
    }

    // ────────────────────────────────────────────── the quotation detail's PO

    /** "The quotation detail always carries `purchase_order` (null when none)". */
    public function test_the_quotation_detail_names_its_purchase_order_or_null(): void
    {
        $deal = $this->deal(null);
        $order = $this->accepted($deal, '4500123987', '2026-09-20');
        [$unaccepted] = $this->sent($deal);

        $named = $this->quotationRead($order['quotation_id']);
        self::assertSame([
            'id' => $order['id'],
            'po_number' => $order['po_number'],
            'customer_po_reference' => '4500123987',
            'po_date' => '2026-09-20',
        ], $named['purchase_order']);

        $none = $this->quotationRead($unaccepted);
        self::assertArrayHasKey('purchase_order', $none);
        self::assertNull($none['purchase_order']);
    }

    // ──────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function list(array $query = [], RoleName $role = RoleName::Manager): TestResponse
    {
        return $this->getJson(self::ENDPOINT.($query === [] ? '' : '?'.http_build_query($query)), $this->bearerFor($role));
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function show(string $id, RoleName $role = RoleName::Manager): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($role));
    }

    /** @return array<mixed> the quotation's detail as the Manager reads it */
    private function quotationRead(string $id): array
    {
        $data = $this->getJson(self::QUOTATIONS.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($data);

        return $data;
    }

    /**
     * A `sent` quotation accepted through 1.6's endpoint — the order is
     * written the one way the module writes it.
     *
     * @param  array<string, string>  $quotation  extra fields for the draft
     * @return array{id: string, po_number: string, quotation_id: string}
     */
    private function accepted(string $dealId, string $reference, string $poDate = '2026-09-23', array $quotation = []): array
    {
        [$id, $etag] = $this->sent($dealId, $quotation);

        $order = $this->patchJson(self::QUOTATIONS.'/'.$id.'/respond', [
            'response' => 'accepted',
            'customer_po_reference' => $reference,
            'po_date' => $poDate,
        ], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])->assertStatus(200)->json('data.purchase_order');
        self::assertIsArray($order);
        self::assertIsString($order['id']);
        self::assertIsString($order['po_number']);

        return ['id' => $order['id'], 'po_number' => $order['po_number'], 'quotation_id' => $id];
    }

    /**
     * A draft through Point 3.4's endpoint, then set `sent` in place.
     *
     * @param  array<string, string>  $extra
     * @return array{string, string} id and etag
     */
    private function sent(string $dealId, array $extra = []): array
    {
        $id = $this->postJson(self::QUOTATIONS, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            ...$extra,
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now()]);

        $etag = $this->quotationRead($id)['etag'];
        self::assertIsString($etag);

        return [$id, $etag];
    }

    /** One stored file on the order's pivot, as `DatabaseFileWriter::attach()` writes it. */
    private function attachFile(string $purchaseOrderId): string
    {
        $fileId = Uuid::uuid4()->toString();

        DB::table('files')->insert([
            'id' => $fileId,
            'original_name' => 'po.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            // §17's shape — `StoragePath::fromStored()` refuses anything else.
            'storage_path' => '2026/09/purchase_order/'.$purchaseOrderId.'/'.$fileId.'.pdf',
            'scan_status' => 'clean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_order_files')->insert(['purchase_order_id' => $purchaseOrderId, 'file_id' => $fileId]);

        return $fileId;
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
