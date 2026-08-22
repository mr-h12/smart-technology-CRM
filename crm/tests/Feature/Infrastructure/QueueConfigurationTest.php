<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Support\Queue\QueueName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four queues §15.1 names, held in one place and checked against the
 * services that actually consume them.
 *
 * A queue name is a string in two files that never see each other: PHP
 * dispatches to it, and a `queue:work --queue=` flag in docker-compose.yml
 * drains it. Nothing connects them, so a typo on either side does not fail —
 * it produces a queue with a producer and no consumer, and jobs that sit in
 * Redis looking exactly like jobs that have not run yet. That is the defect
 * this file exists to make impossible.
 *
 * docker-compose.yml is not inside the bind mount — the mount is ./crm, and
 * the compose file is its parent's — so the php service mounts it read-only at
 * COMPOSE_PATH for this check. If it is missing these tests fail rather than
 * skip: a cross-file check that quietly stops running is worse than no check,
 * because the file still looks green.
 */
final class QueueConfigurationTest extends TestCase
{
    /**
     * Read-only mount added to the php service and to the CI test container.
     */
    private const COMPOSE_PATH = '/opt/crm/docker-compose.yml';

    /**
     * §15.1, in the priority order the table gives: 1 critical, 2 pdf,
     * 3 reports, 4 maintenance.
     *
     * @var list<string>
     */
    private const DOCUMENTED = ['critical', 'pdf', 'reports', 'maintenance'];

    // ------------------------------------------------------------ the PHP side

    public function test_the_four_documented_queues_are_configured(): void
    {
        self::assertSame(
            self::DOCUMENTED,
            config('queue.crm.queues'),
            '§15.1 names four queues in a priority order. The order is part of the requirement, '
            .'not presentation: it is what worker capacity is allocated against.',
        );
    }

    public function test_the_typed_names_and_the_configuration_agree(): void
    {
        // AP-08 puts the values in config; PHPStan level 10 wants a type at the
        // call site. Both exist, so the one thing that can go wrong is that they
        // stop matching — and it is compared against config directly, not
        // against DOCUMENTED, because the relationship this test is named for is
        // the one between the two of them.
        self::assertSame(
            config('queue.crm.queues'),
            array_map(static fn (QueueName $q): string => $q->value, QueueName::all()),
            'QueueName and config/queue.php must name the same queues in the same order.',
        );
    }

    public function test_each_queue_carries_the_priority_the_table_gives_it(): void
    {
        self::assertSame(1, QueueName::Critical->priority());
        self::assertSame(2, QueueName::Pdf->priority());
        self::assertSame(3, QueueName::Reports->priority());
        self::assertSame(4, QueueName::Maintenance->priority());
    }

    public function test_the_priorities_are_a_strict_order_with_no_ties(): void
    {
        $priorities = array_map(static fn (QueueName $q): int => $q->priority(), QueueName::all());

        self::assertSame(
            $priorities,
            array_values(array_unique($priorities)),
            'Two queues at the same priority means §15.1 order is undefined between them.',
        );
    }

    public function test_the_deployed_queue_backend_is_redis(): void
    {
        // §14.2 gives Redis sessions, cache, queues, rate limiting and locks,
        // and all four worker services run `queue:work redis`. The skeleton
        // default is `database`, which would work and be wrong.
        //
        // Read from .env.example rather than from config('queue.default'),
        // because phpunit.xml deliberately puts the suite on `sync` so jobs run
        // inline. Asserting the running value would either be asserting `sync`
        // — which says nothing about what deploys — or would require undoing
        // that, which is a worse trade for a configuration check.
        self::assertMatchesRegularExpression(
            '/^QUEUE_CONNECTION=redis$/m',
            self::envExample(),
            '.env.example is what a deployment copies; it must name redis.',
        );

        self::assertSame('redis', config('queue.connections.redis.driver'));
        self::assertSame('sync', config('queue.default'), 'The suite runs jobs inline by design.');
    }

    public function test_the_environment_template_pins_the_fallback_queue(): void
    {
        // Left to the config default the value is still correct, but nothing
        // shows an operator that this knob exists or that `default` is a trap.
        self::assertMatchesRegularExpression(
            '/^REDIS_QUEUE=(critical|pdf|reports|maintenance)$/m',
            self::envExample(),
            'REDIS_QUEUE must be present and name one of the four queues.',
        );
    }

    public function test_a_job_dispatched_without_a_queue_still_lands_on_one_a_worker_drains(): void
    {
        // Laravel's redis connection defaults to a queue literally named
        // `default`, and no worker consumes it. A job dispatched without
        // ->onQueue() would be accepted, stored, and never run — the failure is
        // silent on both sides.
        $fallback = config('queue.connections.redis.queue');

        self::assertIsString($fallback);
        self::assertContains(
            $fallback,
            self::DOCUMENTED,
            "The redis connection falls back to '{$fallback}', which no worker in docker-compose.yml "
            .'drains. §15.1 has four queues and this must be one of them.',
        );
    }

    // -------------------------------------------------------- the compose side

