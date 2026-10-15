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
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 13, first slice — choosing a report's recipients and submitting it.
 *
 * - Candidates: active users holding §3.10's `report.view_received` with a scope
 *   that covers the author (`D-127`, `D-129`). `All` covers everyone; `Out`
 *   covers outdoor authors; `Team` covers nobody until teams exist.
 * - Default: the one Manager the `reports.default_recipient` setting names, when
 *   that user is still an active Manager — and never on a Manager's own report.
 * - Submit: §11.3's frozen copy (`report_versions` v1) and the recipients, in
 *   one transaction, under the token. The acceptance criterion "unchanged even
 *   if the data changes" is read back after the data changes.
 */
final class ReportSubmitEndpointTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    // ── candidates ──────────────────────────────────────────────────────────

    public function test_that_an_indoor_authors_candidates_are_the_active_managers_and_the_ceo(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $managerA = $this->user(RoleName::Manager, 'a');
        $managerB = $this->user(RoleName::Manager, 'b');
        $ceo = $this->user(RoleName::Ceo);
        $this->user(RoleName::Manager, 'gone', active: false);
        $this->user(RoleName::TeamLeader);          // `Team` covers nobody yet.
        $this->user(RoleName::OutdoorSupervisor);   // `Out` does not cover an indoor author.
        $this->user(RoleName::Procurement);
        $this->user(RoleName::SuperAdmin);

        $id = $this->create($author);

        $candidates = $this->candidates($author, $id);

        self::assertEqualsCanonicalizing([$managerA->id, $managerB->id, $ceo->id], array_column($candidates, 'id'));
        foreach ($candidates as $candidate) {
            self::assertSame(['id', 'name', 'is_default'], array_keys($candidate));
            self::assertFalse($candidate['is_default'], 'No setting, no default (D-129).');
        }
    }

    public function test_that_an_outdoor_authors_candidates_include_the_outdoor_supervisor(): void
    {
        $author = $this->user(RoleName::OutdoorSales);
        $supervisor = $this->user(RoleName::OutdoorSupervisor);
        $manager = $this->user(RoleName::Manager);

        $ids = array_column($this->candidates($author, $this->create($author)), 'id');

        self::assertContains($supervisor->id, $ids);
        self::assertContains($manager->id, $ids);
    }

    /** `D-129`: the Manager the setting names is the default. */
    public function test_that_the_named_manager_is_the_default(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $named = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $this->setting('reports.default_recipient', $named->id);

        $candidates = $this->candidates($author, $this->create($author));

        $defaults = array_column($candidates, 'is_default', 'id');
        self::assertTrue($defaults[$named->id]);
        self::assertFalse($defaults[$other->id]);
    }

    /** `D-129`: a named user who is inactive, or no longer a Manager, is no default. */
    public function test_that_a_named_user_who_is_no_longer_an_active_manager_is_no_default(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $named = $this->user(RoleName::Manager, 'a');
        $this->user(RoleName::Manager, 'b');
        $this->setting('reports.default_recipient', $named->id);
        $id = $this->create($author);

        DB::table('users')->where('id', $named->id)->update(['is_active' => false]);
        self::assertNotContains(true, array_column($this->candidates($author, $id), 'is_default'));

        DB::table('users')->where('id', $named->id)->update([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->value('id'),
        ]);
        self::assertNotContains(true, array_column($this->candidates($author, $id), 'is_default'));
    }

    /** `D-127`: a Manager's own report has no default; the Manager chooses each time, and not himself. */
    public function test_that_a_managers_own_report_has_no_default_and_excludes_the_author(): void
    {
        $author = $this->user(RoleName::Manager, 'author');
        $other = $this->user(RoleName::Manager, 'other');
        $this->setting('reports.default_recipient', $other->id);

        $candidates = $this->candidates($author, $this->create($author));

        self::assertNotContains($author->id, array_column($candidates, 'id'));
        self::assertContains($other->id, array_column($candidates, 'id'));
        self::assertNotContains(true, array_column($candidates, 'is_default'));
    }

    public function test_that_another_authors_candidates_are_not_found(): void
    {
        $owner = $this->user(RoleName::Manager);
        $id = $this->create($owner);

        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $this->bearer($this->user(RoleName::IndoorSales)))
            ->assertStatus(404);
    }

    // ── submit ──────────────────────────────────────────────────────────────

    public function test_that_submitting_freezes_a_copy_and_saves_the_recipients(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $id = $this->create($author);
        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'A good day.'], [...$this->bearer($author), 'If-Match' => 'report:'.$id.':1'])
            ->assertStatus(200);

        $this->submit($author, $id, 'report:'.$id.':2', [$manager->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.etag', 'report:'.$id.':3')
            ->assertJsonPath('data.recipients.0.id', $manager->id)
            ->assertJsonPath('data.recipients.0.name', $manager->name);

        $report = DB::table('reports')->where('id', $id)->first();
        self::assertNotNull($report);
        self::assertSame('submitted', $report->status);
        self::assertNotNull($report->submitted_at);

        self::assertSame([$manager->id], DB::table('report_recipients')->where('report_id', $id)->pluck('user_id')->all());

        $version = DB::table('report_versions')->where('report_id', $id)->first();
        self::assertNotNull($version, '§11.3: a submitted report keeps a frozen copy.');
        self::assertIsInt($version->version);
        self::assertSame(1, $version->version);
        self::assertIsString($version->snapshot);
        $snapshot = json_decode($version->snapshot, true);
        self::assertIsArray($snapshot);
        self::assertSame('A good day.', $snapshot['notes']);
        self::assertIsArray($snapshot['activity']);
        self::assertIsArray($snapshot['activity']['counts']);
        self::assertSame(1, $snapshot['activity']['counts']['deals_created']);
    }

    /** Acceptance criterion: "Submitted report → fixed snapshot … unchanged even if the data changes". */
    public function test_that_a_submitted_report_does_not_change_when_the_data_does(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:30:00');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.activity.counts.deals_created', 1);
    }

    /**
     * `D-130`: the frozen copy holds the records as they were at submit — a
     * deal that moves on afterwards still shows its submit-time status.
     */
    public function test_that_the_frozen_copy_holds_the_records_as_they_were_at_submit(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $dealId = $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $code = DB::table('deals')->where('id', $dealId)->value('code');
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $frozen = ['deal_id' => $dealId, 'code' => $code, 'customer_name' => 'Nile Trading', 'status' => 'lead'];

        $version = DB::table('report_versions')->where('report_id', $id)->first();
        self::assertNotNull($version);
        self::assertIsString($version->snapshot);
        $snapshot = json_decode($version->snapshot, true);
        self::assertIsArray($snapshot);
        self::assertIsArray($snapshot['activity']);
        self::assertArrayHasKey('sections', $snapshot['activity'], 'D-130: the frozen copy holds the records, not the log.');
        self::assertIsArray($snapshot['activity']['sections']);
        self::assertSame([$frozen], $snapshot['activity']['sections']['deals_created']);

        DB::table('deals')->where('id', $dealId)->update(['status' => 'contacted']);
        DB::table('customers')->where('id', DB::table('deals')->where('id', $dealId)->value('customer_id'))->update(['name' => 'Renamed Later']);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.activity.sections.deals_created', [$frozen]);
    }

    public function test_that_submitting_is_audited(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $entry = DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->first();
        self::assertNotNull($entry, 'AUD-01: the submit left no audit entry.');
        self::assertSame($author->id, $entry->user_id);
    }

    /** `D-127`: a report goes to somebody — with no default, the author must choose. */
    public function test_that_a_submit_without_recipients_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'recipient_ids');

        $this->assertStillDraft($id);
    }

    public function test_that_a_recipient_outside_the_candidates_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $colleague = $this->user(RoleName::Procurement);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$colleague->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'recipient_ids');

        $this->assertStillDraft($id);
    }

    public function test_that_a_manager_may_not_send_his_report_to_himself(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$author->id])->assertStatus(422);

        $this->assertStillDraft($id);
    }

    public function test_that_a_stale_submit_is_a_409(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'x'], [...$this->bearer($author), 'If-Match' => 'report:'.$id.':1'])
            ->assertStatus(200);

        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.current_etag', 'report:'.$id.':2');

        $this->assertStillDraft($id);
    }

    public function test_that_a_submit_without_if_match_is_a_400(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], $this->bearer($author))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.field', 'If-Match');
    }

    public function test_that_a_submitted_report_cannot_be_submitted_again(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $this->submit($author, $id, 'report:'.$id.':2', [$manager->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'report_not_draft');

        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('report_recipients')->where('report_id', $id)->count());
    }

    public function test_that_another_authors_report_cannot_be_submitted(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($owner);
        $intruder = $this->user(RoleName::Procurement);

        $this->submit($intruder, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(404);

        $this->assertStillDraft($id);
    }

    // ── refusals the permission audit asked for ─────────────────────────────

    /** §3.10: the CEO holds no `report.create`, so no author route opens for them — even on a real report id. */
    public function test_that_the_ceo_is_refused_on_every_report_route(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $ceo = $this->bearer($this->user(RoleName::Ceo));

        $this->getJson(self::ENDPOINT.'/'.$id, $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'x'], [...$ceo, 'If-Match' => 'report:'.$id.':1'])->assertStatus(403);
        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$ceo, 'If-Match' => 'report:'.$id.':1'])
            ->assertStatus(403);

        $this->assertStillDraft($id);
        self::assertNull(DB::table('reports')->where('id', $id)->value('notes'));
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $id = $this->create($this->user(RoleName::IndoorSales));

        $this->getJson(self::ENDPOINT)->assertStatus(401);
        $this->postJson(self::ENDPOINT, ['type' => 'daily'])->assertStatus(401);
        $this->getJson(self::ENDPOINT.'/'.$id)->assertStatus(401);
        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates')->assertStatus(401);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => []])->assertStatus(401);
    }

    /** The candidates rule holds on the submit path too: an inactive Manager and the hidden Super Admin are refused. */
    public function test_that_an_inactive_manager_or_the_super_admin_is_refused_as_a_recipient(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $gone = $this->user(RoleName::Manager, 'gone', active: false);
        $admin = $this->user(RoleName::SuperAdmin);
        self::assertTrue((bool) $admin->is_hidden, 'The fixture must build the Super Admin hidden, or this proves nothing.');
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$gone->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'recipient_not_allowed');
        $this->submit($author, $id, 'report:'.$id.':1', [$admin->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'recipient_not_allowed');

        $this->assertStillDraft($id);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function assertStillDraft(string $id): void
    {
        self::assertSame('draft', DB::table('reports')->where('id', $id)->value('status'));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(0, DB::table('report_recipients')->where('report_id', $id)->count());
    }

    /** @return list<array<string, mixed>> */
    private function candidates(User $author, string $id): array
    {
        $data = $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $this->bearer($author))
            ->assertStatus(200)
            ->json('data');
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param  list<string>  $recipients
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(User $author, string $id, string $ifMatch, array $recipients): TestResponse
    {
        return $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => $recipients],
            [...$this->bearer($author), 'If-Match' => $ifMatch],
        );
    }

    private function create(User $author): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    /** Through the Super Admin's settings endpoint, so the settings cache is the real one. */
    private function setting(string $key, string $value): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => [$key => $value]], $this->bearer($this->user(RoleName::SuperAdmin)))
            ->assertStatus(200);
    }

    /** One index entry pointing at a real deal (Module 9's fixture), as `D-130` needs; returns the deal's id. */
    private function activity(string $userId, string $event, string $at): string
    {
        $dealId = DB::table('quotations')->where('id', $this->insertQuotation())->value('deal_id');
        self::assertIsString($dealId);
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'event' => $event,
            'entity_type' => 'deal', 'entity_id' => $dealId, 'created_at' => $at,
        ]);

        return $dealId;
    }

    private function user(RoleName $role, string $tag = '', bool $active = true): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().($tag !== '' ? ' '.$tag : ''),
            'email' => str_replace('_', '.', $role->value).($tag !== '' ? '.'.$tag : '').'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => $active,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
