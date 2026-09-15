<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * `days_waiting` and `sla_exceeded` on the list row and the detail —
 * Module 8 Point 2.1. `D-11`: no automatic escalation, "a red badge plus a
 * 'days waiting' column" — both **server-computed** from `submitted_at`
 * against `limits.quotation_approval_sla_hours` read through
 * `SettingReader` (§13 screen 6 "quotation approval SLA"), never by the SPA.
 * Null unless the row is `pending`; `sla_exceeded` is also null while the
 * limit is unconfigured (`SystemLimit`'s own docblock leaves it unseeded,
 * and `SettingReader::nullableInteger()` says the caller does not guess).
 * The detail also carries 1.2's `returned_at` and `return_note`.
 *
 * `SystemLimit` and the repository are imported here from `Admin` the way
 * `CustomerStatusRecomputeTest` imports them — a test may cross the boundary
 * application code may not, and `put()` is the cache-safe write.
 */
final class QuotationApprovalWaitingTest extends TestCase
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

    /** A pending quotation three days old against a 48-hour SLA: three days waiting, SLA exceeded — on the row and on the detail. */
    public function test_that_a_pending_quotation_past_the_sla_is_flagged_on_the_row_and_the_detail(): void
    {
        $this->sla('48');
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->travel(3)->days();

        $row = $this->row($id);
        self::assertSame(3, $row['days_waiting'] ?? null);
        self::assertTrue($row['sla_exceeded'] ?? null);

        $detail = $this->detail($id);
        self::assertSame(3, $detail['days_waiting'] ?? null);
        self::assertTrue($detail['sla_exceeded'] ?? null);
    }

    /** One day old against 48 hours: waiting, not exceeded. */
    public function test_that_a_pending_quotation_within_the_sla_is_not_flagged(): void
    {
        $this->sla('48');
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->travel(1)->day();

        $row = $this->row($id);
        self::assertSame(1, $row['days_waiting'] ?? null);
        self::assertFalse($row['sla_exceeded'] ?? null);
    }

    /** A draft is not waiting: both null — present, not absent (the SPA reads the key). */
    public function test_that_a_draft_carries_nulls(): void
    {
        $this->sla('48');
        [$id] = $this->quotation($this->deal(null), RoleName::Manager);

        foreach ([$this->row($id), $this->detail($id)] as $data) {
            self::assertArrayHasKey('days_waiting', $data);
            self::assertArrayHasKey('sla_exceeded', $data);
            self::assertNull($data['days_waiting']);
            self::assertNull($data['sla_exceeded']);
        }
    }

    /** An approved quotation stops waiting, even though `submitted_at` survives on the row (1.1). */
    public function test_that_an_approved_quotation_stops_waiting(): void
    {
        $this->sla('48');
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/approve', [], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])->assertStatus(200);

        $this->travel(3)->days();

        $row = $this->row($id);
        self::assertNull($row['days_waiting']);
        self::assertNull($row['sla_exceeded']);
    }

    /** The limit is a setting, not a constant: a 1-hour SLA is exceeded after two hours, and `days_waiting` is still 0. */
    public function test_that_the_sla_is_read_from_the_setting(): void
    {
        $this->sla('1');
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->travel(2)->hours();

        $row = $this->row($id);
        self::assertSame(0, $row['days_waiting'] ?? null);
        self::assertTrue($row['sla_exceeded'] ?? null);
    }

    /** While the limit is unconfigured (unseeded), waiting is measured and the SLA verdict is null — not guessed. */
    public function test_that_an_unconfigured_sla_answers_null_not_false(): void
    {
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->travel(10)->days();

        $row = $this->row($id);
        self::assertSame(10, $row['days_waiting'] ?? null);
        self::assertArrayHasKey('sla_exceeded', $row);
        self::assertNull($row['sla_exceeded']);
    }

    /** 1.2's marks on the detail: null before a return, set after it. */
    public function test_that_the_detail_carries_the_return_marks(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $before = $this->detail($id);
        self::assertArrayHasKey('returned_at', $before);
        self::assertArrayHasKey('return_note', $before);
        self::assertNull($before['returned_at']);
        self::assertNull($before['return_note']);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['note' => 'Raise the margin.'], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])->assertStatus(200);

        $after = $this->detail($id);
        self::assertIsString($after['returned_at']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $after['returned_at'], '`DB-08`: an ISO-8601 instant in UTC.');
        self::assertSame('Raise the margin.', $after['return_note']);
        self::assertNull($after['days_waiting'], 'a returned draft is no longer waiting.');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    private function sla(string $hours): void
    {
        app(SystemLimitRepositoryInterface::class)->put(SystemLimit::QuotationApprovalSlaHours, $hours);
    }

    /** @return array<mixed> */
    private function detail(string $id): array
    {
        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($data);

        return $data;
    }

    /**
     * The list row for `$id`, from `GET /quotations`.
     *
     * @return array<mixed>
     */
    private function row(string $id): array
    {
        $items = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($items);
        $rows = array_values(array_filter($items, static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $id));
        self::assertCount(1, $rows);

        return $rows[0];
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