    public function test_the_compose_file_is_readable_from_here(): void
    {
        self::assertFileExists(
            self::COMPOSE_PATH,
            'docker-compose.yml must be mounted read-only at '.self::COMPOSE_PATH.' for the worker '
            .'cross-check to run. Without it the checks below cannot fail, which is the same as not '
            .'having them.',
        );
    }

    public function test_every_documented_queue_has_exactly_one_worker(): void
    {
        $consumed = self::consumedQueues();

        self::assertSame(
            self::DOCUMENTED,
            array_values($consumed),
            'Each of §15.1\'s four queues needs a worker draining it, and no worker may drain a '
            .'queue the application never dispatches to.',
        );
    }

    public function test_the_worker_services_are_named_after_the_queues_they_drain(): void
    {
        foreach (self::consumedQueues() as $service => $queue) {
            self::assertSame(
                "worker-{$queue}",
                $service,
                "Service '{$service}' drains '{$queue}'. A worker whose name and --queue disagree is "
                .'the one thing nobody rereads.',
            );
        }
    }

    #[DataProvider('documentedQueues')]
    public function test_no_worker_sits_behind_a_compose_profile(string $queue): void
    {
        $block = self::serviceBlock("worker-{$queue}");

        self::assertDoesNotMatchRegularExpression(
            '/^\s+profiles:/m',
            $block,
            'ST-01 requires every service enabled on boot with no manual startup. A profile on '
            ."worker-{$queue} means `docker compose up -d` leaves that queue with no consumer.",
        );
    }

    #[DataProvider('documentedQueues')]
    public function test_each_worker_retries_a_fixed_number_of_times(string $queue): void
    {
        // §15.1: "a fixed retry count then Failed in Queue Monitor". Without
        // --tries a worker retries forever and nothing ever reaches Failed.
        self::assertMatchesRegularExpression(
            '/--tries=\d+/',
            self::serviceBlock("worker-{$queue}"),
            "worker-{$queue} must bound its retries; §15.1 requires a fixed count then Failed.",
        );
    }

    #[DataProvider('documentedQueues')]
    public function test_each_worker_waits_for_redis_and_postgres(string $queue): void
    {
        // ST-03: each service waits for its dependency. A worker that starts
        // before Redis is healthy exits and takes its queue down with it.
        $block = self::serviceBlock("worker-{$queue}");

        self::assertMatchesRegularExpression('/redis:\s*\{\s*condition:\s*service_healthy\s*\}/', $block);
        self::assertMatchesRegularExpression('/postgres:\s*\{\s*condition:\s*service_healthy\s*\}/', $block);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentedQueues(): iterable
    {
        foreach (self::DOCUMENTED as $queue) {
            yield $queue => [$queue];
        }
    }

    // ------------------------------------------------------------------ reading

    private static function envExample(): string
    {
        $path = base_path('.env.example');
        $contents = file_get_contents($path);

        self::assertIsString($contents, "Could not read {$path}");

        return $contents;
    }

    /**
     * Every `worker-*` service, mapped to the queue its command drains, in the
     * order the file declares them.
     *
     * @return array<string, string>
     */
    private static function consumedQueues(): array
    {
        $consumed = [];

        foreach (self::serviceBlocks() as $service => $block) {
            if (! str_starts_with($service, 'worker-')) {
                continue;
            }

            $matched = preg_match('/--queue=([a-z0-9_-]+)/', $block, $m);
            self::assertSame(1, $matched, "Service '{$service}' has no --queue flag.");

            $consumed[$service] = $m[1];
        }

        self::assertNotSame([], $consumed, 'docker-compose.yml declares no worker services at all.');

        return $consumed;
    }

    private static function serviceBlock(string $service): string
    {
        $blocks = self::serviceBlocks();

        self::assertArrayHasKey($service, $blocks, "docker-compose.yml has no service '{$service}'.");

        return $blocks[$service];
    }

    /**
     * docker-compose.yml split by top-level service, by indentation.
     *
     * Regex rather than a YAML parser because neither ext-yaml nor
     * symfony/yaml is installed in this image — checked, not assumed. The file
     * is read structurally enough for the question being asked: a service key
     * is two spaces in, and everything more deeply indented belongs to it.
     *
     * @return array<string, string>
     */
    private static function serviceBlocks(): array
    {
        $contents = file_get_contents(self::COMPOSE_PATH);
        self::assertIsString($contents, 'Could not read '.self::COMPOSE_PATH);

        $lines = explode("\n", $contents);

        $blocks = [];
        $current = null;
        $inServices = false;

        foreach ($lines as $line) {
            if (preg_match('/^([a-z][a-z0-9_-]*):/', $line, $m) === 1) {
                $inServices = $m[1] === 'services';
                $current = null;

                continue;
            }

            if (! $inServices) {
                continue;
            }

            if (preg_match('/^  ([a-z][a-z0-9_-]*):\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $blocks[$current] = '';

                continue;
            }

            if ($current !== null) {
                $blocks[$current] .= $line."\n";
            }
        }

        return $blocks;
    }
}
