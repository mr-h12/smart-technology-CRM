<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 2, Point 3.1 — `GET` and `PATCH /api/v1/settings`.
 *
 * **§3.11 gives `system settings` to the Super Admin and to nobody else** — the
 * Manager holds `FX rates` on the row below and `—` on this one. The negative
 * test is therefore not a formality: it is the row of the matrix, asserted.
 *
 * **The editable keys are §13 screen 4's fields, and only those.** A key/value
 * table will accept anything; `SystemSetting` is what makes the endpoint refuse
 * `haxx.enabled` with a 422 rather than storing it. The *values* remain the
 * business's — nothing here seeds one — but the *field names* are the
 * document's, and the endpoint is bounded by them.
 *
 * **A settings change is audited.** §3.12 rule 4 does not list it among its
 * nine, but `AUD-01` asks for a comprehensive audit and `AuditEnforcementTest`
 * refuses an unregistered writer, so the use case records `SETTINGS_UPDATED`
 * with the old and new values.
 */
final class SettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/settings';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: the grants live in the database, so without this every call
        // is 403 and the suite passes for the wrong reason.
        $this->seed(RolePermissionSeeder::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

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

    // ── §3.11, the row asserted ─────────────────────────────────────────────

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /** §3.11 gives `system settings` to the Super Admin and `—` to everyone else. */
    public function test_that_a_manager_may_not_read_the_settings(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    public function test_that_a_manager_may_not_write_the_settings(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['settings' => ['company.name' => 'Anything']],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(403);
    }

    public function test_that_a_sales_employee_may_not_read_the_settings(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))->assertStatus(403);
    }

    // ── the envelope ────────────────────────────────────────────────────────

    /** `OpenAPI §4.1`: a `data` object and a `meta.request_id`; §3.3 for the header. */
    public function test_that_the_reading_answers_in_the_documented_envelope(): void
    {
        $response = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))->assertStatus(200);

        $response->assertJsonStructure(['data' => ['settings'], 'meta' => ['request_id']]);

        self::assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    /** Every §13 screen 4 field this endpoint owns is listed, even before it has a value. */
    public function test_that_every_known_field_is_listed_with_a_null_value_before_it_is_set(): void
    {
        $settings = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->json('data.settings');

        self::assertIsArray($settings);

        foreach (SystemSetting::cases() as $setting) {
            self::assertArrayHasKey($setting->value, $settings);
            self::assertNull($settings[$setting->value]);
        }
    }

    // ── writing ─────────────────────────────────────────────────────────────

    public function test_that_the_super_admin_may_set_a_field(): void
    {
        $settings = $this->patchJson(
            self::ENDPOINT,
            ['settings' => [SystemSetting::CompanyName->value => 'Smart Technology']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200)->json('data.settings');

        // Indexed, not `assertJsonPath('data.settings.company.name', …)`: the
        // key contains a dot, and a JSON path reads that as nesting. The same
        // collision the validation rules hit, in the assertion this time —
        // which is why the row is checked underneath rather than the shape
        // alone.
        self::assertIsArray($settings);
        self::assertSame('Smart Technology', $settings[SystemSetting::CompanyName->value]);

        self::assertSame(
            'Smart Technology',
            DB::scalar('select value from settings where key = ?', [SystemSetting::CompanyName->value]),
        );
    }

    public function test_that_setting_a_field_twice_updates_rather_than_duplicates(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT, ['settings' => ['company.name' => 'First']], $bearer)->assertStatus(200);
        $this->patchJson(self::ENDPOINT, ['settings' => ['company.name' => 'Second']], $bearer)->assertStatus(200);

        self::assertSame(1, DB::table('settings')->where('key', 'company.name')->count());
        self::assertSame('Second', DB::scalar("select value from settings where key = 'company.name'"));
    }

    /** A key/value table accepts anything; the endpoint must not. */
    public function test_that_a_field_the_document_does_not_name_is_refused(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['settings' => ['haxx.enabled' => 'yes']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);

        self::assertSame(0, DB::table('settings')->count());
    }

    /** §13 names a "default tax"; §5 makes a tax a percentage, not a sentence. */
    public function test_that_a_decimal_field_refuses_a_word(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['settings' => [SystemSetting::DefaultTaxPercent->value => 'fourteen']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(422);
    }

    public function test_that_a_decimal_field_accepts_a_decimal_string(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['settings' => [SystemSetting::DefaultTaxPercent->value => '14.000']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200);

        self::assertSame(
            'decimal',
            DB::scalar('select value_type from settings where key = ?', [SystemSetting::DefaultTaxPercent->value]),
        );
    }

    /** `DB-07` again: a percentage is stored as a string, never as a float. */
    public function test_that_a_decimal_field_keeps_every_digit_it_was_given(): void
    {
        $this->patchJson(
            self::ENDPOINT,
            ['settings' => [SystemSetting::DefaultTaxPercent->value => '14.005']],
            $this->bearerFor(RoleName::SuperAdmin),
        )->assertStatus(200);

        self::assertSame(
            '14.005',
            DB::scalar('select value from settings where key = ?', [SystemSetting::DefaultTaxPercent->value]),
        );
    }

    /** `AUD-01`: an administrator changing the company's own configuration is recorded. */
    public function test_that_a_change_is_audited_with_the_old_and_the_new_value(): void
    {
        $bearer = $this->bearerFor(RoleName::SuperAdmin);

        $this->patchJson(self::ENDPOINT, ['settings' => ['company.name' => 'First']], $bearer)->assertStatus(200);
        $this->patchJson(self::ENDPOINT, ['settings' => ['company.name' => 'Second']], $bearer)->assertStatus(200);

        $entries = DB::table('audit_log')->where('event', 'SETTINGS_UPDATED')->orderBy('created_at')->get();

        self::assertCount(2, $entries);

        // Asked of the database rather than cast off `mixed`: a cast would hide
        // a column that came back as something other than JSON text.
        $old = DB::scalar(
            "select old_values::text from audit_log where event = 'SETTINGS_UPDATED' order by created_at desc limit 1",
        );
        $new = DB::scalar(
            "select new_values::text from audit_log where event = 'SETTINGS_UPDATED' order by created_at desc limit 1",
        );

        self::assertIsString($old);
        self::assertIsString($new);
        self::assertStringContainsString('First', $old);
        self::assertStringContainsString('Second', $new);
    }

    /** An empty body changes nothing and says so, rather than 500ing. */
    public function test_that_an_empty_change_set_is_refused(): void
    {
        $this->patchJson(self::ENDPOINT, ['settings' => []], $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(422);
    }
}
