<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\ManagedListSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 3.4 — `GET` and `POST /api/v1/managed-lists/{list}`.
 *
 * **This is the module's acceptance criterion, finally testable:** *"New sector
 * added in settings → appears in the customer form **without a deployment**"*.
 * `DB-05` put the membership in a table and Point 1.3 built it; Point 2.2
 * seeded it and gave it a repository that refuses to answer from code. What was
 * missing was the way to add one, and it is the `POST` below. A test adds a
 * sector through the API and reads it back through the endpoint the customer
 * form will call.
 *
 * **The two verbs answer to two different authorities, and that asymmetry is
 * deliberate.** §3.11 has **no row** for managed lists — neither for reading
 * one nor for editing one. Reading is therefore guarded by authentication
 * alone, the way `GET /auth/me` is: §8 gives Catalog to five roles and
 * Customers to six, and every one of those screens has to render a unit or a
 * sector. A read behind `admin.system_settings` would make the acceptance
 * criterion unreachable — the sector would appear in settings and nowhere else.
 * Writing is a settings action and carries `admin.system_settings`, the Super
 * Admin's row, because §13 files these lists under screen 4's neighbourhood.
 * **Recorded in `CHECKLIST.md` as a decision awaiting a `D-xx`**, on the same
 * terms as `DELETE /roles/{role}`.
 *
 * **§14.2 is why both labels are required.** `ListEntry` carries `label_en` and
 * `label_ar` as *values* rather than translation keys, precisely because a
 * sector added at runtime has no key to resolve. An endpoint that accepted one
 * label would produce a row that renders as blank in the other language — which
 * is the `roles.name_ar` outcome Point 1.3 was written to avoid.
 */
