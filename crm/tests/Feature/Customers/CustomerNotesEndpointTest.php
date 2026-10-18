<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `D-122` (F-50 · 3) — `GET|POST /customers/{id}/notes` and
 * `PATCH|DELETE /customers/{id}/notes/{note_id}`.
 *
 * Read under `customer.view`, written under `customer.edit`, each at the
 * customer's own row scope (§3.3): the customer is reached first, so a
 * customer out of reach — or a note that is not this customer's — is the
 * parent's own `404`, as `D-117` answers for a deal's documents.
 */
final class CustomerNotesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ─────────────────────────────── list

    public function test_that_notes_are_listed_newest_date_first_and_undated_last(): void
    {
        $id = $this->customer('Alpha Trading');
        $this->note($id, 'undated, older', null, '2026-10-01 09:00:00');
        $this->note($id, 'dated early', '2026-01-10', '2026-10-02 09:00:00');
        $this->note($id, 'undated, newer', null, '2026-10-03 09:00:00');
        $this->note($id, 'dated late', '2026-09-30', '2026-10-04 09:00:00');
        $this->note($id, 'removed', '2026-12-31', '2026-10-05 09:00:00', removed: true);
        $this->note($this->customer('Beta Medical'), 'another customer', '2026-12-31', '2026-10-05 09:00:00');

        $response = $this->getJson($this->notes($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 4)
            ->assertJsonPath('data.1.note_date', '2026-01-10');

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertSame(['dated late', 'dated early', 'undated, newer', 'undated, older'], array_column($data, 'body'));

        $first = $response->json('data.0');
        self::assertIsArray($first);
        $keys = array_keys($first);
        sort($keys);
        self::assertSame(['body', 'created_at', 'created_by', 'id', 'note_date', 'updated_at'], $keys);
    }

    public function test_that_an_out_of_scope_customer_is_404(): void
    {
        $id = $this->customer('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);
        $note = $this->note($id, 'private', null);
        $bearer = $this->bearerFor(RoleName::IndoorSales);

        $this->getJson($this->notes($id), $bearer)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        $this->postJson($this->notes($id), ['body' => 'mine now'], $bearer)->assertStatus(404);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'mine now'], $bearer)->assertStatus(404);
        $this->deleteJson($this->notes($id).'/'.$note, [], $bearer)->assertStatus(404);
        $this->getJson($this->notes((string) Str::uuid7()), $this->bearerFor(RoleName::Manager))->assertStatus(404);

        $this->assertDatabaseHas('customer_notes', ['id' => $note, 'body' => 'private', 'deleted_at' => null]);
        $this->assertDatabaseCount('customer_notes', 1);
    }

    /** §3.3: Procurement and the CEO view customers and edit none. */
    public function test_that_procurement_and_the_ceo_may_not_write(): void
    {
        $id = $this->customer('Alpha Trading');
        $note = $this->note($id, 'kept', null);

        foreach ([RoleName::Procurement, RoleName::Ceo] as $role) {
            $bearer = $this->bearerFor($role);

            $this->postJson($this->notes($id), ['body' => 'no'], $bearer)->assertStatus(403);
            $this->patchJson($this->notes($id).'/'.$note, ['body' => 'no'], $bearer)->assertStatus(403);
            $this->deleteJson($this->notes($id).'/'.$note, [], $bearer)->assertStatus(403);
        }

        // The CEO's view is `All`, so the CEO reads them.
        $this->getJson($this->notes($id), $this->bearerFor(RoleName::Ceo))
            ->assertStatus(200)
            ->assertJsonPath('data.0.body', 'kept');

        $this->assertDatabaseHas('customer_notes', ['id' => $note, 'body' => 'kept', 'deleted_at' => null]);
    }

    // ─────────────────────────────── add

    public function test_that_a_note_is_added_and_audited(): void
    {
        $id = $this->customer('Alpha Trading');
        $manager = $this->userWith(RoleName::Manager);

        $noteId = $this->postJson($this->notes($id), ['body' => '  Called; asked for a visit.  ', 'note_date' => '2026-10-15'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Called; asked for a visit.')
            ->assertJsonPath('data.note_date', '2026-10-15')
            ->assertJsonPath('data.created_by', $manager->id)
            ->json('data.id');
        self::assertIsString($noteId);

        $this->postJson($this->notes($id), ['body' => 'No date.'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.note_date', null);

        $this->assertDatabaseHas('customer_notes', ['id' => $noteId, 'customer_id' => $id, 'created_by' => $manager->id]);

        $audit = DB::table('audit_log')->where('event', 'CUSTOMER_NOTE_ADDED')->where('entity_id', $noteId)->first();
        self::assertNotNull($audit);
        self::assertSame('customer_note', $audit->entity_type);
        self::assertIsString($audit->new_values);
        $values = json_decode($audit->new_values, true);
        self::assertIsArray($values);
        self::assertSame($id, $values['customer_id'] ?? null);
        self::assertSame('Called; asked for a visit.', $values['body'] ?? null);
    }

    public function test_that_a_blank_or_long_body_or_bad_date_is_422(): void
    {
        $id = $this->customer('Alpha Trading');
        $bearer = $this->bearerFor(RoleName::Manager);

        foreach ([
            'body' => [[], ['body' => '   '], ['body' => str_repeat('a', 5001)], ['body' => ['not', 'text']]],
            'note_date' => [['body' => 'ok', 'note_date' => '2026-02-30'], ['body' => 'ok', 'note_date' => 'yesterday']],
        ] as $field => $payloads) {
            foreach ($payloads as $payload) {
                $this->postJson($this->notes($id), $payload, $bearer)
                    ->assertStatus(422)
                    ->assertJsonPath('error.code', 'validation_failed')
                    ->assertJsonPath('error.details.0.field', $field);
            }
        }

        $this->assertDatabaseCount('customer_notes', 0);

        // The ceiling itself is allowed.
        $this->postJson($this->notes($id), ['body' => str_repeat('a', 5000)], $bearer)->assertStatus(201);
    }

    // ─────────────────────────────── edit and remove

    public function test_that_a_note_is_edited_and_removed_and_audited(): void
    {
        $id = $this->customer('Alpha Trading');
        $note = $this->note($id, 'first wording', '2026-10-01');
        // Any holder of `customer.edit` in reach may change it, not only its author (`D-122`).
        $bearer = $this->bearerFor(RoleName::Manager);

        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'second wording', 'note_date' => null], $bearer)
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'second wording')
            ->assertJsonPath('data.note_date', null);

        $updated = DB::table('audit_log')->where('event', 'CUSTOMER_NOTE_UPDATED')->where('entity_id', $note)->first();
        self::assertNotNull($updated);
        self::assertIsString($updated->old_values);
        self::assertIsString($updated->new_values);
        self::assertSame(['body' => 'first wording', 'note_date' => '2026-10-01'], json_decode($updated->old_values, true));
        self::assertSame(['body' => 'second wording', 'note_date' => null], json_decode($updated->new_values, true));

        $this->deleteJson($this->notes($id).'/'.$note, [], $bearer)->assertStatus(204);

        self::assertNotNull(DB::table('customer_notes')->where('id', $note)->value('deleted_at'), 'The note was not soft-deleted.');
        $this->getJson($this->notes($id), $bearer)->assertStatus(200)->assertJsonPath('meta.pagination.total', 0);
        self::assertSame(1, DB::table('audit_log')->where('event', 'CUSTOMER_NOTE_REMOVED')->where('entity_id', $note)->count());

        // Removed is gone: a second remove, or an edit, is the parent's 404.
        $this->deleteJson($this->notes($id).'/'.$note, [], $bearer)->assertStatus(404);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'back'], $bearer)->assertStatus(404);
    }

    /** `D-138` — a Team Leader's notes stop at the team: a customer owned by a user with no leader is 404 on every verb. */
    public function test_that_a_team_leader_cannot_reach_the_notes_of_a_customer_outside_the_team(): void
    {
        $leader = $this->userWith(RoleName::TeamLeader);
        $this->userWith(RoleName::IndoorSales)->forceFill(['team_leader_id' => $leader->id])->save();
        $id = $this->customer('Outsiders', $this->userWith(RoleName::OutdoorSales)->id);
        $note = $this->note($id, 'private', null);
        $bearer = $this->bearerFor(RoleName::TeamLeader);

        $this->getJson($this->notes($id), $bearer)->assertStatus(404);
        $this->postJson($this->notes($id), ['body' => 'ours now'], $bearer)->assertStatus(404);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'ours now'], $bearer)->assertStatus(404);
        $this->deleteJson($this->notes($id).'/'.$note, [], $bearer)->assertStatus(404);
    }

    public function test_that_a_note_of_another_customer_is_404(): void
    {
        $alpha = $this->customer('Alpha Trading');
        $beta = $this->customer('Beta Medical');
        $betasNote = $this->note($beta, 'beta only', null);
        $bearer = $this->bearerFor(RoleName::Manager);

        $this->patchJson($this->notes($alpha).'/'.$betasNote, ['body' => 'moved'], $bearer)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
        $this->deleteJson($this->notes($alpha).'/'.$betasNote, [], $bearer)->assertStatus(404);
        $this->patchJson($this->notes($alpha).'/'.Str::uuid7(), ['body' => 'x'], $bearer)->assertStatus(404);
        // A malformed id is the router's 404 (`note` is in the id patterns), never a 500.
        $this->patchJson($this->notes($alpha).'/not-a-uuid', ['body' => 'x'], $bearer)->assertStatus(404);

        $this->assertDatabaseHas('customer_notes', ['id' => $betasNote, 'customer_id' => $beta, 'body' => 'beta only', 'deleted_at' => null]);
    }

    // ─────────────────────────────── helpers

    private function notes(string $customerId): string
    {
        return '/api/v1/customers/'.$customerId.'/notes';
    }

    private function note(string $customerId, string $body, ?string $date, ?string $createdAt = null, bool $removed = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('customer_notes')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'body' => $body,
            'note_date' => $date,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
            'deleted_at' => $removed ? now() : null,
        ]);

        return $id;
    }

    private function customer(string $name, ?string $ownerId = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => 'prospect',
            'sales_owner_id' => $ownerId,
            'is_archived' => false,
            'is_incomplete' => false,
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
