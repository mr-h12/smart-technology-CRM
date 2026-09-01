<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Application\Access\DealAttachmentPermission;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 5 Point 4.1's other half of D-38: what `DealAttachmentPermission`
 * answers for each of the four `AttachmentParent` cases, exercised directly
 * rather than only through `FileDownloadTest`'s Deal-only fixtures.
 */
final class DealAttachmentPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function permission(): DealAttachmentPermission
    {
        $permission = $this->app->make(DealAttachmentPermission::class);
        self::assertInstanceOf(DealAttachmentPermission::class, $permission);

        return $permission;
    }

    private function managerId(): string
    {
        $role = Role::query()->where('slug', RoleName::Manager->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test Manager',
            'email' => 'manager@example.test',
            'password' => 'Passw0rd123',
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();

        return $user->id;
    }

    private function deal(): string
    {
        $customerId = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dealId = (string) Str::uuid7();

        DB::table('deals')->insert([
            'id' => $dealId,
            'code' => 'DL-2026-9999',
            'customer_id' => $customerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dealId;
    }

    public function test_that_it_grants_a_manager_view_of_a_deal_it_holds_all_scope_for(): void
    {
        $actorId = $this->managerId();
        $dealId = $this->deal();

        self::assertTrue(
            $this->permission()->mayView(new AttachmentLink(AttachmentParent::Deal, $dealId), $actorId),
        );
    }

    public function test_that_it_refuses_a_deal_that_does_not_exist(): void
    {
        $actorId = $this->managerId();

        self::assertFalse(
            $this->permission()->mayView(
                new AttachmentLink(AttachmentParent::Deal, (string) Str::uuid7()),
                $actorId,
            ),
        );
    }

    /**
     * The three parents Deals does not own — Modules 6, 10 and 13 are still
     * `.gitkeep`, and this class must keep denying them the same way
     * `DenyAllAttachmentPermission` did, on the class's own docblock.
     */
    public function test_that_it_still_refuses_every_other_parent(): void
    {
        $actorId = $this->managerId();
        $permission = $this->permission();

        foreach ([AttachmentParent::SupplierQuotation, AttachmentParent::PurchaseOrder, AttachmentParent::Report] as $parent) {
            self::assertFalse(
                $permission->mayView(new AttachmentLink($parent, (string) Str::uuid7()), $actorId),
                "Expected {$parent->value} to still be refused.",
            );
        }
    }
}
