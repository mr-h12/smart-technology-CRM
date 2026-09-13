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
 * `PATCH /api/v1/quotations/{id}` — Module 7 Point 3.6.
 *
 * `DB-12`/`API-12`: `If-Match` carries §9.2's etag, a stale one is `409
 * concurrency_conflict` (never 412), and the write advances `version_token`
 * so the token that just succeeded is stale on the next try. §3.5's `edit`
 * row is Draft-only; `edit margin` and `edit tax` are separate grants. Every
 * edit is re-priced by §5 and written to the audit log (§6.4).
 */
final class QuotationUpdateEndpointTest extends TestCase
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

        $this->currency('EGP', '1', false, true);
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine('10', '5');
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_edit(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), $this->payload())->assertStatus(401);
    }

    public function test_that_the_manager_edits_any_draft(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(200);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_edits_its_own_deals_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($role)->id));

        $this->edit($id, $etag, $this->payload(), $role)->assertStatus(200);
    }

    /** §5.1: out of reach is a 404, indistinguishable from absent. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_edit_another_owners_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));

        $this->edit($id, $etag, $this->payload(), $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `team` is granted and unbacked — fails closed, as the read does. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::TeamLeader)->id));

        $this->edit($id, $etag, $this->payload(), RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `edit` cell is `—` for Procurement and the CEO, and the Outdoor Supervisor.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonEditors(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonEditors')]
    public function test_that_a_role_without_the_grant_cannot_edit(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), $role)->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit')->delete();

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(403);
    }

    /** §3.5 `edit margin` is its own checkmark: without it, a changed margin is refused … */
    public function test_that_changing_the_margin_needs_the_edit_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');
    }

    /** … a line margin counts as a margin … */
    public function test_that_a_line_margin_needs_the_edit_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload([
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2', 'margin_percent' => '30']],
        ]), RoleName::Manager)->assertStatus(403);
    }

    /** … and an unchanged margin is not an edit of the margin. */
    public function test_that_an_unchanged_margin_needs_no_margin_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->edit($id, $etag, $this->payload(['payment_terms' => '30 days']), RoleName::Manager)->assertStatus(200);
    }

    /** §3.5 `edit tax`, the same way. */
    public function test_that_changing_the_tax_needs_the_edit_tax_grant(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_tax')->delete();

        $this->edit($id, $etag, $this->payload(['tax_percent' => '14']), RoleName::Manager)->assertStatus(403);
        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────── optimistic lock

    /** `OpenAPI §5.1`: a missing or malformed `If-Match` is an invalid header → 400. */
    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->patchJson(self::ENDPOINT.'/'.$id, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    public function test_that_a_malformed_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->edit($id, '"7"', $this->payload(), RoleName::Manager)->assertStatus(400);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` — never 412 — and names the current etag to refresh from. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, 'quotation:'.$id.':0', $this->payload(), RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        // Nothing was written.
        self::assertSame('20.000', DB::table('quotations')->where('id', $id)->value('default_margin'));
    }

    /** `DB-12`: the write advances the token, so the token that just succeeded is stale on the next try. */
    public function test_that_a_successful_edit_advances_the_token(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':2')
            // §6.3's document version is a different counter and does not move on an edit.
            ->assertJsonPath('data.version', 1);

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(409);
    }

    // ────────────────────────────────────────────────────────── draft only

    /** §3.5 `edit` = "(Draft)"; a submitted quotation is Module 8's to edit. */
    public function test_that_a_non_draft_quotation_is_refused(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'pending']);

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::NOT_DRAFT);
    }

    // ───────────────────────────────────────────────────── what the edit does

    /** §5 re-priced on the backend: 3 × (10 × 1.25) = 37.5, plus 7 untaxed (`D-62`), 10 % discount before tax (`D-64`), 14 % tax. */
    public function test_that_an_edit_is_repriced_and_answered_in_full(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload([
            'default_margin' => '25',
            'discount_percent' => '10',
            'tax_percent' => '14',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '3']],
            'additional_items' => [['description' => 'Installation', 'amount' => '7']],
            'payment_terms' => '30 days',
        ]), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.default_margin', '25.000')
            ->assertJsonPath('data.subtotal', '37.500000')
            ->assertJsonPath('data.discount_amount', '3.750000')
            ->assertJsonPath('data.tax_base', '33.750000')
            ->assertJsonPath('data.tax_amount', '4.725000')
            ->assertJsonPath('data.additional_total', '7.000000')
            ->assertJsonPath('data.final_total', '45.475000')
            ->assertJsonPath('data.payment_terms', '30 days')
            ->assertJsonPath('data.items.0.quantity', '3.0000')
            ->assertJsonPath('data.items.0.unit_price', '12.500000')
            ->assertJsonPath('data.additional_items.0.description', 'Installation')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.additional_items');

        // The replaced lines are soft-deleted, never physically gone (`DB-01`).
        self::assertSame(2, DB::table('quotation_items')->where('quotation_id', $id)->count());
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $id)->whereNull('deleted_at')->count());
    }

    /** §5.6 still warns on the edit, in `meta`, without blocking. */
    public function test_that_an_over_quantity_line_warns_on_edit(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload([
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '9']],
        ]), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('meta.warnings.0.field', 'lines.0.quantity')
            ->assertJsonPath('meta.warnings.0.code', 'quantity_exceeds_recorded');
    }

    /** §5.6 still blocks a line with no usable price. */
    public function test_that_an_unpriceable_line_is_blocked_on_edit(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('supplier_quotation_items')->where('id', $this->lineId)->update(['deleted_at' => now()]);

        $this->edit($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'supplier_price_missing');
    }

    /** §6.4: "every edit is written to the audit log" — with `AUD-02`'s old and new values. */
    public function test_that_an_edit_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_UPDATED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('20.000', $old['default_margin'] ?? null);
        self::assertSame('25', $new['default_margin'] ?? null);
    }

    /** The deal and the customer are the quotation's identity, not fields of an edit. */
    public function test_that_the_deal_and_customer_cannot_be_moved(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->edit($id, $etag, $this->payload(['deal_id' => Uuid::uuid4()->toString()]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'deal_id']);
        $this->edit($id, $etag, $this->payload(['customer_id' => Uuid::uuid4()->toString()]), RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'customer_id']);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->edit($id, 'quotation:'.$id.':1', $this->payload(), RoleName::Manager)->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function edit(string $id, string $ifMatch, array $body, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id, $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /**
     * The full editable body — an edit replaces every editable field, so the
     * default is the quotation as `quotation()` created it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], $overrides);
    }

    /**
     * Creates a quotation through Point 3.4's endpoint and reads its etag
     * through Point 3.5's: one line of 2 × 10 at 20 % margin, plus 5 delivery.
     *
     * @return array{string, string} id and etag
     */
    private function quotation(string $dealId): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            ...$this->payload(),
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    private function currency(string $code, string $unit, bool $enabled, bool $base): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => $unit,
            'rounding_enabled' => $enabled,
            'is_base' => $base,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function currencyId(string $code): string
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        self::assertIsString($id);

        return $id;
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
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

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
            'currency_id' => $this->currencyId('EGP'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
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
