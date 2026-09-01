<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Deals\Application\CustomerStatus\RecomputeStaleCustomerStatuses;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Presentation\RecomputeStaleCustomerStatusesJob;
use App\Support\Queue\QueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 5 Point 4.2 — `J-02`'s nightly half (§4.5, `D-49`). Point 3.1 wired
 * the event-triggered half into `POST /deals` and `PATCH /deals/{id}/status`;
 * this is the sweep that catches a customer's only active deal simply going
 * stale with neither event firing.
 */
final class RecomputeStaleCustomerStatusesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedDeal(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function customerStatus(string $customerId): string
    {
        $status = DB::table('customers')->where('id', $customerId)->value('customer_status');

        self::assertIsString($status);

        return $status;
    }

    private function directory(): DealDirectoryInterface
    {
        $directory = $this->app->make(DealDirectoryInterface::class);
        self::assertInstanceOf(DealDirectoryInterface::class, $directory);

        return $directory;
    }

    // --------------------------------------------------- customerIdsWithActiveDeals

    public function test_that_the_candidate_query_excludes_all_lost_and_no_deal_customers(): void
    {
        $wonCustomer = $this->customer();
        $this->seedDeal($wonCustomer, ['status' => 'won']);

        $activeCustomer = $this->customer();
        $this->seedDeal($activeCustomer, ['status' => 'negotiations']);

        $lostOnlyCustomer = $this->customer();
        $this->seedDeal($lostOnlyCustomer, ['status' => 'lost', 'lost_reason' => 'Budget cut']);

        $noDealCustomer = $this->customer();

        $ids = $this->directory()->customerIdsWithActiveDeals();

        self::assertContains($wonCustomer, $ids, 'A Won customer is still included — rule 1 is not duplicated here.');
        self::assertContains($activeCustomer, $ids);
        self::assertNotContains($lostOnlyCustomer, $ids, 'All-Lost is unaffected by the clock (rule 4).');
        self::assertNotContains($noDealCustomer, $ids, 'No deals at all is unaffected by the clock (rule 5).');
    }

    public function test_that_a_customer_with_one_lost_and_one_active_deal_is_included(): void
    {
        $customerId = $this->customer();
        $this->seedDeal($customerId, ['status' => 'lost', 'lost_reason' => 'Budget cut']);
        $this->seedDeal($customerId, ['status' => 'quotation_sent']);

        self::assertContains($customerId, $this->directory()->customerIdsWithActiveDeals());
    }

    // ---------------------------------------------------------------------- run()

    public function test_that_a_stale_active_deal_becomes_no_response_once_swept(): void
    {
        $this->app->make(SystemLimitRepositoryInterface::class)->put(SystemLimit::StaleDealDays, '5');

        $customerId = $this->customer();
        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        $count = $this->app->make(RecomputeStaleCustomerStatuses::class)->run();

        self::assertSame(1, $count);
        self::assertSame('no_response', $this->customerStatus($customerId));
    }

    public function test_that_a_stale_active_deal_stays_prospect_while_unconfigured(): void
    {
        $customerId = $this->customer();
        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        $this->app->make(RecomputeStaleCustomerStatuses::class)->run();

        self::assertSame('prospect', $this->customerStatus($customerId));
    }

    public function test_that_a_won_customer_reconfirms_customer_rather_than_erroring(): void
    {
        $customerId = $this->customer();
        $this->seedDeal($customerId, ['status' => 'won']);
        DB::table('customers')->where('id', $customerId)->update(['customer_status' => 'customer']);

        $this->app->make(RecomputeStaleCustomerStatuses::class)->run();

        self::assertSame('customer', $this->customerStatus($customerId));
    }

    public function test_that_running_it_twice_is_idempotent(): void
    {
        $this->app->make(SystemLimitRepositoryInterface::class)->put(SystemLimit::StaleDealDays, '5');

        $customerId = $this->customer();
        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        $recompute = $this->app->make(RecomputeStaleCustomerStatuses::class);
        $recompute->run();
        $recompute->run();

        self::assertSame('no_response', $this->customerStatus($customerId));
    }

    // ----------------------------------------------------------------- the job

    public function test_that_the_job_recomputes_through_the_container(): void
    {
        $this->app->make(SystemLimitRepositoryInterface::class)->put(SystemLimit::StaleDealDays, '5');

        $customerId = $this->customer();
        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        $this->app->call([new RecomputeStaleCustomerStatusesJob, 'handle']);

        self::assertSame('no_response', $this->customerStatus($customerId));
    }

    // ------------------------------------------------------------ the schedule

    public function test_the_job_is_scheduled_daily(): void
    {
        $events = app(Schedule::class)->events();

        $scheduled = array_values(array_filter(
            $events,
            static fn (object $event): bool => str_contains(
                (string) ($event->description ?? ''),
                RecomputeStaleCustomerStatusesJob::class,
            ),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-02 job.');
        self::assertSame('0 0 * * *', $scheduled[0]->expression);
    }

    public function test_the_scheduled_job_dispatches_onto_the_maintenance_queue(): void
    {
        Queue::fake();

        $events = app(Schedule::class)->events();

        $scheduled = array_values(array_filter(
            $events,
            static fn (object $event): bool => str_contains(
                (string) ($event->description ?? ''),
                RecomputeStaleCustomerStatusesJob::class,
            ),
        ));

        self::assertCount(1, $scheduled);
        $scheduled[0]->run($this->app);

        Queue::assertPushedOn(QueueName::Maintenance->value, RecomputeStaleCustomerStatusesJob::class);
    }
}
