<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Presentation\GenerateMonthlyReportsJob;
use App\Modules\Reports\Presentation\SubmitUnreviewedWeeklyReportsJob;
use App\Support\Queue\QueueName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 13, slice 8 · 8.1 — `D-140`: J-07 (§15) on the 1st drafts one RPT-MN for the
 * calendar month just ended (company time; a partial first month counts from go-live;
 * nothing while the go-live date is empty, `D-127`), authored by the Manager the
 * `reports.default_recipient` setting names (none, or not an active Manager → nothing,
 * and the next run tries again). Every active CEO is a default recipient, any other
 * Manager may be picked, the author never. One RPT-MN per month. Its content is
 * company-wide counts, no money (8.2 adds it): deals created in the month by their
 * current status; quotation versions sent in the month (`sent_at`), won = accepted +
 * partial, lost = rejected + expired, by their current status.
 *
 * 2026-10-01 01:00 UTC: the month just ended is September 2026. The company zone is
 * `app.timezone` (UTC) unless a test sets `locale.timezone`.
 */
final class MonthlyReportsGenerationTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    /** @var array<string, string> one login per user — the login limit counts every call */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));
    }

    // ── what J-07 creates ───────────────────────────────────────────────────

    public function test_that_it_drafts_one_monthly_report_for_the_month_that_just_ended_by_the_named_manager_audited_with_no_actor(): void
    {
        $this->goLive('2026-09-01');
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        $this->user(RoleName::IndoorSales);

        $this->runJob();

        $rows = DB::table('reports')->where('type', 'monthly')->get();
        self::assertCount(1, $rows, 'D-140: one RPT-MN for the month, not one per user.');
        /** @var object{id: string, code: string, author_id: string, status: string, period_start: string, period_end: string} $report */
        $report = $rows[0];
        self::assertSame($manager->id, $report->author_id, 'D-140: authored by the Manager reports.default_recipient names.');
        self::assertSame('draft', $report->status, 'D-140: the author reviews and submits it; nothing auto-submits.');
        self::assertSame('2026-09-01', $report->period_start);
        self::assertSame('2026-09-30', $report->period_end);
        self::assertMatchesRegularExpression('/^RPT-MN-\d{4}-\d{4}$/', $report->code);

        $audit = DB::table('audit_log')->where('event', 'REPORT_CREATED')->where('entity_type', 'report')->where('entity_id', $report->id)->first();
        self::assertInstanceOf(stdClass::class, $audit, 'AUD-01: drafting a report left no audit entry.');
        self::assertNull($audit->user_id, 'D-140: the system drafts it, not a person.');
    }

    public function test_that_a_second_run_creates_nothing_new(): void
    {
        $this->goLive('2026-09-01');
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();
        $this->runJob();

        self::assertSame(1, DB::table('reports')->where('type', 'monthly')->count(), 'D-140: one RPT-MN per month.');
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_CREATED')->count());
    }

    public function test_that_nothing_is_drafted_while_the_go_live_date_is_empty(): void
    {
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count(), 'D-127: while the go-live date is empty J-07 creates nothing.');
    }

    public function test_that_nothing_is_drafted_for_a_month_that_ends_before_the_go_live_date(): void
    {
        $this->goLive('2026-10-01');
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count(), 'September ended before the go-live date.');
    }

    public function test_that_with_no_default_recipient_nothing_is_drafted_until_one_is_set(): void
    {
        $this->goLive('2026-09-01');
        $manager = $this->user(RoleName::Manager);

        $this->runJob();
        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count(), 'D-140: no named Manager makes nothing.');

        $this->defaultRecipient($manager);
        $this->runJob();

        self::assertSame([$manager->id], DB::table('reports')->where('type', 'monthly')->pluck('author_id')->all(), 'D-140: J-07 tries again on its next run.');
    }

    public function test_that_a_default_recipient_who_is_inactive_makes_nothing(): void
    {
        $this->goLive('2026-09-01');
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        DB::table('users')->where('id', $manager->id)->update(['is_active' => false]);

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count(), 'D-140: not an active Manager makes nothing.');
    }

    public function test_that_a_default_recipient_who_is_no_longer_a_manager_makes_nothing(): void
    {
        $this->goLive('2026-09-01');
        $manager = $this->user(RoleName::Manager);
        $this->defaultRecipient($manager);
        DB::table('users')->where('id', $manager->id)->update(['role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail()->id]);

        $this->runJob();

        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count(), 'D-140: not an active Manager makes nothing.');
    }

    public function test_that_a_go_live_date_mid_month_starts_that_months_period_on_it(): void
    {
        $this->goLive('2026-09-15');
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();

        self::assertSame(
            [['2026-09-15', '2026-09-30']],
            $this->periods(),
            'D-140: a partial first month counts from go-live.',
        );
    }

    public function test_that_the_catch_up_drafts_every_missed_month_since_go_live_once(): void
    {
        $this->goLive('2026-07-01');
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();
        $this->runJob();

        self::assertSame([
            ['2026-07-01', '2026-07-31'],
            ['2026-08-01', '2026-08-31'],
            ['2026-09-01', '2026-09-30'],
        ], $this->periods(), 'D-55, D-140: one draft per missed month since go-live, none twice.');
    }

    /** 22:30 UTC on 31 October is already 1 November in Cairo: October has ended in company time. */
    public function test_that_the_month_is_read_in_company_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(22, 30));
        $this->goLive('2026-09-01');
        $this->defaultRecipient($this->user(RoleName::Manager));

        $this->runJob();
        self::assertSame([['2026-09-01', '2026-09-30']], $this->periods(), 'In UTC it is still 31 October: October has not ended.');

        $this->patchJson('/api/v1/settings', ['settings' => ['locale.timezone' => 'Africa/Cairo']], $this->bearer($this->admin()))->assertStatus(200);
        $this->runJob();

        self::assertSame([
            ['2026-09-01', '2026-09-30'],
            ['2026-10-01', '2026-10-31'],
        ], $this->periods(), 'D-140: the month is the company\'s — in Cairo it is 1 November.');
    }

    // ── who it goes to (`D-140`) ────────────────────────────────────────────

    public function test_that_every_active_ceo_is_a_default_candidate_another_manager_a_candidate_and_the_author_none(): void
    {
        $this->goLive('2026-09-01');
        $author = $this->user(RoleName::Manager);
        $this->defaultRecipient($author);
        $other = $this->user(RoleName::Manager, 'other');
        $ceo = $this->user(RoleName::Ceo);
        $secondCeo = $this->user(RoleName::Ceo, 'second');
        $goneCeo = $this->user(RoleName::Ceo, 'gone', active: false);
        $leader = $this->user(RoleName::TeamLeader);
        // A leader with a team the author is not in: `Team` covers its members only (`D-138`).
        $this->user(RoleName::IndoorSales, 'member')->forceFill(['team_leader_id' => $leader->id])->save();
        $supervisor = $this->user(RoleName::OutdoorSupervisor);
        $this->runJob();

        /** @var list<array{id: string, name: string, is_default: bool}> $candidates */
        $candidates = $this->getJson(self::ENDPOINT.'/'.$this->monthly().'/recipient-candidates', $this->bearer($author))
            ->assertStatus(200)->json('data');
        $byId = array_column($candidates, 'is_default', 'id');

        self::assertTrue($byId[$ceo->id] ?? null, 'D-140: every active CEO is a default recipient.');
        self::assertTrue($byId[$secondCeo->id] ?? null, 'D-140: every active CEO is a default recipient.');
        self::assertFalse($byId[$other->id] ?? null, 'D-140: any other Manager may be picked, not preselected.');
        foreach ([$author, $goneCeo, $leader, $supervisor] as $absent) {
            self::assertArrayNotHasKey($absent->id, $byId, "{$absent->email} must not be offered (D-140, §3.10's Team/Out scopes).");
        }
    }

    // ── what the monthly report holds (`D-140`, 8.1: counts, no money) ──────

    public function test_that_the_draft_counts_the_months_deals_by_status_and_its_sent_quotations_and_carries_no_money_and_no_personal_activity(): void
    {
        $this->goLive('2026-09-01');
        $author = $this->user(RoleName::Manager);
        $this->defaultRecipient($author);
        $this->seedTheMonth($author, $this->user(RoleName::IndoorSales));

        $this->runJob();

        $response = $this->getJson(self::ENDPOINT.'/'.$this->monthly(), $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'monthly')
            // `D-126`'s RPT-MN is company-wide: the author's own day-by-day activity is not part of it.
            ->assertJsonMissingPath('data.activity');
        $this->assertTheMonthsCounts($response->json('data'));

        $body = $response->getContent();
        self::assertIsString($body);
        foreach (['net_amount', 'final_total', 'subtotal', 'profit', 'won_value', 'amount'] as $money) {
            self::assertStringNotContainsString($money, $body, "8.1 carries no money; {$money} is 8.2's.");
        }
    }

    /** Submitting freezes the counts (§11.3): a deal created afterwards, dated inside the month, changes nothing. */
    public function test_that_submitting_freezes_the_counts(): void
    {
        $this->goLive('2026-09-01');
        $author = $this->user(RoleName::Manager);
        $this->defaultRecipient($author);
        $ceo = $this->user(RoleName::Ceo);
        $this->seedTheMonth($author, $this->user(RoleName::IndoorSales));
        $this->runJob();
        $id = $this->monthly();

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$ceo->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);
        $this->deal('lead', '2026-09-20 10:00:00', null);

        $this->assertTheMonthsCounts($this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data'));
        $this->assertTheMonthsCounts($this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($ceo))->assertStatus(200)->json('data'));
    }

    // ── guards on a monthly row (no J-07 needed) ────────────────────────────

    /** Guard: §11.4 — a returned report is a v2 its author edits (`D-140`: "a returned one is a v2 the author edits"). */
    public function test_that_a_returned_monthly_report_is_a_version_2_its_author_edits(): void
    {
        $author = $this->user(RoleName::Manager);
        $other = $this->user(RoleName::Manager, 'other');
        $id = $this->monthlyRow($author);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$other->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add the October pipeline.'], [...$this->bearer($other), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.version', 2);
        $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'Pipeline added.'], $this->bearer($author))->assertStatus(201);
    }

    /** Guard: only the author and its recipients reach a report (`ReportAccess`). */
    public function test_that_a_non_author_non_recipient_gets_404_on_the_monthly_report(): void
    {
        $id = $this->monthlyRow($this->user(RoleName::Manager));

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($this->user(RoleName::IndoorSales)))->assertStatus(404);
    }

    /** Guard: only a live recipient reads it received (`ReportAccess`) — another Manager and a CEO the author left out get 404, and none of its counts. */
    public function test_that_a_manager_who_is_not_a_recipient_and_a_ceo_left_out_get_404_on_the_received_monthly_report(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $leftOut = $this->user(RoleName::Ceo, 'left');
        $otherManager = $this->user(RoleName::Manager, 'other');
        $id = $this->monthlyRow($author);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$ceo->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        foreach ([$otherManager, $leftOut] as $outsider) {
            $body = $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($outsider))
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found')
                ->getContent();
            self::assertIsString($body);
            self::assertStringNotContainsString('"deals_by_status"', $body, "{$outsider->email} read the month's deal counts.");
            self::assertStringNotContainsString('"quotations"', $body, "{$outsider->email} read the month's quotation counts.");
        }
    }

    /** Guard: `D-137` — follow-ups are refused on any report that is not RPT-DY. */
    public function test_that_a_follow_up_on_a_monthly_report_is_refused_422(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $customer = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $customer, 'name' => 'Nile Trading', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson(self::ENDPOINT.'/'.$id.'/follow-ups', ['customer_id' => $customer, 'note' => 'Not on a monthly.'], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'report_follow_up_not_allowed');
    }

    /** Guard: `D-140` — nothing auto-submits RPT-MN; J-06 is the weekly report's (`D-136`), however long the draft waits. */
    public function test_that_j06_leaves_a_monthly_draft_a_draft_past_the_review_window(): void
    {
        $author = $this->user(RoleName::Manager);
        $this->defaultRecipient($author);
        $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author, createdAt: now()->subHours(72)->toDateTimeString());

        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new SubmitUnreviewedWeeklyReportsJob, 'handle']);

        self::assertSame('draft', DB::table('reports')->where('id', $id)->value('status'), 'D-140: the author reviews and submits it; nothing auto-submits.');
        self::assertFalse((bool) DB::table('reports')->where('id', $id)->value('not_reviewed'));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
    }

    /** Guard: the monthly report is on its author's «تقاريري», and once submitted, on the CEO's «التقارير المستلَمة». */
    public function test_that_the_monthly_report_is_listed_on_my_reports_and_once_submitted_on_the_ceos_received_reports(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);

        $mine = $this->getJson(self::ENDPOINT, $this->bearer($author))->assertStatus(200)->json('data');
        self::assertIsArray($mine);
        /** @var list<array{id: string, type: string, status: string}> $mine */
        self::assertSame([[$id, 'monthly', 'draft']], array_map(static fn (array $r): array => [$r['id'], $r['type'], $r['status']], $mine));

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$ceo->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $received = $this->getJson(self::ENDPOINT.'/received', $this->bearer($ceo))->assertStatus(200)->json('data');
        self::assertIsArray($received);
        /** @var list<array{id: string, type: string, author_name: string}> $received */
        self::assertSame([[$id, 'monthly', $author->name]], array_map(static fn (array $r): array => [$r['id'], $r['type'], $r['author_name']], $received));
    }

    /** Guard: `CreateReportRequest` accepts `daily` only; J-07 alone makes RPT-MN (`D-140`). */
    public function test_that_a_monthly_report_cannot_be_created_by_hand(): void
    {
        $this->postJson(self::ENDPOINT, ['type' => 'monthly'], $this->bearer($this->user(RoleName::Manager)))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'type');

        self::assertSame(0, DB::table('reports')->where('type', 'monthly')->count());
    }

    // ── the job and the schedule (J-05's shape) ─────────────────────────────

    public function test_that_the_job_is_scheduled_monthly_on_the_first_at_one_utc_onto_maintenance(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), GenerateMonthlyReportsJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-07 job.');
        self::assertSame('0 1 1 * *', $scheduled[0]->expression, 'D-140: on the 1st, at 01:00 UTC.');

        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, GenerateMonthlyReportsJob::class);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * September, company-wide: deals created in it — 2 Lead, 1 Won, 1 Lost, owned by
     * the author, another user and nobody — and two on either side of it. Quotation
     * versions sent in it: one still Sent, Accepted, Partial, Rejected, Expired and
     * Counter (sent 6, won 2, lost 2); one Accepted sent in August, one Draft never
     * sent. Their deals are created now (October), outside the month. And one deal
     * the author created, in the audit, that a daily report would show.
     */
    private function seedTheMonth(User $author, User $other): void
    {
        $this->deal('lead', '2026-09-01 00:00:00', $author->id);
        $this->deal('lead', '2026-09-30 23:59:59', $other->id);
        $won = $this->deal('won', '2026-09-10 09:00:00', null);
        $this->deal('lost', '2026-09-11 09:00:00', $other->id);
        $this->deal('won', '2026-08-31 23:59:59', $author->id);
        $this->deal('lead', '2026-10-01 00:00:00', $author->id);

        foreach (['sent', 'accepted', 'partial', 'rejected', 'expired', 'counter'] as $i => $status) {
            $this->quotation($status, '2026-09-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT).' 10:00:00');
        }
        $this->quotation('accepted', '2026-08-31 23:00:00');
        $this->quotation('draft', null);

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $author->id, 'event' => 'DEAL_CREATED',
            'entity_type' => 'deal', 'entity_id' => $won, 'created_at' => '2026-09-10 09:00:00',
        ]);
    }

    /** @param mixed $data the report's `data` */
    private function assertTheMonthsCounts(mixed $data): void
    {
        self::assertIsArray($data);
        /** @var array<string, int>|null $deals */
        $deals = $data['deals_by_status'] ?? null;
        self::assertIsArray($deals, 'D-140: the deals created in the month by their current status.');
        self::assertSame(2, $deals['lead'] ?? null);
        self::assertSame(1, $deals['won'] ?? null);
        self::assertSame(1, $deals['lost'] ?? null);
        self::assertSame(4, array_sum($deals), 'Deals created on 31 August and 1 October are outside September.');

        /** @var array<string, int>|null $quotations */
        $quotations = $data['quotations'] ?? null;
        self::assertIsArray($quotations, 'D-140: the quotation versions sent in the month.');
        self::assertSame(6, $quotations['sent'] ?? null, 'Sent in September, whatever their status now; the August one is outside.');
        self::assertSame(2, $quotations['won'] ?? null, 'D-140: won = accepted + partial.');
        self::assertSame(2, $quotations['lost'] ?? null, 'D-140: lost = rejected + expired.');
        self::assertCount(3, $quotations, 'sent, won and lost — nothing else, no money (8.2).');
    }

    private function deal(string $status, string $createdAt, ?string $ownerId): string
    {
        $customer = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $customer, 'name' => 'Nile Trading', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $id = (string) Str::uuid7();
        DB::table('deals')->insert([
            'id' => $id, 'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -6), 'customer_id' => $customer,
            'owner_id' => $ownerId, 'status' => $status, 'lost_reason' => $status === 'lost' ? 'Price.' : null,
            'last_activity_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function quotation(string $status, ?string $sentAt): void
    {
        DB::table('quotations')->where('id', $this->insertQuotation())->update([
            'status' => $status, 'sent_at' => $sentAt,
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'Too dear.' : null,
        ]);
    }

    /** A monthly draft placed straight in the table, for the guards that need no J-07. */
    private function monthlyRow(User $author, ?string $createdAt = null): string
    {
        $createdAt ??= now()->toDateTimeString();
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-MN-2026-0001', 'type' => 'monthly', 'status' => 'draft',
            'author_id' => $author->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function runJob(): void
    {
        // As a worker runs it: with no request, so the audit names no user.
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new GenerateMonthlyReportsJob, 'handle']);
    }

    private function monthly(): string
    {
        $id = DB::table('reports')->where('type', 'monthly')->value('id');
        self::assertIsString($id, 'J-07 drafted no monthly report.');

        return $id;
    }

    /** @return list<array{0: string, 1: string}> */
    private function periods(): array
    {
        /** @var list<array{0: string, 1: string}> $periods */
        $periods = DB::table('reports')->where('type', 'monthly')->orderBy('period_start')->get(['period_start', 'period_end'])
            ->map(static fn (object $r): array => [$r->period_start, $r->period_end])->all();

        return $periods;
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    private function goLive(string $date): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.go_live_date' => $date]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    private function defaultRecipient(User $manager): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['reports.default_recipient' => $manager->id]], $this->bearer($this->admin()))
            ->assertStatus(200);
    }

    /** The one hidden Super Admin of the test, built the first time anything asks. */
    private function admin(): User
    {
        return User::query()->where('email', 'super.admin@example.test')->first() ?? $this->user(RoleName::SuperAdmin);
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
        $user->forceFill(['created_at' => '2026-01-01 00:00:00']);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        if (! isset($this->tokens[$user->id])) {
            $token = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertStatus(201)->json('data.token');
            self::assertIsString($token);
            $this->tokens[$user->id] = $token;
        }

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id], 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
