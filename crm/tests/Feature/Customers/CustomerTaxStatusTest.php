<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface;
use App\Modules\Customers\Infrastructure\EloquentCustomerTaxStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 3.3 — the one fact a quotation reads about a customer to
 * derive its tax line (`D-63`).
 */
final class CustomerTaxStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_contract_resolves_to_the_eloquent_reader(): void
    {
        self::assertInstanceOf(
            EloquentCustomerTaxStatus::class,
            $this->app->make(CustomerTaxStatusInterface::class),
        );
    }

    public function test_that_an_exempt_customer_is_exempt(): void
    {
        self::assertTrue($this->reader()->isExempt($this->customer(true)));
    }

    public function test_that_a_non_exempt_customer_is_not(): void
    {
        self::assertFalse($this->reader()->isExempt($this->customer(false)));
    }

    /** An absent (or soft-deleted) customer resolves to the taxed direction, never a throw. */
    public function test_that_an_absent_customer_is_not_exempt(): void
    {
        self::assertFalse($this->reader()->isExempt(Uuid::uuid4()->toString()));
    }

    private function reader(): EloquentCustomerTaxStatus
    {
        $reader = $this->app->make(CustomerTaxStatusInterface::class);
        self::assertInstanceOf(EloquentCustomerTaxStatus::class, $reader);

        return $reader;
    }

    private function customer(bool $exempt): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'is_tax_exempt' => $exempt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
