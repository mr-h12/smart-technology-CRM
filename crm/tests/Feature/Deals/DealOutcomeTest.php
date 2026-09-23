<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Domain\Contracts\DealNotReadyToSend;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 10 · 1.2 — the deal write Quotations reaches through `DealsContract`
 * (`D-90`: send moves `supplier_quotation → quotation_sent`, rule a refuses a
 * deal before it; a rejection moves the deal to `lost` with its reason, rule b
 * leaves a deal with no `lost` edge untouched).
 */
final class DealOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $user = new User;
        $user->fill([
            'name' => 'Test Manager',
            'email' => 'manager@example.test',
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', RoleName::Manager->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();
        $this->actingAs($user);
        $this->actorId = (string) $user->id;
    }

    private function outcome(): DealOutcomeInterface
    {
        return $this->app->make(DealOutcomeInterface::class);
    }

    private function dealAt(string $status): string
    {
        DB::table('customers')->insert([
            'id' => $customerId = (string) Str::uuid7(),
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $id = (string) Str::uuid7(),
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'title' => 'Deal at '.$status,
            'owner_id' => $this->actorId,
            'status' => $status,
            'lost_reason' => $status === 'lost' ? 'Lost earlier' : null,
            'last_activity_at' => now(),
            'created_by' => $this->actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function statusOf(string $dealId): string
    {
        $status = DB::table('deals')->where('id', $dealId)->value('status');
        self::assertIsString($status);

        return $status;
    }

    private function audits(string $dealId): int
    {
        return DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->where('entity_id', $dealId)->count();
    }

    public function test_sent_moves_a_deal_at_supplier_quotation_to_quotation_sent(): void
    {
        $deal = $this->dealAt('supplier_quotation');

        $this->outcome()->quotationSent($deal, $this->actorId);

        self::assertSame('quotation_sent', $this->statusOf($deal));
        self::assertSame(1, $this->audits($deal));
    }

    /** @return array<string, array{string}> */
    public static function atQuotationSentOrLater(): array
    {
        return array_combine(
            $s = ['quotation_sent', 'negotiations', 'won', 'purchasing', 'delivery', 'delivery_complete', 'lost'],
            array_map(fn (string $x): array => [$x], $s),
        );
    }

    #[DataProvider('atQuotationSentOrLater')]
    public function test_sent_leaves_a_deal_at_quotation_sent_or_later_untouched(string $status): void
    {
        $deal = $this->dealAt($status);

        $this->outcome()->quotationSent($deal, $this->actorId);

        self::assertSame($status, $this->statusOf($deal));
        self::assertSame(0, $this->audits($deal));
    }

    /** @return array<string, array{string}> */
    public static function beforeSupplierQuotation(): array
    {
        return array_combine(
            $s = ['lead', 'contacted', 'waiting_customer_request', 'supplier_rfq'],
            array_map(fn (string $x): array => [$x], $s),
        );
    }

    #[DataProvider('beforeSupplierQuotation')]
    public function test_sent_refuses_a_deal_before_supplier_quotation(string $status): void
    {
        $deal = $this->dealAt($status);

        try {
            $this->outcome()->quotationSent($deal, $this->actorId);
            self::fail('D-90 rule a: a deal before supplier_quotation refuses the send.');
        } catch (DealNotReadyToSend $e) {
            self::assertSame($status, $e->status);
        }

        self::assertSame($status, $this->statusOf($deal));
        self::assertSame(0, $this->audits($deal));
    }

    /** @return array<string, array{string}> */
    public static function withALostEdge(): array
    {
        return ['quotation_sent' => ['quotation_sent'], 'negotiations' => ['negotiations']];
    }

    #[DataProvider('withALostEdge')]
    public function test_rejected_moves_quotation_sent_and_negotiations_to_lost_with_the_reason(string $status): void
    {
        $deal = $this->dealAt($status);

        self::assertTrue($this->outcome()->quotationRejected($deal, 'Price too high', $this->actorId));

        self::assertSame('lost', $this->statusOf($deal));
        self::assertSame('Price too high', DB::table('deals')->where('id', $deal)->value('lost_reason'));
        self::assertSame(1, $this->audits($deal));
    }

    /** @return array<string, array{string}> */
    public static function withoutALostEdge(): array
    {
        return array_combine(
            $s = ['supplier_quotation', 'won', 'purchasing', 'delivery', 'delivery_complete', 'lost'],
            array_map(fn (string $x): array => [$x], $s),
        );
    }

    #[DataProvider('withoutALostEdge')]
    public function test_rejected_leaves_a_deal_with_no_lost_edge_untouched_and_says_so(string $status): void
    {
        $deal = $this->dealAt($status);

        self::assertFalse($this->outcome()->quotationRejected($deal, 'Price too high', $this->actorId));

        self::assertSame($status, $this->statusOf($deal));
        self::assertSame(0, $this->audits($deal));
    }

    public function test_a_rolled_back_caller_leaves_the_deal_and_its_audit_untouched(): void
    {
        $sent = $this->dealAt('supplier_quotation');
        $rejected = $this->dealAt('quotation_sent');

        try {
            DB::transaction(function () use ($sent, $rejected): void {
                $this->outcome()->quotationSent($sent, $this->actorId);
                $this->outcome()->quotationRejected($rejected, 'Price too high', $this->actorId);

                throw new RuntimeException('the caller fails after both moves');
            });
        } catch (RuntimeException) {
        }

        self::assertSame('supplier_quotation', $this->statusOf($sent));
        self::assertSame('quotation_sent', $this->statusOf($rejected));
        self::assertSame(0, $this->audits($sent) + $this->audits($rejected));
    }
}