final class ManagedListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/managed-lists';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ManagedListSeeder::class);
    }

    /** @var array<string, User> */
    private array $users = [];

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

    /** @return array<string, mixed> */
    private static function entry(string $code = 'education'): array
    {
        return ['code' => $code, 'label_en' => 'Education', 'label_ar' => 'تعليم', 'position' => 7];
    }

    // ── who may read, who may write ─────────────────────────────────────────

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'/sectors')->assertStatus(401);
        $this->postJson(self::ENDPOINT.'/sectors', self::entry())->assertStatus(401);
    }

    /**
     * The acceptance criterion depends on this. §8 puts Customers and Catalog
     * on every sales screen, and neither can render without these lists.
     */
    public function test_that_any_authenticated_employee_may_read_a_list(): void
    {
        $this->getJson(self::ENDPOINT.'/sectors', $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/units', $this->bearerFor(RoleName::OutdoorSales))
            ->assertStatus(200);
    }

    /** Editing the membership is a settings action — §3.11's Super Admin row. */
    public function test_that_a_sales_employee_may_not_add_an_entry(): void
    {
        $this->postJson(self::ENDPOINT.'/sectors', self::entry(), $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(403);
    }

    /** The Manager holds `FX rates` but not `system settings` — Point 3.2's row. */
    public function test_that_a_manager_may_not_add_an_entry(): void
    {
        $this->postJson(self::ENDPOINT.'/sectors', self::entry(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(403);
    }

    // ── reading ─────────────────────────────────────────────────────────────

    public function test_that_a_list_answers_in_the_collection_envelope(): void
    {
        $response = $this->getJson(self::ENDPOINT.'/sectors', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [['code', 'label_en', 'label_ar', 'position']],
            'meta' => [
                'pagination' => ['page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page'],
                'request_id',
            ],
        ]);

        self::assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    /** §4.2's seeded sectors, through the API this time. */
    public function test_that_the_seeded_sectors_come_back_in_position_order(): void
    {
        $entries = $this->getJson(self::ENDPOINT.'/sectors', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 6)
            ->json('data');

        self::assertIsArray($entries);

        $codes = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['code']);
            $codes[] = $entry['code'];
        }

        self::assertSame(
            ['government', 'medical', 'commercial', 'industrial', 'hotels', 'banks'],
            $codes,
        );
    }

    /** §14.2: both labels travel, because both are rendered. */
    public function test_that_both_labels_come_back(): void
    {
        $entries = $this->getJson(self::ENDPOINT.'/units', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($entries);

        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame('piece', $first['code']);
        self::assertSame('Piece', $first['label_en']);
        self::assertSame('قطعة', $first['label_ar']);
    }

    /**
     * `delivery_terms` is seeded empty on purpose (Point 2.2 — `DB-05` names it
     * and no document gives it a value). An empty list is a list, not a 404.
     */
    public function test_that_an_empty_list_is_one_empty_page_and_not_a_404(): void
    {
        $this->getJson(self::ENDPOINT.'/delivery_terms', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.pagination.total_pages', 1);
    }

    /**
     * `companies` is the fifth list (Point 5.1, owner's ruling of 2026-08-31),
     * and it is seeded empty for `delivery_terms`' reason. Asserted here because
     * a list the enum knows must be reachable through the route: `tryFrom` is
     * the only thing standing between a 200 and a 404, so the enum case and the
     * endpoint cannot be verified apart.
     */
    public function test_that_the_companies_list_is_served_and_starts_empty(): void
    {
        $this->getJson(self::ENDPOINT.'/companies', $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    /**
     * The catalog screens read this list, and §8 puts Catalog on five roles'
     * screens — so the read must work for one of them, not only for the Super
     * Admin. The route names no permission for exactly this reason.
     */
    public function test_that_a_catalog_role_may_read_the_companies_list(): void
    {
        $this->getJson(self::ENDPOINT.'/companies', $this->bearerFor(RoleName::Procurement))
            ->assertStatus(200);
    }

    /** `ManagedList`'s cases are the set; a list outside them is a 404. */
    public function test_that_a_list_this_system_does_not_keep_is_not_found(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        // The known list is exercised first: a missing route answers 404 too,
        // which is the vacuous shape Point 1.1 paid for.
        $this->getJson(self::ENDPOINT.'/sectors', $bearer)->assertStatus(200);
        $this->getJson(self::ENDPOINT.'/colours', $bearer)->assertStatus(404);
    }

    public function test_that_an_archived_entry_is_not_offered(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        DB::table('enum_lists')->where('list', 'sectors')->where('code', 'banks')
            ->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT.'/sectors', $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 5);
    }

    public function test_that_a_list_paginates(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $first = $this->getJson(self::ENDPOINT.'/sectors?per_page=4', $bearer)->assertStatus(200);
        $first->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', true);

        $page = $first->json('data');
        self::assertIsArray($page);
        self::assertCount(4, $page);

        $rest = $this->getJson(self::ENDPOINT.'/sectors?per_page=4&page=2', $bearer)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.has_previous_page', true)
            ->json('data');

        self::assertIsArray($rest);
        self::assertCount(2, $rest);
    }

    /** §6.1 and §6.2 — a 400, deliberately not the 422 a Form Request gives. */
    public function test_that_an_unhonourable_query_is_a_bad_request(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->getJson(self::ENDPOINT.'/sectors?per_page=101', $bearer)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');

        $this->getJson(self::ENDPOINT.'/sectors?page=0', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'/sectors?filter[code]=banks', $bearer)->assertStatus(400);
        $this->getJson(self::ENDPOINT.'/sectors?sort=code', $bearer)->assertStatus(400);
    }

    // ── the acceptance criterion ────────────────────────────────────────────

    /**
     * **The module's acceptance criterion, end to end.** A sector added through
     * the settings endpoint is returned by the endpoint the customer form
     * reads, in the same test, with no deployment between them.
     */
    public function test_that_a_new_sector_appears_in_the_list_the_customer_form_reads(): void
    {
        $this->postJson(self::ENDPOINT.'/sectors', self::entry(), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201)
            ->assertJsonPath('data.entry.code', 'education');

        $entries = $this->getJson(self::ENDPOINT.'/sectors', $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 7)
            ->json('data');

        self::assertIsArray($entries);

        $codes = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['code']);
            $codes[] = $entry['code'];
        }

        self::assertContains('education', $codes);
        self::assertSame('education', $codes[6], 'position 7 puts it last.');
    }

    public function test_that_an_added_entry_is_audited(): void
    {
        $this->postJson(self::ENDPOINT.'/sectors', self::entry(), $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(201);

        $values = DB::scalar(
            "select new_values::text from audit_log where event = 'LIST_ENTRY_ADDED' order by created_at desc limit 1",
        );

        self::assertIsString($values, 'AUD-01: a change to reference data is not silent.');
        self::assertStringContainsString('education', $values);
        self::assertStringContainsString('sectors', $values);
    }

    // ── refusals on the write ───────────────────────────────────────────────

    /** The partial unique index on `(list, code)` — said at the boundary first. */
    public function test_that_a_duplicate_code_in_the_same_list_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT.'/sectors', self::entry('banks'), $bearer)->assertStatus(422);

        self::assertSame(6, DB::scalar("select count(*)::int from enum_lists where list = 'sectors'"));
    }

    /** The same code in a *different* list is a different row, and allowed. */
    public function test_that_the_same_code_in_another_list_is_accepted(): void
    {
        $this->postJson(
            self::ENDPOINT.'/units',
            ['code' => 'banks', 'label_en' => 'Banks', 'label_ar' => 'بنوك', 'position' => 4],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(201);
    }

    /** §14.2 — a row with one label renders blank in the other language. */
    public function test_that_a_missing_arabic_label_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT.'/sectors',
            ['code' => 'education', 'label_en' => 'Education', 'position' => 7],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    public function test_that_a_missing_english_label_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT.'/sectors',
            ['code' => 'education', 'label_ar' => 'تعليم', 'position' => 7],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    /** A code is what a foreign key points at — machine-shaped, never shown. */
    public function test_that_a_code_that_is_not_machine_shaped_is_refused(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        foreach (['Higher Education', 'تعليم', 'UPPER', 'has-dash', ''] as $code) {
            $this->postJson(
                self::ENDPOINT.'/sectors',
                ['code' => $code, 'label_en' => 'X', 'label_ar' => 'س', 'position' => 7],
                $bearer,
            )->assertStatus(422);
        }

        self::assertSame(6, DB::scalar("select count(*)::int from enum_lists where list = 'sectors'"));
    }

    /** `position` is 1-based; Point 1.3 deliberately left it non-unique. */
    public function test_that_a_position_below_one_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT.'/sectors',
            ['code' => 'education', 'label_en' => 'Education', 'label_ar' => 'تعليم', 'position' => 0],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    /**
     * The known list is written first, and it has to be: with no route at all
     * the unknown list answers 404 as well, and this test passed on its very
     * first run — before a single line of the endpoint existed. That is the
     * vacuous shape Point 1.1 paid for, caught here by the RED count being 38
     * failed and **1 passed** rather than 39 failed.
     */
    public function test_that_adding_to_a_list_this_system_does_not_keep_is_not_found(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->postJson(self::ENDPOINT.'/sectors', self::entry(), $bearer)->assertStatus(201);
        $this->postJson(self::ENDPOINT.'/colours', self::entry('art'), $bearer)->assertStatus(404);
    }

    /**
     * `DB-01` forbids physical deletion and no document asks for an entry to be
     * withdrawn through the API, so the resource carries neither verb. The
     * assertion is on the collection URI, which `GET` and `POST` do match.
     */
    public function test_that_the_resource_carries_no_delete_verb(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->deleteJson(self::ENDPOINT.'/sectors', [], $bearer)->assertStatus(405);
        $this->patchJson(self::ENDPOINT.'/sectors', [], $bearer)->assertStatus(405);
    }
}
