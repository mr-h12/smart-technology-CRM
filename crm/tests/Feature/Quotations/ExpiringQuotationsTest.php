<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Contracts\ExpiringQuotationsInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 16, Point 2 (`D-153`) — what Quotations tells J-16: every live `sent`
 * quotation whose `valid_until` falls between two calendar days, both included,
 * with its code, that day, and its deal's owner (through Deals' contract — §3.5's
 * "own" is the deal's owner). Read-only. J-01's fixtures, J-01's terms.
 */
final class ExpiringQuotationsTest extends TestCase
{
    use QuotationLineFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private ?string $token = null;

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->currency();
        $this->customerId = $this->customer();
        Carbon::setTestNow('2026-10-22 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_that_a_sent_quotation_inside_the_window_is_named_with_its_code_date_and_owner(): void
    {
        $owner = $this->user();
        $id = $this->quotation($this->deal($owner->id), '2026-10-24');
        $code = DB::table('quotations')->where('id', $id)->value('code');

        self::assertSame([[
            'quotation_id' => $id,
            'number' => $code,
            'valid_until' => '2026-10-24',
            'owner_id' => $owner->id,
        ]], $this->between('2026-10-22', '2026-10-25'));
    }

    public function test_that_both_ends_of_the_window_are_included_and_nothing_outside(): void
    {
        $deal = $this->deal($this->user()->id);
        $first = $this->quotation($deal, '2026-10-22');
        $last = $this->quotation($deal, '2026-10-25');
        $this->quotation($deal, '2026-10-21');
        $this->quotation($deal, '2026-10-26');
        $this->quotation($deal, null);

        self::assertEqualsCanonicalizing([$first, $last], array_column($this->between('2026-10-22', '2026-10-25'), 'quotation_id'));
    }

    /** @return array<string, array{string}> */
    public static function notSent(): array
    {
        return ['draft' => ['draft'], 'accepted' => ['accepted'], 'rejected' => ['rejected'], 'expired' => ['expired'], 'counter' => ['counter'], 'partial' => ['partial']];
    }

    #[DataProvider('notSent')]
    public function test_that_only_a_sent_quotation_is_named(string $status): void
    {
        $this->quotation($this->deal($this->user()->id), '2026-10-24', $status);

        self::assertSame([], $this->between('2026-10-22', '2026-10-25'));
    }

    public function test_that_a_soft_deleted_quotation_is_not_named(): void
    {
        $id = $this->quotation($this->deal($this->user()->id), '2026-10-24');
        DB::table('quotations')->where('id', $id)->update(['deleted_at' => now()]);

        self::assertSame([], $this->between('2026-10-22', '2026-10-25'));
    }

    public function test_that_an_unowned_deals_quotation_is_named_with_no_owner(): void
    {
        $id = $this->quotation($this->deal(null), '2026-10-24');

        $found = $this->between('2026-10-22', '2026-10-25');
        self::assertCount(1, $found);
        self::assertSame($id, $found[0]['quotation_id']);
        self::assertNull($found[0]['owner_id']);
    }

    // ── fixtures — J-01's (`ExpireQuotationsTest`) ─────────────────────────

    /** @return list<array{quotation_id: string, number: string, valid_until: string, owner_id: string|null}> */
    private function between(string $from, string $to): array
    {
        return $this->app->make(ExpiringQuotationsInterface::class)->sentExpiringBetween($from, $to);
    }

    private function user(): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test Owner',
            'email' => Uuid::uuid4()->toString().'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }

    /** A draft through Point 3.4's endpoint, then set to $status with $validUntil in place. */
    private function quotation(string $dealId, ?string $validUntil, string $status = 'sent'): string
    {
        $id = $this->postJson('/api/v1/quotations', [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'pricing_mode' => 'tax_exempt',
            'discount_percent' => '0',
            'lines' => [$this->lineBody(entered: '10', tax: null, margin: '20', quantity: '2')],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearer())->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update([
            'status' => $status,
            'quotation_date' => null,
            'valid_until' => $validUntil,
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'No reply' : null,
            'rejection_reason_code' => in_array($status, ['rejected', 'counter'], true) ? 'other' : null,
        ]);

        return $id;
    }

    private function currency(): void
    {
        DB::table('currencies')->insert([
            'id' => Uuid::uuid4()->toString(),
            'code' => 'EGP',
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

        DB::table('customers')->insert(['id' => $id, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);

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

    /** @return array<string, string> one login per test — the login limit counts every call */
    private function bearer(): array
    {
        if ($this->token === null) {
            $user = new User;
            $user->fill([
                'name' => 'Test Manager',
                'email' => 'manager@example.test',
                'password' => self::PASSWORD,
                'role_id' => Role::query()->where('slug', RoleName::Manager->value)->firstOrFail()->id,
                'is_active' => true,
                'is_hidden' => false,
            ]);
            $user->save();

            $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertStatus(201)->json('data.token');
            self::assertIsString($token);
            $this->token = $token;
        }

        return ['Authorization' => 'Bearer '.$this->token];
    }
}
