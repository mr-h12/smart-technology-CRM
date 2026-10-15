<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 13, first slice — a daily report (RPT-DY) written by its author.
 *
 * Create (`OpenAPI §9.1`'s `Idempotency-Key`), read, list "My reports", and edit
 * the notes under `DB-12`'s token (`If-Match`, `409` when stale). Every author
 * route is gated by `report.create`, the only report grant an author holds
 * (§3.10); the CEO holds none of it. The day's activity is `D-126`'s RPT-DY
 * list, read from the audit log (`D-129`).
 */
final class ReportWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // A company day (UTC: no `locale.timezone` row) far from midnight.
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    // ── create ──────────────────────────────────────────────────────────────

    public function test_that_an_author_creates_a_daily_draft_with_its_rpt_dy_code(): void
    {
        $response = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'code'], 'meta' => ['request_id']]);

        self::assertSame('RPT-DY-2026-0001', $response->json('data.code'));

        $row = DB::table('reports')->where('id', $response->json('data.id'))->first();
        self::assertNotNull($row);
        self::assertSame('daily', $row->type);
        self::assertSame('draft', $row->status);
        self::assertSame($this->userWith(RoleName::IndoorSales)->id, $row->author_id);
        self::assertSame('2026-10-15', $row->period_start, 'A daily report covers the company day it is written on.');
        self::assertSame('2026-10-15', $row->period_end);
    }

    public function test_that_the_next_report_takes_the_next_number(): void
    {
        $this->create(RoleName::IndoorSales);
        $second = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);

        self::assertSame('RPT-DY-2026-0002', $second->json('data.code'));
    }

    public function test_that_a_create_without_an_idempotency_key_is_refused(): void
    {
        $headers = $this->bearerFor(RoleName::IndoorSales);
        unset($headers['Idempotency-Key']);

        $this->postJson(self::ENDPOINT, ['type' => 'daily'], $headers)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');

        self::assertSame(0, DB::table('reports')->count());
    }

    public function test_that_replaying_a_key_returns_the_original_report_without_a_second_one(): void
    {
        $headers = $this->bearerFor(RoleName::IndoorSales);

        $first = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $headers)->assertStatus(201);
        $replay = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $headers)->assertStatus(201);

        self::assertSame($first->json('data'), $replay->json('data'));
        self::assertSame(1, DB::table('reports')->count());
    }

    /** This slice builds the daily report only; the other five of `D-125` land in later slices. */
    public function test_that_a_type_other_than_daily_is_refused_for_now(): void
    {
        foreach (['weekly', 'visits', ''] as $type) {
            $this->postJson(self::ENDPOINT, ['type' => $type], $this->bearerFor(RoleName::IndoorSales))
                ->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'type');
        }

        self::assertSame(0, DB::table('reports')->count());
    }

    /** §3.10 and the acceptance criterion: the CEO views, exports and comments — never writes one. */
    public function test_that_the_ceo_may_not_create_a_report(): void
    {
        $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearerFor(RoleName::Ceo))->assertStatus(403);

        self::assertSame(0, DB::table('reports')->count());
    }

    public function test_that_creating_a_report_is_audited(): void
    {
        $id = $this->create(RoleName::IndoorSales);

        $entry = DB::table('audit_log')->where('event', 'REPORT_CREATED')->where('entity_id', $id)->first();
        self::assertNotNull($entry, 'AUD-01: the create left no audit entry.');
        self::assertSame('report', $entry->entity_type);
        self::assertSame($this->userWith(RoleName::IndoorSales)->id, $entry->user_id);
    }

    // ── list: "My reports" ─────────────────────────────────────────────────

    public function test_that_my_reports_lists_only_the_authors_own(): void
    {
        $mine = $this->create(RoleName::IndoorSales);
        $this->create(RoleName::Manager);

        $response = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'code', 'type', 'status', 'period_start', 'period_end', 'submitted_at']],
                'meta' => ['request_id', 'pagination'],
            ]);

        self::assertSame([$mine], array_column((array) $response->json('data'), 'id'));
    }

    public function test_that_the_ceo_has_no_my_reports_list(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Ceo))->assertStatus(403);
    }

    // ── read ────────────────────────────────────────────────────────────────

    public function test_that_the_author_reads_the_draft_with_its_etag(): void
    {
        $id = $this->create(RoleName::IndoorSales);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'id', 'code', 'type', 'status', 'period_start', 'period_end', 'notes', 'etag', 'submitted_at',
                'activity' => ['counts', 'items'], 'recipients',
            ]])
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.etag', 'report:'.$id.':1')
            ->assertJsonPath('data.recipients', []);
    }

    public function test_that_another_authors_report_is_not_found(): void
    {
        $id = $this->create(RoleName::Manager);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /**
     * `D-126` RPT-DY: the author's own activity that day, as counts and a list —
     * deals created, deal status moves, quotations created and sent, customer
     * responses, POs recorded. Nobody else's, no other day, no other event.
     */
    public function test_that_the_draft_shows_the_authors_activity_for_the_day(): void
    {
        $author = $this->userWith(RoleName::IndoorSales)->id;
        $other = $this->userWith(RoleName::Manager)->id;

        $this->activity($author, 'DEAL_CREATED', 'deal', '2026-10-15 07:00:00');
        $this->activity($author, 'DEAL_CREATED', 'deal', '2026-10-15 07:30:00');
        $this->activity($author, 'DEAL_STATUS_CHANGED', 'deal', '2026-10-15 08:00:00');
        $this->activity($author, 'QUOTATION_CREATED', 'quotation', '2026-10-15 08:10:00');
        $this->activity($author, 'QUOTATION_SENT', 'quotation', '2026-10-15 08:20:00');
        $this->activity($author, 'QUOTATION_ACCEPTED', 'quotation', '2026-10-15 08:30:00');
        $this->activity($author, 'QUOTATION_COUNTERED', 'quotation', '2026-10-15 08:40:00');
        $this->activity($author, 'PURCHASE_ORDER_CREATED', 'purchase_order', '2026-10-15 08:50:00');
        // Noise: another event, another day, another user.
        $this->activity($author, 'DEAL_UPDATED', 'deal', '2026-10-15 08:55:00');
        $this->activity($author, 'DEAL_CREATED', 'deal', '2026-10-14 23:59:59');
        $this->activity($other, 'DEAL_CREATED', 'deal', '2026-10-15 08:00:00');

        $id = $this->create(RoleName::IndoorSales);
        $activity = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->json('data.activity');

        self::assertIsArray($activity);
        self::assertSame([
            'deals_created' => 2,
            'deal_status_moves' => 1,
            'quotations_created' => 1,
            'quotations_sent' => 1,
            'customer_responses' => 2,
            'purchase_orders' => 1,
        ], $activity['counts']);

        $items = $activity['items'];
        self::assertIsArray($items);
        self::assertCount(8, $items);
        self::assertSame(
            ['DEAL_CREATED', 'DEAL_CREATED', 'DEAL_STATUS_CHANGED', 'QUOTATION_CREATED', 'QUOTATION_SENT',
                'QUOTATION_ACCEPTED', 'QUOTATION_COUNTERED', 'PURCHASE_ORDER_CREATED'],
            array_column($items, 'event'),
            'Oldest first, the order the day happened in.',
        );
        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertSame(['event', 'entity_type', 'entity_id', 'occurred_at'], array_keys($item));
        }
    }

    // ── edit the notes ──────────────────────────────────────────────────────

    public function test_that_the_author_edits_the_notes_and_the_token_advances(): void
    {
        $id = $this->create(RoleName::IndoorSales);

        $this->edit($id, 'report:'.$id.':1', ['notes' => 'Visited two customers.'])
            ->assertStatus(200)
            ->assertJsonPath('data.notes', 'Visited two customers.')
            ->assertJsonPath('data.etag', 'report:'.$id.':2');

        self::assertSame('Visited two customers.', DB::table('reports')->where('id', $id)->value('notes'));
    }

    public function test_that_editing_the_notes_is_audited_with_old_and_new_values(): void
    {
        $id = $this->create(RoleName::IndoorSales);
        $this->edit($id, 'report:'.$id.':1', ['notes' => 'First.'])->assertStatus(200);
        $this->edit($id, 'report:'.$id.':2', ['notes' => 'Second.'])->assertStatus(200);

        $entry = DB::table('audit_log')->where('event', 'REPORT_UPDATED')->where('entity_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->first(fn (object $row): bool => is_string($row->new_values) && str_contains($row->new_values, 'Second.'));

        self::assertNotNull($entry, 'AUD-01: the edit left no audit entry.');
        self::assertIsString($entry->old_values);
        self::assertIsString($entry->new_values);
        self::assertEquals(['notes' => 'First.'], json_decode($entry->old_values, true));
        self::assertEquals(['notes' => 'Second.'], json_decode($entry->new_values, true));
    }

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        $id = $this->create(RoleName::IndoorSales);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'x'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', 'if_match_required');
    }

    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        $id = $this->create(RoleName::IndoorSales);
        $this->edit($id, 'report:'.$id.':1', ['notes' => 'First.'])->assertStatus(200);

        $this->edit($id, 'report:'.$id.':1', ['notes' => 'Lost update.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', 'stale_version')
            ->assertJsonPath('error.details.0.current_etag', 'report:'.$id.':2');

        self::assertSame('First.', DB::table('reports')->where('id', $id)->value('notes'));
    }

    public function test_that_another_authors_draft_cannot_be_edited(): void
    {
        $id = $this->create(RoleName::Manager);

        $this->edit($id, 'report:'.$id.':1', ['notes' => 'Not mine.'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertNull(DB::table('reports')->where('id', $id)->value('notes'));
    }

    /** §11.4: once submitted, the content is the frozen copy's — not editable. */
    public function test_that_a_submitted_report_cannot_be_edited(): void
    {
        $id = $this->create(RoleName::IndoorSales);
        DB::table('reports')->where('id', $id)->update(['status' => 'submitted']);

        $this->edit($id, 'report:'.$id.':1', ['notes' => 'Too late.'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'report_not_draft');
    }

    public function test_that_notes_must_be_text(): void
    {
        $id = $this->create(RoleName::IndoorSales);

        $this->edit($id, 'report:'.$id.':1', ['notes' => ['not', 'text']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'notes');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function create(RoleName $author): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearerFor($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function edit(string $id, string $ifMatch, array $body): TestResponse
    {
        return $this->patchJson(
            self::ENDPOINT.'/'.$id,
            $body,
            [...$this->bearerFor(RoleName::IndoorSales), 'If-Match' => $ifMatch],
        );
    }

    private function activity(string $userId, string $event, string $entityType, string $at): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'event' => $event,
            'entity_type' => $entityType, 'entity_id' => (string) Str::uuid7(), 'created_at' => $at,
        ]);
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

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
