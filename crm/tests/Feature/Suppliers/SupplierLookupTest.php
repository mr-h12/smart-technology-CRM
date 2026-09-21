<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-10 · 1.4 — the lookup Suppliers publishes so Catalog's import (`D-86`) can
 * turn a `supplier` cell into ids without reading Suppliers' tables. Ruling A2:
 * trimmed, any case, active and deactivated alike. The caller decides what 0 or
 * 2+ ids mean; the lookup only reports them.
 */
final class SupplierLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_supplier_matches_an_unknown_name(): void
    {
        $this->supplier('Acme');

        self::assertSame([], $this->lookup()->idsNamed('Globex'));
    }

    public function test_one_supplier_matches_its_name(): void
    {
        $id = $this->supplier('Acme');
        $this->supplier('Acme Trading');

        self::assertSame([$id], $this->lookup()->idsNamed('Acme'));
    }

    public function test_two_suppliers_sharing_a_name_both_match(): void
    {
        $a = $this->supplier('Acme');
        $b = $this->supplier('acme');

        $ids = $this->lookup()->idsNamed('Acme');
        sort($ids);
        $expected = [$a, $b];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function test_the_name_is_trimmed_and_case_folded(): void
    {
        $id = $this->supplier(' Acme Trading ');

        self::assertSame([$id], $this->lookup()->idsNamed("  aCME trading\t"));
    }

    public function test_a_deactivated_supplier_is_found(): void
    {
        $id = $this->supplier('Acme', ['is_active' => false]);

        self::assertSame([$id], $this->lookup()->idsNamed('Acme'));
    }

    public function test_a_soft_deleted_supplier_is_not_found(): void
    {
        $id = $this->supplier('Acme', ['deleted_at' => now()]);

        self::assertSame([], $this->lookup()->idsNamed('Acme'));
        self::assertSame([], $this->lookup()->namesFor([$id]));
    }

    public function test_a_blank_name_matches_nothing(): void
    {
        $this->supplier('Acme');

        self::assertSame([], $this->lookup()->idsNamed('   '));
    }

    public function test_names_are_returned_for_ids(): void
    {
        $a = $this->supplier('Acme');
        $b = $this->supplier('Globex', ['is_active' => false]);
        $this->supplier('Initech');

        $names = $this->lookup()->namesFor([$a, $b, (string) Str::uuid7()]);
        ksort($names);
        $expected = [$a => 'Acme', $b => 'Globex'];
        ksort($expected);

        self::assertSame($expected, $names);
        self::assertSame([], $this->lookup()->namesFor([]));
    }

    private function lookup(): SupplierLookupInterface
    {
        return $this->app->make(SupplierLookupInterface::class);
    }

    /** @param array<string, mixed> $columns */
    private function supplier(string $name, array $columns = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('suppliers')->insert($columns + [
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
