<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Support\Queue\Jobs\InfrastructureProbeFailed;
use App\Support\Queue\Jobs\InfrastructureProbeJob;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A job actually reaching Redis and actually coming back out.
 *
 * Point 8.1 proved the four queue names are consistent between config/queue.php
 * and the worker commands. Consistent is not running: nothing had ever been
 * dispatched, and a name that agrees everywhere still proves nothing about
 * whether Redis accepts a payload or a worker executes it.
 *
 * Queue::fake() is deliberately absent. It records dispatches and runs nothing,
 * so it would assert that Laravel's dispatcher works — which was never in
 * question — while leaving Redis, the serializer, the worker loop and
 * failed_jobs entirely untested. Every job here is pushed to a real Redis list
 * and drained by a real `queue:work` pass.
 *
 * phpunit.xml puts the suite's default connection on `sync` so ordinary tests
 * run jobs inline. That is left alone; these tests name the redis connection
 * explicitly, which is also what proves the round trip is real.
 */
final class RealQueueExecutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Redis database 15, not 0.
     *
     * Not tidiness — correctness. `docker compose up -d` now runs four workers
     * (point 8.1) and they are draining `queues:critical` and friends on
     * database 0 right now. A test pushing there would have its job taken by a
     * container instead of by the worker pass under test, and would fail or
     * pass depending on which won the race.
     */
    private const TEST_REDIS_DATABASE = '15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedFromTheRunningWorkers();

        Redis::connection()->flushdb();
    }

    // ------------------------------------------------------------ the isolation

    public function test_the_suite_never_shares_a_redis_database_with_the_running_workers(): void
    {
        self::assertSame(
            self::TEST_REDIS_DATABASE,
            self::configuredRedisDatabase(),
            'The four worker containers drain database 0. Sharing it makes every queue test a race '
            .'against a container that will happily execute the job first.',
        );
    }

    public function test_the_connection_under_test_is_a_real_redis_queue(): void
    {
        $connection = Queue::connection('redis');

        self::assertInstanceOf(QueueContract::class, $connection);
        self::assertInstanceOf(
            RedisQueue::class,
            $connection,
            'If this is ever a fake or a sync queue, everything below stops proving anything.',
        );
    }

    // ------------------------------------------------------------- the happy path

    #[DataProvider('everyQueue')]
    public function test_a_job_is_pushed_to_redis_and_executed_on_the_queue_it_names(string $queue): void
    {
        $token = self::token();

        self::assertSame(0, self::depth($queue), 'The queue must start empty.');

        InfrastructureProbeJob::dispatch($token)->onConnection('redis')->onQueue($queue);

        // Before any worker runs: the payload is in Redis, and nothing has
        // executed. This is the assertion Queue::fake() cannot make.
        self::assertSame(1, self::depth($queue), "Nothing reached Redis list for '{$queue}'.");
        self::assertSame([], self::receipts($token), 'The job ran during dispatch — that is sync, not redis.');

        self::work($queue);

        $receipts = self::receipts($token);
        self::assertCount(1, $receipts, "No receipt: the worker did not execute the job on '{$queue}'.");

        self::assertSame($queue, $receipts[0]['queue'], 'The job ran, but not on the queue it was given.');
        self::assertSame(1, $receipts[0]['attempt']);
        self::assertSame(0, self::depth($queue), 'The queue must be empty once the job has been handled.');
    }

    #[DataProvider('everyQueue')]
    public function test_a_worker_on_one_queue_never_drains_another(string $queue): void
    {
        // The single defect 8.1 could not catch: names that agree on paper while
        // a worker reads the wrong list.
        $token = self::token();

        InfrastructureProbeJob::dispatch($token)->onConnection('redis')->onQueue($queue);

        foreach (QueueName::cases() as $other) {
            if ($other->value === $queue) {
                continue;
            }

            self::work($other->value);
        }

        self::assertSame([], self::receipts($token), "A worker on another queue executed the '{$queue}' job.");
        self::assertSame(1, self::depth($queue), "The job left the '{$queue}' queue without running.");
    }

    // ----------------------------------------------------------- the failure path

    public function test_a_failing_job_is_retried_to_the_bound_and_then_recorded_as_failed(): void
    {
        // §15.1: a fixed retry count, then Failed. Unbounded retries mean a
        // poisoned job never reaches Queue Monitor and never stops costing.
        $token = self::token();

        InfrastructureProbeJob::dispatch($token, true)->onConnection('redis')->onQueue(QueueName::Maintenance->value);

        self::work(QueueName::Maintenance->value);
        self::assertSame(0, self::failedCount(), 'Failed on the first attempt — the retry bound is not being applied.');

        self::work(QueueName::Maintenance->value);
        self::assertSame(0, self::failedCount(), 'Failed on the second attempt; --tries=3 allows three.');

        self::work(QueueName::Maintenance->value);

        self::assertSame(1, self::failedCount(), 'Three attempts were spent and nothing reached failed_jobs.');
        self::assertCount(3, self::receipts($token), 'The job body should have run three times.');
        self::assertSame([1, 2, 3], array_column(self::receipts($token), 'attempt'));
        self::assertSame(0, self::depth(QueueName::Maintenance->value), 'A failed job must not stay queued.');
    }

    public function test_the_failure_record_names_the_queue_and_the_reason(): void
    {
        $token = self::token();

        InfrastructureProbeJob::dispatch($token, true)->onConnection('redis')->onQueue(QueueName::Critical->value);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::work(QueueName::Critical->value);
        }

        $failure = DB::table('failed_jobs')->first();
        self::assertNotNull($failure);

        self::assertSame('redis', $failure->connection);
        self::assertSame(QueueName::Critical->value, $failure->queue);
        $exception = $failure->exception;
        self::assertIsString($exception);
        self::assertStringContainsString(InfrastructureProbeFailed::class, $exception);
        self::assertStringContainsString($token, $exception);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyQueue(): iterable
    {
        foreach (QueueName::cases() as $queue) {
            yield $queue->value => [$queue->value];
        }
    }

    // ------------------------------------------------------------------- helpers

    /**
     * One real worker pass over one real queue, with §15.1's retry bound — the
     * same `--tries=3` docker-compose.yml gives every worker service.
     */
    private static function work(string $queue): void
    {
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 3,
            // Only the idle wait, and only because a pass over an empty queue
            // otherwise sleeps three seconds — 36s across the tests below. It
            // changes nothing a job does; the production workers keep the
            // default.
            '--sleep' => 0,
        ]));
    }

    private static function depth(string $queue): int
    {
        $size = Queue::connection('redis')->size($queue);

        return (int) $size;
    }

    /**
     * @return list<array{queue: string, attempt: int, executed_at: string}>
     */
    private static function receipts(string $token): array
    {
        $raw = Redis::connection()->lrange(InfrastructureProbeJob::RECEIPT_PREFIX.$token, 0, -1);
        self::assertIsArray($raw);

        $receipts = [];

        foreach ($raw as $entry) {
            self::assertIsString($entry);
            $decoded = json_decode($entry, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertIsString($decoded['queue'] ?? null);
            self::assertIsInt($decoded['attempt'] ?? null);
            self::assertIsString($decoded['executed_at'] ?? null);

            $receipts[] = [
                'queue' => $decoded['queue'],
                'attempt' => $decoded['attempt'],
                'executed_at' => $decoded['executed_at'],
            ];
        }

        return $receipts;
    }

    private static function failedCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    private static function configuredRedisDatabase(): string
    {
        $database = config('database.redis.default.database');
        self::assertIsScalar($database);

        return (string) $database;
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function assertIsolatedFromTheRunningWorkers(): void
    {
        self::assertSame(
            self::TEST_REDIS_DATABASE,
            self::configuredRedisDatabase(),
            'Refusing to flush a Redis database the running workers may be using.',
        );
    }
}
