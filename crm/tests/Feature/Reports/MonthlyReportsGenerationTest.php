<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Application\ShowReport;
use App\Modules\Reports\Application\SubmitReport;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
 * company-wide: deals created in the month by their current status; quotation versions
 * sent in the month (`sent_at`), won = accepted + partial, lost = rejected + expired, by
 * their current status; and 8.2's money per currency (`D-141`).
 *
 * 2026-10-01 01:00 UTC: the month just ended is September 2026. The company zone is
 * `app.timezone` (UTC) unless a test sets `locale.timezone`.
 */
final class MonthlyReportsGenerationTest extends TestCase
{
    use InsertsMonthlyReport;
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
        $this->moveTo($manager, RoleName::IndoorSales);

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

    // ── what the monthly report holds (`D-140` counts, `D-141` money) ───────

    public function test_that_the_draft_counts_the_months_deals_by_status_and_its_sent_quotations_carries_its_money_per_currency_and_no_personal_activity(): void
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

        // 8.2 (`D-141`): the money is there, in `money`'s shape — the month's accepted and partial
        // versions are EGP at 0 here — and no quotation total under its own name.
        $money = $response->json('data.money');
        self::assertIsArray($money, 'D-141: the monthly report carries its money per currency.');
        self::assertSame(['EGP'], array_column($money, 'currency'));
        foreach ($money as $row) {
            self::assertIsArray($row);
            self::assertSame(['currency', 'won_value', 'cost', 'profit'], array_keys($row), 'D-141: one row per currency — its won value, cost and profit.');
            foreach (['won_value', 'cost', 'profit'] as $figure) {
                self::assertIsString($row[$figure], "{$figure} leaves the API as a decimal string (DB-07).");
                self::assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', $row[$figure], "{$figure} at the stored scale, numeric(18,6).");
            }
        }
        $body = $response->getContent();
        self::assertIsString($body);
        foreach (['"net_amount"', '"final_total"', '"subtotal"'] as $total) {
            self::assertStringNotContainsString($total, $body, "D-141: the money is won_value, cost and profit; {$total} is a quotation's own.");
        }
    }

    // ── the month's money (8.2, `D-141`) ────────────────────────────────────

    /**
     * `D-141`, figures in `seedTheMonthsMoney()`. Each wrong rule moves a total by its own amount:
     * chain B's v1 counted too → EGP won +500.000001, its v2 (a one-level parent lookup stops
     * there) → EGP won +300.300300; chain E's Partial v1 under its Rejected v2 → USD won
     * +444.444444; the soft-deleted line → EGP cost
     * +99.999999; the additional item kept in profit → EGP profit +50.250000; `final_total`
     * for `net_amount` → EGP won +155.638899 (tax and rounding_diff); the Sent, Rejected and
     * Expired versions → +777.333333, +999.111111, +888.222222; August's Accepted →
     * +666.444444; the currencies mixed → one row.
     */
    public function test_that_the_money_counts_each_chains_latest_won_version_sent_in_the_month_per_currency_net_of_tax_and_rounding(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $this->seedTheMonthsMoney();

        $money = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money');

        self::assertSame([
            ['currency' => 'EGP', 'won_value' => '1926.793210', 'cost' => '765.555557', 'profit' => '1110.987653'],
            ['currency' => 'USD', 'won_value' => '2000.123456', 'cost' => '1500.000001', 'profit' => '500.123455'],
        ], $money, 'D-141: per currency, sorted by code; won = net_amount; cost = Σ live line_cost; profit = net_amount − additional_total − cost.');
    }

    /** `D-126`, `D-141`: cost and profit only when the author holds §3.5's *view cost & margin*; the won value either way. */
    public function test_that_without_view_cost_and_margin_the_author_sees_the_won_value_and_no_cost_or_profit(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '1234.567890', lineCosts: ['234.567891']);
        $this->revoke(RoleName::Manager, 'quotation', 'view_cost_and_margin', 'all');

        $response = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200);

        self::assertSame([['currency' => 'EGP', 'won_value' => '1234.567890']], $response->json('data.money'), 'D-126: cost and profit are absent — not null, not zero.');
        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString('234.567891', $body, 'The cost leaked under another key.');
    }

    /** §11.3: submitting freezes the money — a version accepted afterwards, sent inside the month, changes nothing. */
    public function test_that_submitting_freezes_the_money(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '100.100100', lineCosts: ['40.000001']);
        $later = $this->pricedQuotation('sent', '2026-09-06 10:00:00', 'EGP', subtotal: '200.200200', lineCosts: ['50.000000']);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$ceo->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);
        DB::table('quotations')->where('id', $later)->update(['status' => 'accepted']);

        $frozen = [['currency' => 'EGP', 'won_value' => '100.100100', 'cost' => '40.000001', 'profit' => '60.100099']];
        // `jsonb` reorders a frozen row's keys: the figures are compared, not their key order.
        self::assertEquals($frozen, $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'));
        self::assertEquals($frozen, $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($ceo))->assertStatus(200)->json('data.money'));
    }

    /**
     * `D-141`: only the chain's latest version sent *in the month* is read — a Partial v1 sent on 25 September
     * stays September's money though its Accepted v2 was sent on 3 October (counting v2 instead → won 987.654321),
     * and v2 is October's: "a chain won in two months still counts once in each month".
     */
    public function test_that_a_partial_v1_sent_in_the_month_counts_though_its_accepted_v2_was_sent_after_the_month(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $october = $this->monthlyRow($author, periodStart: '2026-10-01');
        $v1 = $this->pricedQuotation('partial', '2026-09-25 10:00:00', 'EGP', subtotal: '321.123456', lineCosts: ['21.000001']);
        $this->pricedQuotation('accepted', '2026-10-03 10:00:00', 'EGP', subtotal: '987.654321', lineCosts: ['87.000000'], parentId: $v1);

        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '321.123456', 'cost' => '21.000001', 'profit' => '300.123455']],
            $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'),
            'D-141: September reads v1, the chain\'s latest version sent in September; v2 belongs to October.',
        );
        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '987.654321', 'cost' => '87.000000', 'profit' => '900.654321']],
            $this->getJson(self::ENDPOINT.'/'.$october, $this->bearer($author))->assertStatus(200)->json('data.money'),
            'D-141: the same chain counts again in October, through v2.',
        );
    }

    /** `D-141`, `DB-01`: a soft-deleted Accepted quotation adds nothing (counting it → won +555.000000). */
    public function test_that_a_soft_deleted_accepted_quotation_adds_no_money(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '100.000001', lineCosts: ['10.000000']);
        $gone = $this->pricedQuotation('accepted', '2026-09-06 10:00:00', 'EGP', subtotal: '555.000000', lineCosts: ['55.000000']);
        DB::table('quotations')->where('id', $gone)->update(['deleted_at' => now()]);

        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '100.000001', 'cost' => '10.000000', 'profit' => '90.000001']],
            $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'),
            'D-141: only live versions count.',
        );
    }

    /**
     * `D-141`, `D-140`: the month is `[first day 00:00, the day after the last)` in company time (`Report::utcPeriod`).
     * In Cairo, September runs from 2026-08-31 21:00:00 to 2026-09-30 21:00:00 UTC: an Accepted version sent at
     * exactly the lower bound is September's (an exclusive bound → won −200.000000); one sent at exactly the upper
     * bound is October's and adds nothing; one sent a second before it is September's (an inclusive bound → won +555.000000).
     */
    public function test_that_the_months_company_time_bounds_count_the_lower_one_and_not_the_upper_one(): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => ['locale.timezone' => 'Africa/Cairo']], $this->bearer($this->admin()))->assertStatus(200);
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $report = $this->app->make(ReportDirectoryInterface::class)->find($id);
        self::assertNotNull($report);
        [$from, $to] = $report->utcPeriod('Africa/Cairo');
        $bound = $to->format('Y-m-d H:i:s');
        self::assertNotSame('2026-10-01 00:00:00', $bound, 'The bound is company time (Cairo), not UTC midnight.');

        $this->pricedQuotation('accepted', $from->format('Y-m-d H:i:s'), 'EGP', subtotal: '200.000000', lineCosts: ['20.000000']);
        $this->pricedQuotation('accepted', $to->modify('-1 second')->format('Y-m-d H:i:s'), 'EGP', subtotal: '100.000001', lineCosts: ['10.000000']);
        $this->pricedQuotation('accepted', $bound, 'EGP', subtotal: '555.000000', lineCosts: ['55.000000']);

        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '300.000001', 'cost' => '30.000000', 'profit' => '270.000001']],
            $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'),
            "D-141: sent at {$from->format('Y-m-d H:i:s')} UTC is September's first instant in Cairo; at {$bound} UTC is past September.",
        );
    }

    /**
     * `D-141`: cost = Σ `line_cost` of the *live* lines — a won version whose only line is soft-deleted costs
     * 0 (the `COALESCE`), so profit = `net_amount` − `additional_total` = 425.500000 − 25.500000.
     */
    public function test_that_a_won_version_whose_only_line_is_soft_deleted_costs_zero(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '400.000000', additional: '25.500000', deletedLineCosts: ['99.999999']);

        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '425.500000', 'cost' => '0.000000', 'profit' => '400.000000']],
            $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'),
            'D-141: no live line → cost 0, never null; the additional item is left out of profit.',
        );
    }

    /**
     * `D-141`: a discount larger than the margin is a loss — net 80.000000 (100.000000 − 20.000000) against a cost of
     * 95.500000 is a profit of −15.500000, sent with its sign (`DB-07`: a decimal string, never clamped or made absolute).
     */
    public function test_that_a_won_version_sold_below_its_cost_carries_a_negative_profit(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '100.000000', discount: '20.000000', lineCosts: ['95.500000']);

        self::assertSame(
            [['currency' => 'EGP', 'won_value' => '80.000000', 'cost' => '95.500000', 'profit' => '-15.500000']],
            $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.money'),
            'D-141: profit = net_amount − additional_total − cost, negative when sold below cost.',
        );
    }

    // ── the author's reach (8.2, `D-141`) ───────────────────────────────────

    /** `D-141`: only while the author is an active Manager — moved to Indoor Sales, the report and its submit are 404, and nothing of it leaks. */
    public function test_that_an_author_moved_to_another_role_gets_404_on_the_monthly_report_and_on_its_submit(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '1234.567890', lineCosts: ['234.567891']);
        $this->bearer($author);
        $this->moveTo($author, RoleName::IndoorSales);

        $this->assertOutOfReach($author, $ceo, $id);
    }

    /**
     * `D-141`: the author's role must hold `deal.view` and `quotation.view` at All — either narrowed
     * to Own, the report and its submit are 404.
     *
     * @return array<string, array{0: string}>
     */
    public static function viewGrants(): array
    {
        return ['quotation.view' => ['quotation'], 'deal.view' => ['deal']];
    }

    #[DataProvider('viewGrants')]
    public function test_that_an_author_whose_role_sees_only_its_own_gets_404_on_the_monthly_report_and_on_its_submit(string $resource): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '1234.567890', lineCosts: ['234.567891']);
        $this->revoke(RoleName::Manager, $resource, 'view', 'all');
        $this->grant(RoleName::Manager, $resource, 'view', 'own');

        $this->assertOutOfReach($author, $ceo, $id);
    }

    /**
     * `D-141` (m-1): "an active **Manager**" is a condition of its own — moved to Team Leader, whose
     * role is granted `deal.view` at All beside the `quotation.view` at All §3.5 already seeds for it, the
     * author still gets 404 on every author route of the monthly report.
     */
    public function test_that_an_author_moved_to_team_leader_with_deal_and_quotation_view_at_all_still_gets_404(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);
        $this->pricedQuotation('accepted', '2026-09-05 10:00:00', 'EGP', subtotal: '1234.567890', lineCosts: ['234.567891']);
        $this->grant(RoleName::TeamLeader, 'deal', 'view', 'all');
        $leader = Role::query()->where('slug', RoleName::TeamLeader->value)->firstOrFail()->id;
        self::assertSame(1, DB::table('role_permissions')->where('role_id', $leader)->whereNull('deleted_at')
            ->whereIn('permission_id', DB::table('permissions')->where(['resource' => 'quotation', 'action' => 'view', 'scope' => 'all'])->pluck('id'))
            ->count(), '§3.5 seeds quotation.view at All for the Team Leader.');
        $this->bearer($author);
        $this->moveTo($author, RoleName::TeamLeader);

        $this->assertOutOfReach($author, $ceo, $id);
    }

    /**
     * `D-141`: a deactivated author reaches neither. Over HTTP the bearer resolver already
     * stops an inactive user at 401, so the rule is pinned where both endpoints route through.
     */
    public function test_that_a_deactivated_author_reaches_neither_the_monthly_report_nor_its_submit(): void
    {
        $author = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->monthlyRow($author);
        DB::table('users')->where('id', $author->id)->update(['is_active' => false]);
        $this->app->instance('request', Request::create('http://localhost'));

        $this->assertThrowsNotFound(fn () => $this->app->make(ShowReport::class)->one($id, $author->id), "D-141: a deactivated author's read");
        $this->assertThrowsNotFound(fn () => $this->app->make(SubmitReport::class)->submit($id, [$ceo->id], $this->etag($id), $author->id), "D-141: a deactivated author's submit");
        self::assertSame('draft', DB::table('reports')->where('id', $id)->value('status'));
    }

    /** Guard: `D-141` limits the author's reach, never a recipient's — the CEO still reads the frozen copy after its author stops being a Manager. */
    public function test_that_a_recipient_still_reads_the_frozen_monthly_copy_after_its_author_stops_being_a_manager(): void
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

        $this->moveTo($author, RoleName::IndoorSales);

        $this->assertTheMonthsCounts($this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($ceo))->assertStatus(200)->json('data'));
    }

    /**
     * Submitting freezes the counts (§11.3): a deal created afterwards, dated inside the month, changes nothing.
     * The frozen `deals_by_status` keeps §4.4's pipeline order for the author and the recipient (`D-140`, the owner, 2026-10-20).
     */
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

    /**
     * `D-141`, the owner (2026-10-20): «تقاريري» hides an RPT-MN its author can no longer open — moved to Indoor
     * Sales, the author's list keeps their daily report and drops the monthly one, and counts it nowhere; an
     * active Manager's list still holds theirs.
     * Asking for the archive (`include_archived=1`) never brings an archived one back: a query parameter narrows, never
     * broadens (`OpenAPI §3.2`).
     */
    public function test_that_my_reports_hides_a_monthly_report_its_author_can_no_longer_open(): void
    {
        $demoted = $this->user(RoleName::Manager);
        $manager = $this->user(RoleName::Manager, 'other');
        $this->monthlyRow($demoted);
        DB::table('reports')->where('id', $this->monthlyRow($demoted, periodStart: '2026-08-01'))->update(['status' => 'archived']);
        $kept = $this->monthlyRow($manager);
        $this->bearer($demoted);
        $this->moveTo($demoted, RoleName::IndoorSales);
        $daily = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($demoted))->assertStatus(201)->json('data.id');

        $mine = $this->getJson(self::ENDPOINT, $this->bearer($demoted))->assertStatus(200);
        self::assertSame([$daily], array_column((array) $mine->json('data'), 'id'), 'D-141: the demoted author\'s list drops the RPT-MN they can no longer open.');
        $mine->assertJsonPath('meta.pagination.total', 1);
        self::assertSame(
            [$daily],
            array_column((array) $this->getJson(self::ENDPOINT.'?include_archived=1', $this->bearer($demoted))->assertStatus(200)->json('data'), 'id'),
            'OpenAPI §3.2: include_archived widens the archive, never D-141\'s reach — the archived RPT-MN stays hidden.',
        );

        self::assertSame([$kept], array_column((array) $this->getJson(self::ENDPOINT, $this->bearer($manager))->assertStatus(200)->json('data'), 'id'), 'An active Manager still lists their RPT-MN.');
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

    /**
     * September's money (`D-141`), company time = UTC. Won and counted: chain A, EGP Accepted —
     * subtotal 1234.567890, discount 123.456789, an additional item 50.250000 (net 1161.361101),
     * tax 155.555554 and rounding_diff 0.083345 (final 1317.000000), live lines 400.123456 +
     * 300.000001 and a soft-deleted 99.999999; chain B, v1 EGP Partial (500.000001) → v2 EGP
     * Partial (300.300300) → v3 USD Accepted (2000.123456, line 1500.000001), all sent in
     * September — v3 only, three levels deep (`parent_id` names the version just before); chain D,
     * EGP Partial 765.432109, line 65.432100. Not counted: chain E, v1 USD Partial (444.444444)
     * → v2 Rejected (333.333333), both sent in September — its latest is not won, so nothing
     * (the owner, 2026-10-20); one Sent, one Rejected, one Expired in September, one Accepted sent
     * on 31 August, one Draft.
     */
    private function seedTheMonthsMoney(): void
    {
        $this->pricedQuotation('accepted', '2026-09-02 10:00:00', 'EGP', subtotal: '1234.567890', additional: '50.250000', discount: '123.456789',
            tax: '155.555554', roundingDiff: '0.083345', lineCosts: ['400.123456', '300.000001'], deletedLineCosts: ['99.999999']);
        $v1 = $this->pricedQuotation('partial', '2026-09-08 10:00:00', 'EGP', subtotal: '500.000001', lineCosts: ['100.000000']);
        $v2 = $this->pricedQuotation('partial', '2026-09-10 10:00:00', 'EGP', subtotal: '300.300300', lineCosts: ['30.000000'], parentId: $v1);
        $this->pricedQuotation('accepted', '2026-09-12 10:00:00', 'USD', subtotal: '2000.123456', lineCosts: ['1500.000001'], parentId: $v2);
        $this->pricedQuotation('partial', '2026-09-20 10:00:00', 'EGP', subtotal: '765.432109', lineCosts: ['65.432100']);

        $e1 = $this->pricedQuotation('partial', '2026-09-14 10:00:00', 'USD', subtotal: '444.444444', lineCosts: ['4.000000']);
        $this->pricedQuotation('rejected', '2026-09-15 10:00:00', 'USD', subtotal: '333.333333', lineCosts: ['3.000000'], parentId: $e1);

        $this->pricedQuotation('sent', '2026-09-06 10:00:00', 'EGP', subtotal: '777.333333', lineCosts: ['1.000000']);
        $this->pricedQuotation('rejected', '2026-09-03 10:00:00', 'EGP', subtotal: '999.111111', lineCosts: ['1.000000']);
        $this->pricedQuotation('expired', '2026-09-04 10:00:00', 'USD', subtotal: '888.222222', lineCosts: ['1.000000']);
        $this->pricedQuotation('accepted', '2026-08-31 23:00:00', 'EGP', subtotal: '666.444444', lineCosts: ['1.000000']);
        $this->pricedQuotation('draft', null, 'EGP', subtotal: '555.555555', lineCosts: ['1.000000']);
    }

    private function grant(RoleName $role, string $resource, string $action, string $scope): void
    {
        $permission = DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->value('id');
        self::assertIsString($permission, "No {$resource}.{$action}.{$scope} permission is seeded.");
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid7(), 'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * `D-141`'s 404: the author reads, submits, writes a note on and lists the candidates of nothing
     * (every author route goes through `ReportAccess::reach`, B-1), and no count or money of the month leaks.
     * The PDF's two routes need a submitted version: `ReportPdfEndpointTest` covers them.
     */
    private function assertOutOfReach(User $author, User $recipient, string $id): void
    {
        $read = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->getContent();
        $submit = $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$recipient->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->getContent();
        $note = $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'Out of reach.'], $this->bearer($author))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->getContent();
        $candidates = $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $this->bearer($author))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->getContent();
        self::assertSame(0, DB::table('report_notes')->where('report_id', $id)->count(), 'D-141: the note was written.');

        foreach (['read' => $read, 'submit' => $submit, 'note' => $note, 'candidates' => $candidates] as $call => $body) {
            self::assertIsString($body);
            foreach (['"deals_by_status"', '"quotations"', '"money"', '"won_value"', '1234.567890'] as $leak) {
                self::assertStringNotContainsString($leak, $body, "The {$call} answer leaked {$leak}.");
            }
        }
        self::assertSame('draft', DB::table('reports')->where('id', $id)->value('status'), 'D-141: the submit went through.');
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
    }

    /** @param mixed $data the report's `data` */
    private function assertTheMonthsCounts(mixed $data): void
    {
        self::assertIsArray($data);
        $deals = $data['deals_by_status'] ?? null;
        self::assertIsArray($deals, 'D-140: the deals created in the month by their current status.');
        // D-140 (the owner, 2026-10-20): an ordered list in §4.4's pipeline order — `jsonb` reorders a frozen map's keys (won before lead).
        self::assertTrue(array_is_list($deals), 'D-140: deals_by_status is an ordered list of {status, count}, not a map.');
        foreach ($deals as $row) {
            self::assertIsArray($row);
            $keys = array_keys($row);
            sort($keys);
            self::assertSame(['count', 'status'], $keys, 'D-140: each row is {status, count}.');
        }
        self::assertSame(['lead', 'won', 'lost'], array_column($deals, 'status'), 'D-140: §4.4 pipeline order (DealStatusTransition::statuses), live and frozen.');
        self::assertSame([2, 1, 1], array_column($deals, 'count'), 'Deals created on 31 August and 1 October are outside September.');

        /** @var array<string, int>|null $quotations */
        $quotations = $data['quotations'] ?? null;
        self::assertIsArray($quotations, 'D-140: the quotation versions sent in the month.');
        self::assertSame(6, $quotations['sent'] ?? null, 'Sent in September, whatever their status now; the August one is outside.');
        self::assertSame(2, $quotations['won'] ?? null, 'D-140: won = accepted + partial.');
        self::assertSame(2, $quotations['lost'] ?? null, 'D-140: lost = rejected + expired.');
        self::assertCount(3, $quotations, 'sent, won and lost — nothing else; the money is under `money` (D-141).');
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
