<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationAttribute;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\AuthorizePermission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Point 2.3 — `SEC-07` · `SEC-08` · `SEC-09` · §3.1 · §3.2 · §3.12 rules 1, 5
 * and 6.
 *
 * ── Why the protected routes are defined here ──────────────────────────────
 *
 * Because no production route carries `permission:` yet: Module 1's user, role
 * and permission CRUD is a later point and Modules 3 onward own the resources.
 * Registering routes in the test is what stops this point shipping an engine
 * that has never actually refused an HTTP request — the alternative is a unit
 * test of a middleware that no request has ever entered, which is this
 * project's defect №3 ("a check that passes because nothing is there").
 *
 * The routes are deliberately trivial. What is under test is the middleware,
 * the envelope and the status code, not a controller.
 */
final class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** A real triple from §3.5, held by Indoor Sales at `own`. */
    private const RESOURCE = 'deal';

    private const ACTION = 'view';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerProtectedRoutes();
    }

    /**
     * Registered in `setUp()`, not in a `defineRoutes()` hook.
     *
     * `defineRoutes()` is Orchestra Testbench's, and this project's `TestCase`
     * extends Laravel's own — which never calls it, so the routes silently did
     * not exist and every request under test answered **404 instead of 403**.
     * `LocaleTest` already carries a comment about the same trap. Adding them
     * here works because `parent::setUp()` has booted the application, and the
     * router accepts routes after boot.
     *
     * The routes are deliberately trivial. What is under test is the
     * middleware, the status code and the envelope — not a controller.
     */
    private function registerProtectedRoutes(): void
    {
        // Mirrors how a real endpoint will be declared, alias included.
        Route::middleware(['auth', AuthorizePermission::ALIAS.':deal.view'])
            ->get('/api/v1/testing/deals', function (Request $request): JsonResponse {
                $decision = $request->attributes->get(AuthorizationAttribute::NAME);

                return new JsonResponse(['data' => [
                    'scopes' => $decision instanceof PermissionDecision ? $decision->scopeValues() : null,
                ]]);
            });

        Route::middleware(['auth', AuthorizePermission::ALIAS.':deal.view,team'])
            ->get('/api/v1/testing/deals/team', fn (): JsonResponse => new JsonResponse(['data' => ['ok' => true]]));

        Route::middleware(['auth', AuthorizePermission::ALIAS.':unicorn.groom'])
            ->get('/api/v1/testing/unicorns', fn (): JsonResponse => new JsonResponse(['data' => ['ok' => true]]));
    }

    private function userWith(RoleName $role): User
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

        return $user;
    }

    private function engine(): AuthorizeAction
    {
        return $this->app->make(AuthorizeAction::class);
    }

    // ── the engine reads the database, not the code ─────────────────────────

    public function test_the_matrix_comes_from_the_database_and_not_from_permission_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWith(RoleName::IndoorSales);

        self::assertTrue($this->engine()->decide($user->id, self::RESOURCE, self::ACTION)->granted);

        // §3.12 rule 5: "changing this matrix is a configuration change, not a
        // deployment." Delete the grant row and the very next call must refuse
        // — with no restart, no cache clear and no redeploy. If the engine were
        // reading PermissionMatrix, this would still pass as granted.
        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')
                ->where('resource', self::RESOURCE)->where('action', self::ACTION)->pluck('id'))
            ->where('role_id', $user->role_id)
            ->update(['deleted_at' => now()]);

        self::assertFalse(
            $this->app->make(AuthorizeAction::class)->decide($user->id, self::RESOURCE, self::ACTION)->granted,
            'A revoked grant still authorised — the matrix is not being read from the database (SEC-07).',
        );
    }

    public function test_a_soft_deleted_permission_grants_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWith(RoleName::IndoorSales);

        Permission::query()
            ->where('resource', self::RESOURCE)->where('action', self::ACTION)
            ->update(['deleted_at' => now()]);

        self::assertFalse($this->engine()->decide($user->id, self::RESOURCE, self::ACTION)->granted);
    }

    public function test_an_unknown_resource_is_denied(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWith(RoleName::IndoorSales);

        // §3's `—` and `❌` are the same thing: absent is refused.
        self::assertFalse($this->engine()->decide($user->id, 'unicorn', 'groom')->granted);
    }

    public function test_a_user_who_does_not_exist_is_denied(): void
    {
        $this->seed(RolePermissionSeeder::class);

        self::assertFalse(
            $this->engine()->decide('0198f3d4-1a2b-7c3d-8e4f-5a6b7c8d9e0f', self::RESOURCE, self::ACTION)->granted,
        );
    }

    // ── §3.2 scope hierarchy (SEC-08) ───────────────────────────────────────

    public function test_a_broader_scope_covers_a_narrower_one(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = $this->userWith(RoleName::Manager);

        $decision = $this->engine()->decide($manager->id, 'customer', 'view');

        self::assertTrue($decision->granted);
        // Manager holds `all` on §3.3's customer view, and All includes everything.
        self::assertTrue($decision->allows(Scope::Own));
        self::assertTrue($decision->allows(Scope::Team));
        self::assertTrue($decision->allows(Scope::All));
    }

    public function test_a_narrower_scope_does_not_reach_a_broader_row(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);

        $ownOnly = $this->engine()->decide($indoor->id, 'customer', 'view');

        self::assertTrue($ownOnly->granted, 'Indoor Sales holds customer.view at some scope in §3.3.');
        self::assertTrue($ownOnly->allows(Scope::Own));
        self::assertFalse(
            $ownOnly->allows(Scope::All),
            'An `own` grant reached an `all` row — SEC-08 is not being enforced.',
        );
    }

    public function test_out_and_asgn_are_not_ordered_against_team(): void
    {
        // §3.2's partial order, asserted directly on the enum rather than
        // through a role, because this is the property every scope check rests
        // on and it must not be inferred from whichever cells happen to exist.
        self::assertTrue(Scope::All->includes(Scope::Out));
        self::assertTrue(Scope::All->includes(Scope::Asgn));
        self::assertTrue(Scope::Team->includes(Scope::Own));

        self::assertFalse(Scope::Team->includes(Scope::Out));
        self::assertFalse(Scope::Out->includes(Scope::Team));
        self::assertFalse(Scope::Team->includes(Scope::Asgn));
        self::assertFalse(Scope::Asgn->includes(Scope::Team));
        self::assertFalse(Scope::Own->includes(Scope::Team));
    }

    public function test_a_decision_with_no_scopes_is_a_denial(): void
    {
        self::assertFalse(PermissionDecision::granted([])->granted);
        self::assertFalse(PermissionDecision::granted([])->allows(Scope::Own));
    }

    // ── §3.1 Super Admin ────────────────────────────────────────────────────

    public function test_super_admin_passes_every_resource_and_action(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        // §3.1 gives Super Admin access that is not in the §3.3–§3.10 cells, so
        // this must hold for a resource nobody has ever defined.
        foreach ([['deal', 'view'], ['quotation', 'approve'], ['unicorn', 'groom']] as [$resource, $action]) {
            $decision = $this->engine()->decide($superAdmin->id, $resource, $action);

            self::assertTrue($decision->granted, "Super Admin was refused {$resource}.{$action}.");
            self::assertTrue($decision->unconditional);
            self::assertTrue($decision->allows(Scope::All));
        }
    }

    public function test_super_admin_passes_where_it_holds_no_grant_row(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        // §3.11 Administration is "the one table with a Super Admin column", so
        // the role does hold rows — nine of them, all `admin.*`. Checked here
        // rather than assumed, because the first version of this test asserted
        // zero and was simply wrong about the document.
        $held = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $superAdmin->role_id)
            ->pluck('permissions.resource')
            ->unique()->values()->all();

        self::assertSame(['admin'], $held, '§3.11 is the only section with a Super Admin column.');

        // The point of §3.1 is the access that is *not* in those rows.
        self::assertTrue($this->engine()->decide($superAdmin->id, 'deal', 'view')->granted);
        self::assertTrue($this->engine()->decide($superAdmin->id, 'deal', 'view')->unconditional);
    }

    // ── §3.12 rule 6 — the stealth Super Admin ──────────────────────────────

    public function test_hidden_users_are_absent_from_a_listable_query(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);
        $indoor = $this->userWith(RoleName::IndoorSales);

        $listed = User::query()->listable()->pluck('id')->all();

        self::assertContains($indoor->id, $listed);
        self::assertNotContains(
            $superAdmin->id,
            $listed,
            '§3.12 rule 6: the Super Admin is never listed in any user list, for any role.',
        );
    }

    public function test_the_seeded_super_admin_is_hidden(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        self::assertTrue($superAdmin->is_hidden, 'is_hidden must follow the role, not a hand-written list.');
        self::assertTrue(RoleName::SuperAdmin->isHidden());

        foreach (RoleName::cases() as $role) {
            if ($role !== RoleName::SuperAdmin) {
                self::assertFalse($role->isHidden(), "§3.12 rule 6 names one hidden role; {$role->value} is not it.");
            }
        }
    }

    public function test_a_hidden_user_is_still_reachable_when_the_system_must_reach_them(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        // Hidden is not absent. SEC-03's notification, SEC-10's Login As and
        // the audit trail all still have to find this account — which is why
        // `listable()` is a scope to opt into and not a global one to lift.
        self::assertNotNull(User::query()->whereKey($superAdmin->id)->first());
        self::assertNotNull(User::query()->where('email', $superAdmin->email)->first());
    }

    // ── SEC-09: the API is the barrier ──────────────────────────────────────

    public function test_a_permitted_caller_reaches_the_endpoint_and_receives_its_scopes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);

        $response = $this->actingAs($indoor)->getJson('/api/v1/testing/deals')->assertStatus(200);

        $scopes = $response->json('data.scopes');

        self::assertIsArray($scopes);
        self::assertNotSame([], $scopes, 'The middleware must leave the reach behind for SEC-08.');
    }

    public function test_a_caller_without_the_permission_is_refused_with_the_documented_envelope(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);

        $this->actingAs($indoor)->getJson('/api/v1/testing/unicorns')
            ->assertStatus(403)
            ->assertJsonPath('error.code', AuthorizationRefused::ERROR_CODE)
            ->assertJsonPath('error.details.0.code', AuthorizationRefused::DETAIL_CODE)
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'meta' => ['request_id']]);
    }

    public function test_a_caller_whose_scope_does_not_reach_is_refused_at_the_api(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);

        // §3.5 gives Indoor Sales `deal.view` at `own`. The route asks for
        // `team`, and `own` does not include it — a 403, not a filtered 200.
        $this->actingAs($indoor)->getJson('/api/v1/testing/deals/team')->assertStatus(403);
    }

    public function test_the_same_route_admits_a_role_whose_scope_does_reach(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = $this->userWith(RoleName::Manager);

        // The mirror of the test above. Without it, a middleware that refused
        // everything would look correct.
        $this->actingAs($manager)->getJson('/api/v1/testing/deals/team')->assertStatus(200);
    }

    public function test_super_admin_passes_the_middleware_on_an_undefined_resource(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $this->actingAs($superAdmin)->getJson('/api/v1/testing/unicorns')->assertStatus(200);
    }

    public function test_an_unauthenticated_caller_gets_401_and_not_403(): void
    {
        // A 403 would imply somebody was identified and refused. Nobody was.
        $this->getJson('/api/v1/testing/deals')->assertStatus(401);
    }

    public function test_revoking_a_grant_changes_the_api_answer_with_no_deployment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);

        $this->actingAs($indoor)->getJson('/api/v1/testing/deals')->assertStatus(200);

        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')
                ->where('resource', self::RESOURCE)->where('action', self::ACTION)->pluck('id'))
            ->where('role_id', $indoor->role_id)
            ->update(['deleted_at' => now()]);

        // The build plan's criterion, word for word: "Given a permission removed
        // from a role → When the user calls the API directly → Then 403."
        $this->actingAs($indoor)->getJson('/api/v1/testing/deals')->assertStatus(403);
    }

    public function test_changing_a_users_role_changes_what_they_may_do(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWith(RoleName::Ceo);

        // §3.1's CEO is a read-only observer, so this is a role swap and not a
        // permission edit — the other half of §3.12 rule 5.
        $before = $this->engine()->decide($user->id, 'quotation', 'approve')->granted;

        $user->role_id = Role::query()->where('slug', RoleName::Manager->value)->firstOrFail()->id;
        $user->save();

        $after = $this->app->make(AuthorizeAction::class)->decide($user->id, 'quotation', 'approve')->granted;

        self::assertFalse($before, '§3.1 makes CEO an observer; approving is not observation.');
        self::assertTrue($after, 'Manager is §3.1\'s highest operational authority and must be able to approve.');
    }

    // ── the Gate reads the same matrix ──────────────────────────────────────

    public function test_laravels_gate_answers_from_the_database(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $indoor = $this->userWith(RoleName::IndoorSales);
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        self::assertTrue(Gate::forUser($indoor)->allows('deal.view'));
        self::assertFalse(Gate::forUser($indoor)->allows('unicorn.groom'));
        self::assertTrue(Gate::forUser($superAdmin)->allows('unicorn.groom'));
    }

    public function test_the_gate_leaves_a_non_matrix_ability_alone(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        Gate::define('some-policy-ability', static fn (): bool => false);

        // `before()` returning true for everything would silently override every
        // policy any later module writes. §3.1 exempts Super Admin from the
        // *matrix*, and `some-policy-ability` is not a `resource.action`.
        self::assertFalse(Gate::forUser($superAdmin)->allows('some-policy-ability'));
    }

    // ── the alias is registered ─────────────────────────────────────────────

    public function test_the_permission_alias_resolves_to_the_middleware(): void
    {
        // The HTTP kernel is what installs the aliases on the router, and it is
        // built lazily. Reading `Router::getMiddleware()` before anything has
        // resolved that kernel returns an empty array — **including Laravel's
        // own `auth`**, which demonstrably works — so the accessor has to be
        // read after the kernel exists, not before.
        $this->app->make(HttpKernel::class);

        $aliases = $this->app->make(Router::class)->getMiddleware();

        self::assertArrayHasKey(AuthorizePermission::ALIAS, $aliases);
        self::assertSame(AuthorizePermission::class, $aliases[AuthorizePermission::ALIAS]);
    }
}
