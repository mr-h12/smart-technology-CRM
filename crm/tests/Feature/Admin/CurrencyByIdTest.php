<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 7, Point 3.3 — the `currency_id ↔ CurrencyCode` resolution the create
 * needs: `find()` must carry the row id so a quotation can store `currency_id`
 * from a code, and `findById()` must map the reverse so a supplier line's
 * currency id becomes a `CurrencyCode` for `effectiveRate()`.
 */
final class CurrencyByIdTest extends TestCase
{
    use RefreshDatabase;

    private string $egpId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->egpId = Uuid::uuid4()->toString();
        DB::table('currencies')->insert([
            'id' => $this->egpId, 'code' => 'EGP', 'rounding_unit' => '1',
            'rounding_enabled' => true, 'is_base' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_that_find_by_code_carries_the_row_id(): void
    {
        $currency = $this->repository()->find(CurrencyCode::Egp);

        self::assertNotNull($currency);
        self::assertSame($this->egpId, $currency->id());
        self::assertSame(CurrencyCode::Egp, $currency->code());
    }

    public function test_that_find_by_id_returns_the_currency(): void
    {
        $currency = $this->repository()->findById($this->egpId);

        self::assertNotNull($currency);
        self::assertSame(CurrencyCode::Egp, $currency->code());
        self::assertSame($this->egpId, $currency->id());
        self::assertTrue($currency->rounding()->isEnabled());
        // NUMERIC(18,6): PostgreSQL hands the column back at its full scale.
        self::assertSame('1.000000', $currency->rounding()->unit());
    }

    public function test_that_an_unknown_id_is_null(): void
    {
        self::assertNull($this->repository()->findById(Uuid::uuid4()->toString()));
    }

    private function repository(): CurrencyRepositoryInterface
    {
        return $this->app->make(CurrencyRepositoryInterface::class);
    }
}
