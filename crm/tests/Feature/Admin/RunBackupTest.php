<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Application\Backups\RunBackup;
use App\Modules\Admin\Domain\Backups\BackupFailed;
use App\Modules\Admin\Domain\Backups\BackupSucceeded;
use App\Modules\Admin\Presentation\RunBackupJob;
use App\Support\Queue\QueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PharData;
use Tests\TestCase;

/**
 * Module 16, Point 5 — `J-10` database_backup, minimal (`D-153`: `BK-01` without
 * the search index, which is not running, and `BK-04`).
 *
 * One run writes `<backups.path>/<YYYYMMDD-HHMMSS>/` with `database.dump` (the
 * database in PostgreSQL's own custom format — `pg_dump -Fc`, the shape
 * `pg_restore` reads) and `files.tar.gz` (the `secure_uploads` disk's root),
 * records each artefact's SHA-256 and size in `backup_runs`, and announces
 * `BackupSucceeded`. Anything that goes wrong leaves no half-written folder, a
 * `failed` row that says why, and `BackupFailed` — the alert's cause. A day with
 * a succeeded run already is not backed up twice: the startup catch-up and the
 * nightly run share the day. Retention (`BK-03`), restore tests (`BK-06`) and
 * off-machine copies (`BK-08`) are not here (`D-153`).
 *
 * Real `pg_dump`, real files: a stand-in would prove the bookkeeping and not
 * that a backup exists. The database it dumps is the test database.
 */
