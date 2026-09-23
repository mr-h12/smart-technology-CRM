<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 10 · 1.3 — `PATCH /quotations/{id}/send` (`D-90`, `OpenAPI §7.2`):
 * `approved → sent` with `sent_at`, no PDF, and the deal moved by
 * `DealOutcomeInterface::quotationSent` in the same transaction — its
 * `deal_id` taken only from the quotation the route authorised.
 */
final class QuotationSendEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

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

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_send(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/send')->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_sends_its_own_deals_quotation(RoleName $role): void
    {
        [$id, $etag] = $this->approved($this->deal($this->userWith($role)->id, 'supplier_quotation'));

        $this->send($id, $etag, $role)->assertStatus(200)->assertJsonPath('data.status', 'sent');
    }

    /** §5.1: out of reach is a 404 — and the other owner's deal does not move (1.2's audit: `deal_id` only from the authorised quotation). */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_send_another_owners_quotation(RoleName $role): void
    {
        $deal = $this->deal($this->userWith(RoleName::Manager)->id, 'supplier_quotation');
        [$id, $etag] = $this->approved($deal);

        $this->send($id, $etag, $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertSame('approved', $this->statusOf('quotations', $id));
        self::assertSame('supplier_quotation', $this->statusOf('deals', $deal));
    }

    /** `team` is granted and unbacked — fails closed (`D-a`). */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->approved($this->deal($this->userWith(RoleName::TeamLeader)->id, 'supplier_quotation'));

        $this->send($id, $etag, RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `send to customer` is `—` for Procurement and the CEO; the Outdoor Supervisor has no quotation row.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonSenders(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonSenders')]
    public function test_that_a_role_without_the_grant_cannot_send(RoleName $role): void
    {
        [$id, $etag] = $this->approved($this->deal(null, 'supplier_quotation'));

        $this->send($id, $etag, $role)->assertStatus(403);
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->approved($this->deal(null, 'supplier_quotation'));

        $this->patchJson(self::ENDPOINT.'/'.$id.'/send', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    public function test_that_a_stale_token_is_a_409(): void
    {
        [$id] = $this->approved($this->deal(null, 'supplier_quotation'));

        $this->send($id, 'quotation:'.$id.':0', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');

        self::assertSame('approved', $this->statusOf('quotations', $id));
    }

    // ───────────────────────────────────────────────────── the transition

    public function test_that_the_manager_sends_an_approved_quotation_and_the_deal_moves_to_quotation_sent(): void
    {
        $deal = $this->deal(null, 'supplier_quotation');
        [$id, $etag] = $this->approved($deal);

        $this->send($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'sent');

        self::assertIsString(DB::table('quotations')->where('id', $id)->value('sent_at'));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
        self::assertSame(1, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $deal)->count());
    }

    /** @return array<string, array{string}> */
    public static function atQuotationSentOrLater(): array
    {
        return ['quotation_sent' => ['quotation_sent'], 'negotiations' => ['negotiations'], 'won' => ['won']];
    }

    #[DataProvider('atQuotationSentOrLater')]
    public function test_that_a_deal_at_quotation_sent_or_later_is_left_where_it_is(string $status): void
    {
        $deal = $this->deal(null, $status);
        [$id, $etag] = $this->approved($deal);

        $this->send($id, $etag, RoleName::Manager)->assertStatus(200)->assertJsonPath('data.status', 'sent');

        self::assertSame($status, $this->statusOf('deals', $deal));
    }

    /** `D-90` rule a, one transaction: the refusal leaves the quotation `approved`, unsent and unaudited. */
    public function test_that_a_deal_before_supplier_quotation_refuses_the_send_with_a_422(): void
    {
        $deal = $this->deal(null, 'supplier_rfq');
        [$id, $etag] = $this->approved($deal);

        $this->send($id, $etag, RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'deal_not_ready_to_send')
            ->assertJsonPath('error.message', __('quotations.errors.deal_not_ready_to_send'));

        self::assertSame('approved', $this->statusOf('quotations', $id));
        self::assertNull(DB::table('quotations')->where('id', $id)->value('sent_at'));
        self::assertSame('supplier_rfq', $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'QUOTATION_SENT')->count());
    }

    public function test_that_sending_a_quotation_that_is_not_approved_is_a_transition_error(): void
    {
        [$id, $etag] = $this->approved($this->deal(null, 'supplier_quotation'));
        DB::table('quotations')->where('id', $id)->update(['status' => 'pending']);

        $this->send($id, $etag, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');
    }

    public function test_that_a_send_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->approved($this->deal(null, 'supplier_quotation'));

        $this->send($id, $etag, RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_SENT')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('approved', $old['status'] ?? null);
        self::assertNull($old['sent_at'] ?? null);
        self::assertSame('sent', $new['status'] ?? null);
        self::assertIsString($new['sent_at'] ?? null);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->send($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function send(string $id, string $ifMatch, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/send', [], [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    private function statusOf(string $table, string $id): string
    {
        $status = DB::table($table)->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
    }

    /**
     * A draft through Point 3.4's endpoint, then set `approved` in place — the
     * etag is `version_token`, which a status column write leaves alone.
     *
     * @return array{string, string} id and etag
     */
    private function approved(string $dealId): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update(['status' => 'approved']);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
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

    private function deal(?string $ownerId, string $status): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'status' => $status,
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
            'quantity' => '5',
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
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
