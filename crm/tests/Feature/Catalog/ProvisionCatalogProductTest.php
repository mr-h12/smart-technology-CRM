<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Contracts\CatalogProductProvisionerInterface;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * Module 6, Step 3 Point 3.1 — `D-22`'s automatic product add, as a contract
 * Catalog publishes and another module may hold.
 *
 * ── Why this lives in Catalog and not in SupplierQuotations ────────────────
 *
 * `D-22` adds a product **to the catalog**, and `deptrac.modules.yaml` grants
 * `SupplierQuotations` exactly three layers — `Framework`, `SharedContracts`
 * and `AuditContract`. It cannot see Catalog at all, which is `CLAUDE.md`'s
 * rule made mechanical: cross-module work goes through interfaces or events,
 * never another module's models. So Catalog publishes one method, Module 6
 * holds the interface, and the crossing is a line in a config rather than an
 * import nobody reviewed.
 *
 * ── One method, taking and returning strings ───────────────────────────────
 *
 * Deliberate: the collector that exposes this interface to other modules is a
 * `classLike` on this one class — `Precision`'s precedent rather than
 * `AuditContract`'s directory. A signature carrying `CatalogItemSummary` would
 * drag Catalog's `Domain\Listing` across the boundary with it, so the boundary
 * is a name in and an id out, and nothing of Catalog's shape travels.
 *
 * ── Reuse on an exact, case-insensitive name — the owner's ruling ──────────
 *
 * `D-22` says a new product is added automatically and without review; it does
 * not say whether a name already present is the same product. The owner ruled
 * on 2026-09-03 that it is reused. `catalog_items.name` is nullable and carries
 * no unique index, so this is a convention the lookup enforces, not the
 * database — recorded in `CHECKLIST.md`.
 */
final class ProvisionCatalogProductTest extends TestCase
{
    use RefreshDatabase;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $actorId = User::factory()->create()->getKey();
        self::assertIsString($actorId);
        $this->actorId = $actorId;
    }

    public function test_that_an_unknown_name_becomes_a_catalog_product(): void
    {
        $id = $this->provision('Split unit 1.5HP');

        $row = DB::table('catalog_items')->where('id', $id)->first();

        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('product', $row->kind, 'D-22 adds a product, and §7.3 has two kinds.');
        self::assertSame('Split unit 1.5HP', $row->name);
        self::assertTrue((bool) $row->is_active, 'A product added for an offer is usable on the next one.');
    }

    /** `D-21`: the catalog is descriptive only, so nothing priced arrives with it. */
    public function test_that_the_added_product_carries_no_price_bearing_field(): void
    {
        $id = $this->provision('Split unit 1.5HP');

        $row = DB::table('catalog_items')->where('id', $id)->first();

        self::assertInstanceOf(stdClass::class, $row);
        self::assertNull($row->product_code);
        self::assertNull($row->category);
        self::assertNull($row->unit);
    }

    /** `AUD-01` names create explicitly, and it comes from Catalog's own writer. */
    public function test_that_the_add_is_written_to_the_audit_log(): void
    {
        $id = $this->provision('Split unit 1.5HP');

        self::assertSame(1, DB::table('audit_log')
            ->where('event', 'CATALOG_ITEM_CREATED')
            ->where('entity_id', $id)
            ->count());
    }

    /** The owner's ruling: the same name is the same product. */
    public function test_that_a_repeated_name_reuses_the_existing_product(): void
    {
        $first = $this->provision('Split unit 1.5HP');
        $second = $this->provision('Split unit 1.5HP');

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('catalog_items')->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'CATALOG_ITEM_CREATED')->count());
    }

    public function test_that_the_match_ignores_case(): void
    {
        $first = $this->provision('Split unit 1.5HP');
        $second = $this->provision('SPLIT UNIT 1.5HP');

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('catalog_items')->count());
    }

    /** §7.3 has two kinds, and a service is not the product this asked for. */
    public function test_that_a_service_of_the_same_name_is_not_reused(): void
    {
        DB::table('catalog_items')->insert([
            'id' => Uuid::uuid4()->toString(),
            'kind' => 'service',
            'name' => 'Installation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->provision('Installation');

        $row = DB::table('catalog_items')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('product', $row->kind);
        self::assertSame(2, DB::table('catalog_items')->count());
    }

    /** `DB-01`: an archived row is out of the live set, so it cannot be matched. */
    public function test_that_a_soft_deleted_product_is_not_reused(): void
    {
        DB::table('catalog_items')->insert([
            'id' => Uuid::uuid4()->toString(),
            'kind' => 'product',
            'name' => 'Split unit 1.5HP',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->provision('Split unit 1.5HP');

        self::assertSame(2, DB::table('catalog_items')->count());
        self::assertNotNull(DB::table('catalog_items')->where('id', $id)->whereNull('deleted_at')->first());
    }

    /**
     * A **deactivated** product is reused rather than duplicated. `is_active` is
     * not `DB-01`'s archive: §10.4 (`D-37`) gives a deactivated item a
     * documented life on an open quotation, so referencing one is a handled
     * situation. Creating a second row with the same name would not be.
     */
    public function test_that_a_deactivated_product_is_reused_rather_than_duplicated(): void
    {
        $existing = Uuid::uuid4()->toString();
        DB::table('catalog_items')->insert([
            'id' => $existing,
            'kind' => 'product',
            'name' => 'Split unit 1.5HP',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame($existing, $this->provision('Split unit 1.5HP'));
        self::assertSame(1, DB::table('catalog_items')->count());
    }

    private function provision(string $name): string
    {
        return $this->app->make(CatalogProductProvisionerInterface::class)->productIdFor($name, $this->actorId);
    }
}
