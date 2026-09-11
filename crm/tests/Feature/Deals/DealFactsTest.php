<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Deals\Infrastructure\EloquentDealFacts;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7's one read of a deal (Point 3.4): who owns it and whom it is for,
 * so a scoped `quotation.create` can be filed against `deals.owner_id` and a
 * quotation's `customer_id` can be held to its deal's.
 */
final class DealFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_contract_resolves_to_the_eloquent_reader(): void
    {
        self::assertInstanceOf(EloquentDealFacts::class, $this->app->make(DealFactsInterface::class));
    }

    public function test_that_an_owned_deal_names_its_owner_and_customer(): void
    {
        $ownerId = User::factory()->create()->getKey();
        self::assertIsString($ownerId);
        $customerId = $this->customer();

        $facts = $this->reader()->factsOf($this->deal($customerId, $ownerId));

        self::assertNotNull($facts);
        self::assertSame($ownerId, $facts->ownerId);
        self::assertSame($customerId, $facts->customerId);
    }

    /** `deals.owner_id` is nullable; an unowned deal is a fact, not an absence. */
    public function test_that_an_unowned_deal_has_a_null_owner_and_still_a_customer(): void
    {
        $customerId = $this->customer();

        $facts = $this->reader()->factsOf($this->deal($customerId, null));

        self::assertNotNull($facts);
        self::assertNull($facts->ownerId);
        self::assertSame($customerId, $facts->customerId);
    }

    public function test_that_a_soft_deleted_deal_is_absent(): void
    {
        $dealId = $this->deal($this->customer(), null);
        DB::table('deals')->where('id', $dealId)->update(['deleted_at' => now()]);

        self::assertNull($this->reader()->factsOf($dealId));
    }

    public function test_that_an_unknown_deal_is_absent(): void
    {
        self::assertNull($this->reader()->factsOf(Uuid::uuid4()->toString()));
    }

    private function reader(): EloquentDealFacts
    {
        $reader = $this->app->make(DealFactsInterface::class);
        self::assertInstanceOf(EloquentDealFacts::class, $reader);

        return $reader;
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

    private function deal(string $customerId, ?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