final class RunBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $backups;

    private string $files;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/crm-backup-test-'.bin2hex(random_bytes(4));
        $this->backups = $base.'/backups';
        $this->files = $base.'/files';
        mkdir($this->backups, 0700, true);
        mkdir($this->files.'/customers', 0700, true);
        file_put_contents($this->files.'/customers/contract.pdf', '%PDF-1.4 a stored file');

        Config::set('backups.path', $this->backups);
        Config::set('filesystems.disks.secure_uploads.root', $this->files);
        Carbon::setTestNow('2026-10-22 02:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf '.escapeshellarg(dirname($this->backups)));
        parent::tearDown();
    }

    public function test_that_a_run_writes_the_database_and_the_files_with_their_checksums(): void
    {
        Event::fake([BackupSucceeded::class, BackupFailed::class]);

        self::assertSame('succeeded', $this->app->make(RunBackup::class)->run());

        /** @var object{id: string, status: string, finished_at: string|null, database_path: string, database_sha256: string, database_bytes: int, files_path: string, files_sha256: string, files_bytes: int} $row */
        $row = DB::table('backup_runs')->sole();
        self::assertSame('succeeded', $row->status);
        self::assertNotNull($row->finished_at);
        self::assertSame('20261022-020000/database.dump', $row->database_path);
        self::assertSame('20261022-020000/files.tar.gz', $row->files_path);

        $dump = $this->backups.'/'.$row->database_path;
        $archive = $this->backups.'/'.$row->files_path;
        self::assertFileExists($dump);
        self::assertFileExists($archive);
        self::assertSame(hash_file('sha256', $dump), $row->database_sha256);
        self::assertSame(hash_file('sha256', $archive), $row->files_sha256);
        self::assertSame(filesize($dump), $row->database_bytes);
        self::assertSame(filesize($archive), $row->files_bytes);

        Event::assertDispatched(BackupSucceeded::class, fn (BackupSucceeded $e): bool => $e->runId === $row->id);
        Event::assertNotDispatched(BackupFailed::class);
    }

    /** The dump is PostgreSQL's custom format, which starts with its own signature, and holds this database's tables. */
    public function test_that_the_dump_is_a_real_postgres_custom_dump_of_this_database(): void
    {
        $this->app->make(RunBackup::class)->run();
        /** @var string $path */
        $path = DB::table('backup_runs')->value('database_path');
        $dump = (string) file_get_contents($this->backups.'/'.$path);

        self::assertStringStartsWith('PGDMP', $dump);
        self::assertStringContainsString('backup_runs', $dump);
        self::assertStringContainsString('quotations', $dump);
    }

    public function test_that_the_archive_holds_the_stored_files(): void
    {
        $this->app->make(RunBackup::class)->run();
        /** @var string $path */
        $path = DB::table('backup_runs')->value('files_path');

        $names = [];
        /** @var \PharFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new PharData($this->backups.'/'.$path)) as $file) {
            $names[] = str_replace('\\', '/', $file->getPathname());
        }

        self::assertNotEmpty(array_filter($names, static fn (string $name): bool => str_ends_with($name, 'customers/contract.pdf')));
    }

    /** The catch-up on start and the nightly run share a day: one backup a day, not two. */
    public function test_that_a_day_with_a_succeeded_run_is_not_backed_up_twice(): void
    {
        $this->app->make(RunBackup::class)->run();

        Carbon::setTestNow('2026-10-22 09:15:00');
        self::assertSame('skipped', $this->app->make(RunBackup::class)->run());
        self::assertSame(1, DB::table('backup_runs')->count());

        Carbon::setTestNow('2026-10-23 02:00:00');
        self::assertSame('succeeded', $this->app->make(RunBackup::class)->run());
        self::assertSame(2, DB::table('backup_runs')->count());
    }

    /** A database that cannot be reached: a failed row that says why, no half-written folder, BackupFailed. */
    public function test_that_a_failed_dump_leaves_a_reason_and_no_partial_backup(): void
    {
        Event::fake([BackupSucceeded::class, BackupFailed::class]);
        Config::set('database.connections.pgsql.database', 'no_such_database_'.bin2hex(random_bytes(3)));

        self::assertSame('failed', $this->app->make(RunBackup::class)->run());

        /** @var object{id: string, status: string, error: string|null, database_path: string|null} $row */
        $row = DB::table('backup_runs')->sole();
        self::assertSame('failed', $row->status);
        self::assertNotNull($row->error);
        self::assertStringContainsString('no_such_database', $row->error);
        self::assertNull($row->database_path);
        self::assertSame([], array_values(array_diff(scandir($this->backups) ?: [], ['.', '..'])), 'A failed run must not leave a half-written backup.');

        Event::assertDispatched(BackupFailed::class, fn (BackupFailed $e): bool => $e->runId === $row->id && str_contains($e->error, 'no_such_database'));
        Event::assertNotDispatched(BackupSucceeded::class);
    }

    /** A failure does not count as the day's backup: the next run tries again. */
    public function test_that_a_failed_day_is_tried_again(): void
    {
        $database = config('database.connections.pgsql.database');
        Config::set('database.connections.pgsql.database', 'no_such_database');
        $this->app->make(RunBackup::class)->run();
        Config::set('database.connections.pgsql.database', $database);

        Carbon::setTestNow('2026-10-22 09:15:00');
        self::assertSame('succeeded', $this->app->make(RunBackup::class)->run());
    }

    public function test_that_a_missing_pg_dump_is_a_failure_that_says_so(): void
    {
        Config::set('backups.pg_dump', '/nonexistent/pg_dump');

        self::assertSame('failed', $this->app->make(RunBackup::class)->run());

        $error = DB::table('backup_runs')->value('error');
        self::assertIsString($error);
        self::assertStringContainsString('pg_dump', $error);
    }

    /** §15: J-10 daily, catch-up ✅ — after J-16 on start. 02:00 UTC, clear of J-09's 00:30 and J-05/J-07's 01:00. */
    public function test_that_j10_is_scheduled_daily_at_two_onto_maintenance_and_caught_up_on_start(): void
    {
        Queue::fake();

        $scheduled = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (object $event): bool => str_contains((string) ($event->description ?? ''), RunBackupJob::class),
        ));

        self::assertCount(1, $scheduled, 'Expected exactly one scheduled J-10 job.');
        self::assertSame('0 2 * * *', $scheduled[0]->expression);

        $scheduled[0]->run($this->app);
        Queue::assertPushedOn(QueueName::Maintenance->value, RunBackupJob::class);
    }

    public function test_that_the_job_runs_it_through_the_container(): void
    {
        $this->app->call([new RunBackupJob, 'handle']);

        self::assertSame('succeeded', DB::table('backup_runs')->value('status'));
    }
}
