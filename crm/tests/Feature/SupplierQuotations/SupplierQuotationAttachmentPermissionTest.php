<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\SupplierQuotations\Application\Access\SupplierQuotationAttachmentPermission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 6, Point 5.1 — `D-38` for the **second** of the four parents.
 *
 * ── The decision `DealAttachmentPermission` deferred, taken here ───────────
 *
 * Module 5's class says it outright: "A composite across all four parents was
 * not built here: only one has a real implementation, and a registry for a set
 * of one is the abstraction `CLAUDE.md`'s 'do not introduce speculative
 * abstractions' already refuses. Whoever builds the second parent's permission
 * decides then whether this class grows a `match` or a composite replaces it."
 *
 * The `match` is refused: growing Deals' class to answer for supplier
 * quotations would put Module 6's rule inside Module 5, which is the
 * cross-module reach `CLAUDE.md` forbids in stronger terms than it forbids a
 * registry. So a composite in `Storage` dispatches by `AttachmentParent`, each
 * module owns its own answer, and an unmapped parent is refused — the same
 * deny-by-default `DenyAllAttachmentPermission` was bound for.
 *
 * ── Why the download route needs this at all ──────────────────────────────
 *
 * `GET /api/v1/files/{file}/download` carries `auth` and nothing else — it is
 * parent-agnostic by design, so the *only* thing standing between a caller and
 * somebody else's attachment is `mayView`. There is no middleware to fall back
 * on here.
 *
 * ── No scope, unlike Deals ────────────────────────────────────────────────
 *
 * `DealAttachmentPermission` resolves a `DealRowScope` because §3.4 gives deals
 * five different reaches. §3.6 gives supplier quotations one — `All`, under "a
 * shared screen — not restricted by ownership" — so the question is only
 * whether the caller holds `supplier_quotation.view` at all, and then whether
 * the offer is still there (`DB-01`).
 */
final class SupplierQuotationAttachmentPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * §3.6's `view` row — the six roles it grants `All` to.
     *
     * @return array<string, array{RoleName}>
     */
    public static function readers(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
        ];
    }

    // ─────────────────────────────────────────── the module's own answer

    #[DataProvider('readers')]
    public function test_that_it_grants_every_role_section_3_6_gives_view_to(RoleName $role): void
    {
        $actorId = $this->userWith($role);
        $offerId = $this->offer();

        self::assertTrue(
            $this->permission()->mayView(new AttachmentLink(AttachmentParent::SupplierQuotation, $offerId), $actorId),
        );
    }

    /**
     * The CEO holds `view` as `All` and no `upload_attachment` grant at all
     * (`PermissionMatrix` §3.6). Reading somebody else's attachment and being
     * unable to add one is the documented pair, and it is the same asymmetry
     * Points 2.2–2.3 proved on the offer itself.
     */
    public function test_that_the_ceo_who_cannot_upload_may_still_view(): void
    {
        $actorId = $this->userWith(RoleName::Ceo);

        self::assertTrue(
            $this->permission()->mayView(
                new AttachmentLink(AttachmentParent::SupplierQuotation, $this->offer()),
                $actorId,
            ),
        );
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column. */
    public function test_that_it_refuses_the_outdoor_supervisor(): void
    {
        $actorId = $this->userWith(RoleName::OutdoorSupervisor);

        self::assertFalse(
            $this->permission()->mayView(
                new AttachmentLink(AttachmentParent::SupplierQuotation, $this->offer()),
                $actorId,
            ),
        );
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_view_grant_refuses_a_role_that_had_it(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $offerId = $this->offer();

        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'view')
            ->delete();

        self::assertFalse(
            $this->permission()->mayView(new AttachmentLink(AttachmentParent::SupplierQuotation, $offerId), $actorId),
        );
    }

    public function test_that_it_refuses_an_offer_that_does_not_exist(): void
    {
        $actorId = $this->userWith(RoleName::Manager);

        self::assertFalse(
            $this->permission()->mayView(
                new AttachmentLink(AttachmentParent::SupplierQuotation, (string) Str::uuid7()),
                $actorId,
            ),
        );
    }

    /**
     * `DB-01` — a soft-deleted offer is not there, so neither is its
     * attachment. `OpenAPI §5.1`'s "does not exist **or** is not visible" is
     * one answer, and the download endpoint gives the same 404 for both.
     */
    public function test_that_it_refuses_an_offer_that_was_soft_deleted(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $offerId = $this->offer();

        DB::table('supplier_quotations')->where('id', $offerId)->update(['deleted_at' => now()]);

        self::assertFalse(
            $this->permission()->mayView(new AttachmentLink(AttachmentParent::SupplierQuotation, $offerId), $actorId),
        );
    }

    /**
     * Behaviour, not a branch. This class was written with a
     * `$link->parent !== SupplierQuotation` guard mirroring Deals', and the
     * probe for it **passed with the guard deleted** — a foreign parent's id is
     * not an offer's id, so `find()` already returns `null`. The guard went;
     * this assertion stayed, because the refusal is what the class owes and it
     * must keep holding however it is reached.
     */
    public function test_that_it_refuses_every_parent_that_is_not_its_own(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $permission = $this->permission();

        foreach ([AttachmentParent::Deal, AttachmentParent::PurchaseOrder, AttachmentParent::Report] as $parent) {
            self::assertFalse(
                $permission->mayView(new AttachmentLink($parent, (string) Str::uuid7()), $actorId),
                "Expected {$parent->value} to be refused by the supplier quotation rule.",
            );
        }
    }

    // ──────────────────────────────────────── the composite that is bound

    /**
     * What the container hands `DownloadFile` must now answer for **both**
     * parents. Before this point the binding was `DealAttachmentPermission`
     * alone, so a supplier quotation's attachment uploaded and stored fine and
     * then downloaded as a 404.
     */
    public function test_that_the_bound_permission_answers_for_a_supplier_quotation(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $offerId = $this->offer();

        $bound = $this->app->make(AttachmentPermissionInterface::class);

        self::assertTrue($bound->mayView(new AttachmentLink(AttachmentParent::SupplierQuotation, $offerId), $actorId));
    }

    /** The regression this point owes Module 5: the deal answer must survive. */
    public function test_that_the_bound_permission_still_answers_for_a_deal(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $dealId = $this->deal();

        $bound = $this->app->make(AttachmentPermissionInterface::class);

        self::assertTrue($bound->mayView(new AttachmentLink(AttachmentParent::Deal, $dealId), $actorId));
    }

    /**
     * Modules 10 and 13 do not exist, so the composite holds no entry for them
     * and must refuse rather than fall through to whichever implementation
     * happens to be first — the deny-by-default `DenyAllAttachmentPermission`
     * was bound for, kept.
     */
    public function test_that_the_bound_permission_refuses_a_parent_no_module_claims(): void
    {
        $actorId = $this->userWith(RoleName::Manager);
        $bound = $this->app->make(AttachmentPermissionInterface::class);

        foreach ([AttachmentParent::PurchaseOrder, AttachmentParent::Report] as $parent) {
            self::assertFalse(
                $bound->mayView(new AttachmentLink($parent, (string) Str::uuid7()), $actorId),
                "Expected {$parent->value} to be refused: no module answers for it yet.",
            );
        }
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function permission(): SupplierQuotationAttachmentPermission
    {
        $permission = $this->app->make(SupplierQuotationAttachmentPermission::class);
        self::assertInstanceOf(SupplierQuotationAttachmentPermission::class, $permission);

        return $permission;
    }

    private function userWith(RoleName $role): string
    {
        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => 'Passw0rd123',
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user->id;
    }

    private function offer(): string
    {
        $supplierId = (string) Str::uuid7();
        $currencyId = (string) Str::uuid7();
        $offerId = (string) Str::uuid7();

        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-9001',
            'supplier_id' => $supplierId,
            'total_price' => '4500.000000',
            'currency_id' => $currencyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $offerId;
    }

    private function deal(): string
    {
        $customerId = (string) Str::uuid7();
        $dealId = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Nile Contracting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-'.now()->format('Y').'-9002',
            'customer_id' => $customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dealId;
    }
}
