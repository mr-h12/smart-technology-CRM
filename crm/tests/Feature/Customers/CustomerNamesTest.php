<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Customers\Infrastructure\EloquentCustomerNames;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-07 · 1.2 — the name a quotation row carries for its customer (`D-83`).
 *
 * The four mechanisms `D-83` measured are written here as the reads that
 * must still answer: an archived customer (mechanism 3, the directory's
 * unconditional `is_archived` filter), one owned by somebody else (mechanism
 * 4, the `§3.3` scope split — the port takes no scope), and one soft-deleted
 * (the owner's ruling 2026-09-21: the name shows even then). N ids are one
 * query, never N.
 */
final class CustomerNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_contract_resolves_to_the_eloquent_reader(): void
    {
        self::assertInstanceOf(
            EloquentCustomerNames::class,
            $this->app->make(CustomerNamesInterface::class),
        );
    }

    public function test_that_an_archived_customer_still_has_a_name(): void
    {
        $id = $this->customer('Nile Trading', ['is_archived' => true]);

        self::assertSame([$id => 'Nile Trading'], $this->reader()->namesOf([$id]));
    }

    public function test_that_a_customer_owned_by_somebody_else_still_has_a_name(): void
    {
        $id = $this->customer('Delta Steel', ['sales_owner_id' => User::factory()->create()->id]);

        self::assertSame([$id => 'Delta Steel'], $this->reader()->namesOf([$id]));
    }

    public function test_that_a_soft_deleted_customer_still_has_a_name(): void
    {
        $id = $this->customer('Cairo Cables', ['deleted_at' => now()]);

        self::assertSame([$id => 'Cairo Cables'], $this->reader()->namesOf([$id]));
    }

    public function test_that_n_ids_are_one_query(): void
    {
        $ids = [$this->customer('A'), $this->customer('B'), $this->customer('C')];

        DB::enableQueryLog();
        $names = $this->reader()->namesOf($ids);

        self::assertCount(1, DB::getQueryLog());
        self::assertSame(array_combine($ids, ['A', 'B', 'C']), $names);
    }

    public function test_that_an_empty_list_is_no_query(): void
    {
        DB::enableQueryLog();

        self::assertSame([], $this->reader()->namesOf([]));
        self::assertCount(0, DB::getQueryLog());
    }

    /** An id no customer carries has no entry — the caller keeps its identifier fallback. */
    public function test_that_an_unknown_id_has_no_entry(): void
    {
        self::assertSame([], $this->reader()->namesOf([Uuid::uuid4()->toString()]));
    }

    private function reader(): CustomerNamesInterface
    {
        return $this->app->make(CustomerNamesInterface::class);
    }

    /** @param  array<string, mixed>  $overrides */
    private function customer(string $name, array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }
}
