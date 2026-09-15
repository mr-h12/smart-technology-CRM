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
 * `PATCH /api/v1/quotations/{id}/edit-and-approve` — Module 8 Point 1.3.
 *
 * §6.4's second arrow, `Pending ──edit + approve──► Approved`, and its rule
 * that the approver "may edit tax, margin, or any field — and every edit is
 * written to the audit log". The owner's Q4 ruling (#131): 6.7's body
 * (`SaveQuotationRequest`, the `PATCH` shape), one transaction — re-price
 * the pending row as 3.6 re-prices a draft, then 1.1's approval — so the
 * audit shows `QUOTATION_UPDATED` (old → new) followed by
 * `QUOTATION_APPROVED` or `SELF_APPROVAL` (§6.5). §3.5's `edit margin` and
 * `edit tax` checkmarks are asked exactly as 3.6 asks them.
 */
final class QuotationEditAndApproveEndpointTest extends TestCase
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

    public function test_that_an_unauthenticated_caller_cannot_edit_and_approve(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/edit-and-approve', $this->payload())->assertStatus(401);
    }

    /** `D-a`: `team` is granted and unbacked — fails closed, as the approve does. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(), RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * The route is `quotation.approve`'s (§3.5 "approve / edit & approve" is one row).
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonApprovers(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonApprovers')]
    public function test_that_a_role_without_the_grant_cannot_edit_and_approve(RoleName $role): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), $role)->assertStatus(403);

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('pending', $row->status);
        self::assertSame('20.000', $row->default_margin, 'a refused edit-and-approve changes nothing.');
    }

    /** §3.5 `edit margin` is its own checkmark: an approver without it may approve but not move the margin. */
    public function test_that_changing_the_margin_needs_the_edit_margin_grant(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_margin')->delete();

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'), 'the approval must not commit without the edit.');

        $this->editAndApprove($id, $etag, $this->payload(), RoleName::Manager)->assertStatus(200);
    }

    /** §3.5 `edit tax`, the same way. */
    public function test_that_changing_the_tax_needs_the_edit_tax_grant(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit_tax')->delete();

        $this->editAndApprove($id, $etag, $this->payload(['tax_percent' => '14']), RoleName::Manager)->assertStatus(403);
        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/edit-and-approve', $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` and names the current etag; nothing moves. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, 'quotation:'.$id.':1', $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('pending', $row->status);
        self::assertSame('20.000', $row->default_margin);
    }

    // ───────────────────────────────────────────────────── the transition

    /** §6.4 draws no arrow from `draft` to `approved`: a Draft is `409 state_transition_invalid`, not 3.6's edit. */
    public function test_that_a_draft_is_a_transition_error(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null), RoleName::Manager);

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::INVALID_TRANSITION);

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('draft', $row->status);
        self::assertSame('20.000', $row->default_margin, 'a refused transition must not edit the draft.');
        self::assertSame(1, $row->version_token);
    }

    // ─────────────────────────────────────────────────────── the happy path

    /** A margin edit is re-priced (3.6's path) and the row approved, in one answer with 3.5's body. */
    public function test_that_a_margin_edit_is_priced_and_the_quotation_approved(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_self_approved', false)
            ->assertJsonPath('data.default_margin', '25.000')
            ->assertJsonPath('data.items.0.unit_price', '12.500000')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':4');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('approved', $row->status);
        self::assertSame('25.000', $row->default_margin);
        self::assertNotNull($row->submitted_at, 'the submission moment survives the approval.');
    }

    /** "No edit in the body still approves": an unchanged body is a plain approval through this route. */
    public function test_that_an_unchanged_body_still_approves(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.default_margin', '20.000');
    }

    /** `D-50`: the approver is `created_by` → `is_self_approved`, through this route as through 1.1's. */
    public function test_that_editing_and_approving_ones_own_quotation_flags_it_as_self_approved(): void
    {
        [$id, $etag] = $this->pending(RoleName::Manager);

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_self_approved', true);

        self::assertInstanceOf(stdClass::class, $this->auditRow($id, 'SELF_APPROVAL'));
        self::assertNull($this->auditRow($id, 'QUOTATION_APPROVED'));
    }

    // ────────────────────────────────────────────────────────────── audit

    /** §6.4 "every edit is written to the audit log": `QUOTATION_UPDATED` with the margin old → new, then `QUOTATION_APPROVED`. */
    public function test_that_a_margin_edit_is_audited_old_to_new_before_the_approval(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(['default_margin' => '25']), RoleName::Manager)->assertStatus(200);

        $updated = $this->auditRow($id, 'QUOTATION_UPDATED');
        self::assertInstanceOf(stdClass::class, $updated);
        self::assertIsString($updated->old_values);
        self::assertIsString($updated->new_values);
        $old = json_decode($updated->old_values, true);
        $new = json_decode($updated->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('20.000', $old['default_margin'] ?? null);
        self::assertSame('25', $new['default_margin'] ?? null);

        $approved = $this->auditRow($id, 'QUOTATION_APPROVED');
        self::assertInstanceOf(stdClass::class, $approved);
        self::assertGreaterThanOrEqual($updated->id, $approved->id, 'the edit is recorded before the approval.');
        self::assertNull($this->auditRow($id, 'SELF_APPROVAL'));
    }

    /** A tax edit likewise: `tax_percent` old → new inside `QUOTATION_UPDATED`. */
    public function test_that_a_tax_edit_is_audited_old_to_new(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->editAndApprove($id, $etag, $this->payload(['tax_percent' => '14']), RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.tax_percent', '14.000');

        $updated = $this->auditRow($id, 'QUOTATION_UPDATED');
        self::assertInstanceOf(stdClass::class, $updated);
        self::assertIsString($updated->old_values);
        self::assertIsString($updated->new_values);
        $old = json_decode($updated->old_values, true);
        $new = json_decode($updated->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertNull($old['tax_percent'] ?? null);
        self::assertSame('14', $new['tax_percent'] ?? null);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->editAndApprove($id, 'quotation:'.$id.':1', $this->payload(), RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function editAndApprove(string $id, string $ifMatch, array $body, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/edit-and-approve', $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /**
     * 6.7's full editable body — the quotation as `quotation()` created it,
     * so an unchanged body is "no edit".
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

    private function auditRow(string $id, string $event): ?stdClass
    {
        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', $event)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * A quotation `$creator` built on their own deal and submitted (4.2), so
     * `created_by` is `$creator` — the fact Q3 keys self-approval on.
     *
     * @return array{string, string} id and the etag after the submit
     */
    private function pending(RoleName $creator): array
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($creator)->id), $creator);

        $etag = $this->patchJson(self::ENDPOINT.'/'.$id.'/submit-for-approval', [], [...$this->bearerFor($creator), 'If-Match' => $etag])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    /**
     * Creates a quotation through Point 3.4's endpoint as `$creator` and reads
     * its etag through Point 3.5's: one line of 2 × 10 at 20 % margin, plus 5
     * delivery.
     *
     * @return array{string, string} id and etag
     */
    private function quotation(string $dealId, RoleName $creator): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor($creator))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($creator))->assertStatus(200)->json('data.etag');
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
