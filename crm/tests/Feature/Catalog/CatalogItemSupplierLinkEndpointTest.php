<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `D-86` (F-10 · 1.7) — the item↔supplier link, edited by hand.
 *
 * The save request takes the **full set** of supplier ids and replaces it;
 * `supplier_ids` absent means "leave the links alone", `[]` means "unlink
 * all". Every change is audited per link row, old and new (`AUD-02`):
 * `CATALOG_ITEM_SUPPLIER_LINKED` (the import's event, #188) and
 * `CATALOG_ITEM_SUPPLIER_UNLINKED`, which soft-deletes the row (`DB-01`) so
 * the partial unique index lets the same pair be linked again later.
 *
 * Owner's rulings: a deactivated supplier may be linked by hand, as the
 * import allows (A2; reconfirmed 2026-09-22); only an unknown or soft-deleted
 * id is a 422. Names come from the lookup Suppliers publishes, never its table.
 */
final class CatalogItemSupplierLinkEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/catalog-items';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ────────────────────────────────────────────────────────────── the link

    public function test_that_a_patch_with_supplier_ids_links_the_item_and_audits_it(): void
    {
        $itemId = $this->created();
        $supplierId = $this->supplier('Acme Trading');

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$supplierId]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.suppliers')
            ->assertJsonPath('data.suppliers.0.id', $supplierId)
            ->assertJsonPath('data.suppliers.0.name', 'Acme Trading');

        $link = DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->where('supplier_id', $supplierId)->first();
        self::assertNotNull($link);
        self::assertNull($link->deleted_at);
        self::assertSame($this->userWith(RoleName::Manager)->id, $link->created_by);

        $audit = DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_LINKED')->first();
        self::assertNotNull($audit);
        self::assertSame('catalog_item_supplier', $audit->entity_type);
        self::assertSame($link->id, $audit->entity_id);
        self::assertNull($audit->old_values);
        self::assertIsString($audit->new_values);
        // assertEquals, not assertSame: jsonb stores its keys in its own order.
        self::assertEquals(['catalog_item_id' => $itemId, 'supplier_id' => $supplierId], json_decode($audit->new_values, true));
    }

    public function test_that_a_post_with_supplier_ids_links_on_creation(): void
    {
        $supplierId = $this->supplier('Acme Trading');

        $response = $this->postJson(self::ENDPOINT, self::product() + ['supplier_ids' => [$supplierId]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.suppliers.0.id', $supplierId);

        $itemId = $response->json('data.id');

        self::assertSame(1, DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->whereNull('deleted_at')->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_LINKED')->count());
    }

    public function test_that_a_removed_id_soft_deletes_the_link_and_audits_it(): void
    {
        $itemId = $this->created();
        $kept = $this->supplier('Kept Supplier');
        $dropped = $this->supplier('Dropped Supplier');
        $this->linked($itemId, [$kept, $dropped]);
        $linkedBefore = $this->audited('CATALOG_ITEM_SUPPLIER_LINKED');

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$kept]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.suppliers')
            ->assertJsonPath('data.suppliers.0.id', $kept);

        $link = DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->where('supplier_id', $dropped)->first();
        self::assertNotNull($link, 'DB-01: the link row is retired, never deleted.');
        self::assertNotNull($link->deleted_at);
        self::assertSame($this->userWith(RoleName::Manager)->id, $link->updated_by);

        self::assertSame($linkedBefore, $this->audited('CATALOG_ITEM_SUPPLIER_LINKED'), 'The kept link is not re-audited.');

        $audit = DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_UNLINKED')->first();
        self::assertNotNull($audit);
        self::assertSame('catalog_item_supplier', $audit->entity_type);
        self::assertSame($link->id, $audit->entity_id);
        self::assertIsString($audit->old_values);
        self::assertEquals(['catalog_item_id' => $itemId, 'supplier_id' => $dropped], json_decode($audit->old_values, true));
        self::assertNull($audit->new_values);
    }

    public function test_that_an_unchanged_set_writes_no_link_audit(): void
    {
        $itemId = $this->created();
        $a = $this->supplier('Supplier A');
        $b = $this->supplier('Supplier B');
        $this->linked($itemId, [$a, $b]);
        $before = $this->audited('CATALOG_ITEM_SUPPLIER_%');

        // The same set in another order is the same set.
        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$b, $a]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.suppliers');

        self::assertSame($before, $this->audited('CATALOG_ITEM_SUPPLIER_%'), 'An unchanged set writes no link audit.');
        self::assertSame(2, DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->whereNull('deleted_at')->count());
    }

    public function test_that_an_absent_field_leaves_the_links_alone(): void
    {
        $itemId = $this->created();
        $this->linked($itemId, [$this->supplier('Supplier A')]);

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['category' => 'Wiring'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.suppliers');

        self::assertSame(0, DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_UNLINKED')->count());
    }

    public function test_that_an_empty_set_unlinks_every_supplier(): void
    {
        $itemId = $this->created();
        $this->linked($itemId, [$this->supplier('Supplier A'), $this->supplier('Supplier B')]);

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => []], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.suppliers');

        self::assertSame(0, DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->whereNull('deleted_at')->count());
        self::assertSame(2, DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_UNLINKED')->count());
    }

    /** The unique index is partial (`WHERE deleted_at IS NULL`), so a retired pair may return. */
    public function test_that_an_unlinked_supplier_can_be_linked_again(): void
    {
        $itemId = $this->created();
        $supplierId = $this->supplier('Supplier A');
        $this->linked($itemId, [$supplierId]);
        $this->linked($itemId, []);

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$supplierId]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.0.id', $supplierId);

        $rows = DB::table('catalog_item_suppliers')->where('catalog_item_id', $itemId)->where('supplier_id', $supplierId);
        self::assertSame(2, $rows->count());
        self::assertSame(1, $rows->whereNull('deleted_at')->count());
    }

    // ─────────────────────────────────────────────────────────── the payload

    public function test_that_show_lists_the_live_links_with_their_names(): void
    {
        $itemId = $this->created();
        $live = $this->supplier('Live Supplier');
        $retired = $this->supplier('Retired Supplier');
        $this->linked($itemId, [$live, $retired]);
        $this->linked($itemId, [$live]);

        $this->getJson(self::ENDPOINT.'/'.$itemId, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.suppliers')
            ->assertJsonPath('data.suppliers.0.id', $live)
            ->assertJsonPath('data.suppliers.0.name', 'Live Supplier');
    }

    // ────────────────────────────────────────────────────────── the refusals

    public function test_that_an_unknown_id_is_a_422_and_nothing_is_written(): void
    {
        $itemId = $this->created();
        $known = $this->supplier('Supplier A');

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$known, (string) Str::uuid7()]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'supplier_ids');

        self::assertSame(0, DB::table('catalog_item_suppliers')->count(), 'DB-11: the refused request writes nothing.');
        self::assertSame(0, DB::table('audit_log')->where('event', 'CATALOG_ITEM_SUPPLIER_LINKED')->count());
    }

    public function test_that_a_soft_deleted_supplier_is_a_422(): void
    {
        $itemId = $this->created();
        $gone = $this->supplier('Gone Supplier', ['deleted_at' => now()]);

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$gone]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'supplier_ids');
    }

    /** Owner, 2026-09-22: as the import (A2), a deactivated supplier still counts. */
    public function test_that_a_deactivated_supplier_can_be_linked(): void
    {
        $itemId = $this->created();
        $inactive = $this->supplier('Dormant Supplier', ['is_active' => false]);

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$inactive]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.0.id', $inactive);
    }

    public function test_that_a_malformed_id_is_a_422(): void
    {
        $itemId = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => ['not-a-uuid']], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'supplier_ids.0');
    }

    public function test_that_a_repeated_id_is_a_422(): void
    {
        $itemId = $this->created();
        $supplierId = $this->supplier('Supplier A');

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$supplierId, $supplierId]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'supplier_ids.0');
    }

    /** §3.7: the CEO reads the catalog and never writes it — the one role without `catalog.manage`. */
    public function test_that_a_role_without_catalog_manage_is_refused(): void
    {
        $itemId = $this->created();
        $supplierId = $this->supplier('Supplier A');

        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => [$supplierId]], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);

        self::assertSame(0, DB::table('catalog_item_suppliers')->count());
    }

    /** No login first: a logged-in guard outlives the request inside one test. */
    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Str::uuid7()->toString(), ['supplier_ids' => [$this->supplier('Supplier A')]])
            ->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────── fixtures

    /** @return array<string, mixed> */
    private static function product(): array
    {
        return ['kind' => 'product', 'name' => 'Copper Cable', 'unit' => 'piece', 'company' => 'Alpha Co'];
    }

    private function created(): string
    {
        $id = $this->postJson(self::ENDPOINT, self::product(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        return $id;
    }

    /** @param  list<string>  $supplierIds */
    private function linked(string $itemId, array $supplierIds): void
    {
        $this->patchJson(self::ENDPOINT.'/'.$itemId, ['supplier_ids' => $supplierIds], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
    }

    /** `audit_log` is append-only (`AUD-03`), so a test counts rows rather than clearing them. */
    private function audited(string $eventPattern): int
    {
        return DB::table('audit_log')->where('event', 'like', $eventPattern)->count();
    }

    /** @param  array<string, mixed>  $columns */
    private function supplier(string $name, array $columns = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('suppliers')->insert($columns + ['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
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
