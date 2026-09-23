<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Application\Writing\ExpireQuotations;
use App\Modules\Quotations\Presentation\ExpireQuotationsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 10 · 2.1 — `J-01 expire_quotations` (§15, §6.1 "Expired · Passed
 * `valid_until` with no reply · System (job J-01)"). `sent` with `valid_until`
 * before today in `locale.timezone` ⇒ `expired`, audited with the system actor,
 * idempotent (§15.1). An unset or unknown time zone falls back to
 * `app.timezone` (owner, 2026-09-23, Q-A). No deal move (Q2). The startup
 * catch-up (`D-55`, `ST-05`) is `quotations:expire`, dispatched onto
 * `maintenance` like the daily entry (Q-B).
 */
final class ExpireQuotationsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private ?string $token = null;

    private string $customerId;

    private string $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency();
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine();

        // 2026-09-23 22:30 UTC is 2026-09-24 01:30 in Cairo (UTC+3 in September).
        Carbon::setTestNow('2026-09-23 22:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────── the move

    public function test_a_sent_quotation_past_valid_until_becomes_expired_with_a_system_audit(): void
    {
        $deal = $this->deal();
        $id = $this->quotation($deal, '2026-09-22');
        $token = $this->tokenOf($id);

        self::assertSame(1, $this->expire());

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('expired', $row->status);
        self::assertSame($token + 1, $this->tokenOf($id));
        self::assertNull($row->updated_by);

        self::assertSame(1, DB::table('audit_log')->where('event', 'QUOTATION_EXPIRED')->count());
        $audit = DB::table('audit_log')->where('event', 'QUOTATION_EXPIRED')->where('entity_type', 'quotation')->where('entity_id', $id)->first();
        self::assertInstanceOf(stdClass::class, $audit);
        self::assertNull($audit->user_id);
        self::assertIsString($audit->old_values);
        self::assertIsString($audit->new_values);
        $old = json_decode($audit->old_values, true);
        $new = json_decode($audit->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('expired', $new['status'] ?? null);

        // Q2: the deal does not move.
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
    }

    public function test_a_quotation_valid_until_today_stays_sent(): void
    {
        // Today in UTC (the fallback) is 2026-09-23.
        $id = $this->quotation($this->deal(), '2026-09-23');

        self::assertSame(0, $this->expire());
        self::assertSame('sent', $this->statusOf('quotations', $id));
    }

    public function test_a_quotation_with_no_valid_until_stays_sent(): void
    {
        $id = $this->quotation($this->deal(), null);

        self::assertSame(0, $this->expire());
        self::assertSame('sent', $this->statusOf('quotations', $id));
    }

    /** @return array<string, array{string}> */
    public static function notSent(): array
    {
        return [
            'draft' => ['draft'], 'pending' => ['pending'], 'approved' => ['approved'],
            'accepted' => ['accepted'], 'partial' => ['partial'], 'counter' => ['counter'],
            'rejected' => ['rejected'], 'expired' => ['expired'],
        ];
    }

    #[DataProvider('notSent')]
    public function test_only_sent_quotations_expire(string $status): void
    {
        $id = $this->quotation($this->deal(), '2026-09-01', $status);
        $token = $this->tokenOf($id);

        self::assertSame(0, $this->expire());
        self::assertSame($status, $this->statusOf('quotations', $id));
        self::assertSame($token, $this->tokenOf($id));
    }

    public function test_a_soft_deleted_sent_quotation_is_untouched(): void
    {
        $id = $this->quotation($this->deal(), '2026-09-01');
        DB::table('quotations')->where('id', $id)->update(['deleted_at' => now()]);

        self::assertSame(0, $this->expire());
        self::assertSame('sent', $this->statusOf('quotations', $id));
    }

    // ───────────────────────────────────────────────────────────── the clock

    public function test_today_is_read_in_locale_timezone(): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::Timezone, 'Africa/Cairo');
        $id = $this->quotation($this->deal(), '2026-09-23');

        // Already 2026-09-24 in Cairo: valid until yesterday.
        self::assertSame(1, $this->expire());
        self::assertSame('expired', $this->statusOf('quotations', $id));
    }

    /** @return array<string, array{string}> */
    public static function unusableTimezones(): array
    {
        return ['a word' => ['Mars/Olympus'], 'blank' => ['  ']];
    }

    #[DataProvider('unusableTimezones')]
    public function test_an_unknown_timezone_falls_back_to_utc(string $value): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::Timezone, $value);
        $id = $this->quotation($this->deal(), '2026-09-23');

        self::assertSame(0, $this->expire());
        self::assertSame('sent', $this->statusOf('quotations', $id));
    }

    // ────────────────────────────────────────────────────────── idempotency

    public function test_running_twice_is_idempotent(): void
    {
        $id = $this->quotation($this->deal(), '2026-09-01');

        self::assertSame(1, $this->expire());
        $token = $this->tokenOf($id);

        self::assertSame(0, $this->expire());
        self::assertSame($token, $this->tokenOf($id));
        self::assertSame(1, DB::table('audit_log')->where('event', 'QUOTATION_EXPIRED')->count());
    }

    /** DB-12: the bumped `version_token` makes an etag read before the expiry stale. */
    public function test_the_pre_expiry_etag_is_refused(): void
    {
        $id = $this->quotation($this->deal(), '2026-09-01');
        $etag = $this->getJson('/api/v1/quotations/'.$id, $this->bearer())->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        $this->expire();

        $this->patchJson('/api/v1/quotations/'.$id.'/respond', ['response' => 'partial'], [...$this->bearer(), 'If-Match' => $etag])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');
    }

    // ───────────────────────────────────────────────── the job and the schedule

    public function test_the_job_expires_through_the_container(): void
    {
        $id = $this->quotation($this->deal(), '2026-09-01');

        $this->app->call([new ExpireQuotationsJob, 'handle']);

        self::assertSame('expired', $this->statusOf('quotations', $id));
    }

    public function test_the_job_is_scheduled_daily_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), ExpireQuotationsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-01 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, ExpireQuotationsJob::class);
    }

    /** `D-55` / `ST-05`: the `scheduler` service runs this once on start. */
    public function test_the_catch_up_command_dispatches_onto_maintenance(): void
    {
        Queue::fake();

        self::assertSame(0, Artisan::call('quotations:expire'));

        Queue::assertPushedOn(QueueName::Maintenance->value, ExpireQuotationsJob::class);
    }

    /** Not a silent success: `&&` in the `scheduler` service stops on it. */
    public function test_the_catch_up_fails_loudly_when_j01_is_not_scheduled(): void
    {
        Queue::fake();
        $this->app->instance(Schedule::class, new Schedule);

        self::assertSame(1, Artisan::call('quotations:expire'));

        Queue::assertNothingPushed();
    }

    // ──────────────────────────────────────────────────────────────── fixtures

    /**
     * As a worker runs it: with no request. The HTTP fixtures above leave
     * theirs bound, and `RequestAuditContext` would name its user.
     */
    private function expire(): int
    {
        $this->app->instance('request', Request::create('http://localhost'));

        return $this->app->make(ExpireQuotations::class)->run();
    }

    /** A draft through Point 3.4's endpoint, then set to $status with $validUntil in place. */
    private function quotation(string $dealId, ?string $validUntil, string $status = 'sent'): string
    {
        $id = $this->postJson('/api/v1/quotations', [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearer())->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update([
            'status' => $status,
            'quotation_date' => null,
            'valid_until' => $validUntil,
            // `quotations_reason_required_when_rejected_or_counter`.
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'No reply' : null,
        ]);

        return $id;
    }

    private function tokenOf(string $id): int
    {
        $token = DB::table('quotations')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return $token;
    }

    private function statusOf(string $table, string $id): string
    {
        $status = DB::table($table)->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
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

    private function deal(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'status' => 'quotation_sent',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** §4.1's chain in EGP; returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();
        $egp = DB::table('currencies')->where('code', 'EGP')->value('id');

        DB::table('suppliers')->insert(['id' => $supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
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

    /** @return array<string, string> one login per test — the login limit counts every call */
    private function bearer(): array
    {
        if ($this->token === null) {
            $role = Role::query()->where('slug', RoleName::Manager->value)->firstOrFail();
            $user = new User;
            $user->fill([
                'name' => 'Test Manager',
                'email' => 'manager@example.test',
                'password' => self::PASSWORD,
                'role_id' => $role->id,
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
