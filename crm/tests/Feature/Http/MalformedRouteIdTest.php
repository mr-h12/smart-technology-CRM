<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-17 · 1.2 — every id is a UUIDv7 (`D-61`), but the routes took any text and
 * handed it to Postgres, which refuses a malformed uuid with `22P02`: a `500`
 * (E5-7, E5-9). A malformed id is now refused by the route itself, so it is
 * 1.1's `404 resource_not_found` before anything reads the database.
 *
 * One real route per id parameter, called by the Super Admin so that no
 * permission refusal answers first and hides the 500.
 */
final class MalformedRouteIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'quotation' => ['GET', '/api/v1/quotations/not-a-uuid'],
            'user' => ['GET', '/api/v1/users/not-a-uuid'],
            'role' => ['GET', '/api/v1/roles/not-a-uuid'],
            'deal' => ['GET', '/api/v1/deals/not-a-uuid'],
            'customer' => ['GET', '/api/v1/customers/not-a-uuid'],
            'supplierQuotation' => ['GET', '/api/v1/supplier-quotations/not-a-uuid'],
            'supplier' => ['GET', '/api/v1/suppliers/not-a-uuid'],
            'session' => ['DELETE', '/api/v1/auth/sessions/not-a-uuid'],
            // Guarded by its own `Str::isUuid` until this point; the row keeps
            // it a 404 once that guard goes.
            'purchaseOrder' => ['GET', '/api/v1/purchase-orders/not-a-uuid'],
            'catalogItem' => ['GET', '/api/v1/catalog-items/not-a-uuid'],
            'file' => ['GET', '/api/v1/files/not-a-uuid/download'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_malformed_id_is_a_404_in_the_envelope(string $method, string $uri): void
    {
        $this->actingAs($this->superAdmin())
            ->json($method, $uri)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /**
     * The route refuses the id before `auth` runs, so a caller who is not
     * signed in gets the same 404 an unknown route gives (1.1), not a 401.
     */
    public function test_a_malformed_id_is_a_404_before_sign_in_is_checked(): void
    {
        $this->getJson('/api/v1/quotations/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    private function superAdmin(): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test Super Admin',
            'email' => 'super.admin@example.test',
            'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', RoleName::SuperAdmin->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => true,
        ]);
        $user->save();

        return $user;
    }
}
