<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\Storage\Infrastructure\ClamAvScanner;
use App\Modules\Storage\Infrastructure\EicarSignatureScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 5.5 — virus scanning (SEC-15, §17 "Virus scanning: mandatory on every upload").
 *
 * The gate this proves is a negative one: nothing that has not come back
 * `clean` is served, whoever asks and whatever permission they hold.
 */
final class VirusScanningTest extends TestCase
{
    use RefreshDatabase;

    private const DEAL_ID = '0198f3d4-1a2b-7c3d-8e4f-5a6b7c8d9e0f';

    /**
     * The EICAR test string, assembled at run time.
     *
     * Never written contiguously into a source file on purpose: it is designed
     * to be detected, so a repository containing it can trip a developer's own
     * antivirus, a mail scanner, or a CI cache scan. Built in three pieces, the
     * file on disk holds none of them together.
     */
    private static function eicar(): string
    {
        return 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'
            .'-STANDARD-ANTIVIRUS-TEST-'
            .'FILE!$H+H*';
    }

    private function allowAll(): void
    {
        $this->app->bind(AttachmentPermissionInterface::class, fn (): AttachmentPermissionInterface => new class implements AttachmentPermissionInterface
        {
            public function mayView(AttachmentLink $link, string $actorId): bool
            {
                return true;
            }
        });
    }

    /** Stores real bytes, inserts the row, links it to a parent. Returns the id. */
    private function storedFile(string $contents, ScanStatus $status = ScanStatus::Pending): string
    {
        $service = $this->app->make(StorageServiceInterface::class);
        self::assertInstanceOf(StorageServiceInterface::class, $service);

        $source = tempnam(sys_get_temp_dir(), 'crm-scan-');
        self::assertIsString($source);
        file_put_contents($source, $contents);

        $path = $service->store(AttachmentParent::Deal, self::DEAL_ID, AllowedFileType::Pdf, $source);
        unlink($source);

        $id = Str::uuid7()->toString();

        DB::table('files')->insert([
            'id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
            'original_name' => 'offer.pdf',
            'mime_type' => AllowedFileType::Pdf->mimeType(),
            'size_bytes' => strlen($contents),
            'storage_path' => $path->value,
            'scan_status' => $status->value,
            'scanned_at' => $status === ScanStatus::Pending ? null : now(),
        ]);

        DB::table('deal_files')->insert(['deal_id' => self::DEAL_ID, 'file_id' => $id]);

        return $id;
    }

    private function scanner(): VirusScannerInterface
    {
        $scanner = $this->app->make(VirusScannerInterface::class);
        self::assertInstanceOf(VirusScannerInterface::class, $scanner);

        return $scanner;
    }

    private function scan(string $fileId): ScanStatus
    {
        $useCase = $this->app->make(ScanStoredFile::class);
        self::assertInstanceOf(ScanStoredFile::class, $useCase);

        return $useCase->scan($fileId);
    }

    private function statusOf(string $id): string
    {
        $status = DB::table('files')->where('id', $id)->value('scan_status');
        self::assertIsString($status);

        return $status;
    }

    private function scannedAtOf(string $id): ?string
    {
        $at = DB::table('files')->where('id', $id)->value('scanned_at');

        if ($at === null) {
            return null;
        }

        self::assertIsString($at);

        return $at;
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.secure_uploads.root');

        if (is_string($root)) {
            $found = glob($root.'/*/*/*/'.self::DEAL_ID.'/*');

            foreach ($found === false ? [] : $found as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            // Prune what is left. rmdir refuses a non-empty directory, so a
            // month holding real attachments is untouched — the same guard
            // StorageServiceTest uses.
            foreach ($found === false ? [] : $found as $file) {
                @rmdir(dirname($file));
                @rmdir(dirname($file, 2));
                @rmdir(dirname($file, 3));
                @rmdir(dirname($file, 4));
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- the statuses

    public function test_the_three_statuses_are_exactly_what_the_database_allows(): void
    {
        // Point 5.1 wrote a CHECK constraint over these three strings. Two lists
        // that must agree and are written in different files is how they stop
        // agreeing, so this asserts against the constraint itself.
        $definition = DB::selectOne(
            "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'files_scan_status_check'"
        );
        self::assertIsObject($definition);

        foreach (ScanStatus::cases() as $status) {
            self::assertStringContainsString("'{$status->value}'", (string) $definition->def);   // @phpstan-ignore-line
        }

        self::assertCount(3, ScanStatus::cases());
    }

    // ---------------------------------------------------------------- scanning

    public function test_a_clean_file_scans_clean(): void
    {
        self::assertSame(ScanStatus::Clean, $this->scan($this->storedFile('%PDF-1.4 harmless %%EOF')));
    }

    public function test_the_eicar_test_file_is_reported_infected(): void
    {
        self::assertSame(ScanStatus::Infected, $this->scan($this->storedFile(self::eicar())));
    }

    public function test_eicar_is_found_even_when_it_is_buried_in_a_larger_file(): void
    {
        $contents = str_repeat('A', 100_000).self::eicar().str_repeat('B', 100_000);

        self::assertSame(ScanStatus::Infected, $this->scan($this->storedFile($contents)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function boundaryOffsets(): array
    {
        // The scanner reads in 64 KiB chunks. A signature that lands wholly
        // inside one chunk proves nothing about the carry-over between them —
        // which is what the first version of the "buried" test did, and why
        // deleting the overlap changed nothing. These offsets split the 68-byte
        // signature across the boundary at every interesting position.
        return [
            'one byte before the boundary' => [65536 - 1],
            'ten bytes before' => [65536 - 10],
            'half in each chunk' => [65536 - 34],
            'one byte into the second chunk' => [65536 - 67],
            'across the second boundary' => [131072 - 20],
        ];
    }

    #[DataProvider('boundaryOffsets')]
    public function test_eicar_is_found_when_it_straddles_a_read_boundary(int $offset): void
    {
        $contents = str_repeat('A', $offset).self::eicar().str_repeat('B', 4096);

        self::assertSame(ScanStatus::Infected, $this->scan($this->storedFile($contents)));
    }

    public function test_the_result_and_its_time_are_recorded(): void
    {
        $id = $this->storedFile('%PDF-1.4 harmless %%EOF');

        self::assertNull($this->scannedAtOf($id));

        $this->scan($id);

        self::assertSame('clean', $this->statusOf($id));
        self::assertNotNull($this->scannedAtOf($id));
    }

    public function test_an_infected_file_is_marked_and_its_bytes_are_kept(): void
    {
        // Marked, not deleted. §17 says nothing about destroying the evidence,
        // and an infected upload is the one file an administrator will want to
        // look at. Quarantine here means "unreachable", enforced on the way out.
        $id = $this->storedFile(self::eicar());
        $this->scan($id);

        self::assertSame('infected', $this->statusOf($id));

        $storage = $this->app->make(StorageServiceInterface::class);
        self::assertInstanceOf(StorageServiceInterface::class, $storage);

        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);
        $found = glob($root.'/*/*/*/'.self::DEAL_ID.'/*');
        self::assertNotFalse($found);
        self::assertCount(1, $found);
    }

    // ---------------------------------------------------------------- the gate

    public function test_a_clean_file_becomes_downloadable(): void
    {
        $this->allowAll();
        $id = $this->storedFile('%PDF-1.4 harmless %%EOF');

        $this->actingAs(User::factory()->create())
            ->get("/api/v1/files/{$id}/download")->assertNotFound();

        $this->scan($id);

        $this->actingAs(User::factory()->create())
            ->get("/api/v1/files/{$id}/download")->assertOk();
    }

    public function test_an_infected_file_is_never_served(): void
    {
        $this->allowAll();
        $id = $this->storedFile(self::eicar());
        $this->scan($id);

        $this->actingAs(User::factory()->create())
            ->get("/api/v1/files/{$id}/download")->assertNotFound();
    }

    public function test_a_pending_file_is_never_served(): void
    {
        $this->allowAll();
        $id = $this->storedFile('%PDF-1.4 harmless %%EOF');

        $this->actingAs(User::factory()->create())
            ->get("/api/v1/files/{$id}/download")->assertNotFound();
    }

    // ---------------------------------------------------------------- failure modes

    public function test_a_scanner_that_cannot_run_does_not_report_clean(): void
    {
        // The failure that matters. An antivirus that is down and answers
        // "clean" is worse than none, because the status column then says the
        // file was checked.
        $scanner = new ClamAvScanner('127.0.0.1', 1, 1);

        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);

        $this->expectException(ScannerUnavailable::class);

        try {
            $scanner->scan($stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_an_unavailable_scanner_leaves_the_file_unscanned_rather_than_clean(): void
    {
        $this->app->bind(VirusScannerInterface::class, fn (): VirusScannerInterface => new class implements VirusScannerInterface
        {
            public function scan($contents): ScanStatus
            {
                throw new ScannerUnavailable('clamd is not answering');
            }
        });

        $id = $this->storedFile('%PDF-1.4 harmless %%EOF');

        try {
            $this->scan($id);
            self::fail('The use case swallowed a scanner failure.');
        } catch (ScannerUnavailable) {
            // expected
        }

        self::assertSame('pending', $this->statusOf($id));
        self::assertNull($this->scannedAtOf($id));
    }

    // ---------------------------------------------------------------- what is bound

    public function test_the_driver_is_chosen_by_configuration(): void
    {
        config(['files.scanner' => 'clamav']);
        self::assertInstanceOf(ClamAvScanner::class, $this->scanner());

        config(['files.scanner' => 'eicar']);
        self::assertInstanceOf(EicarSignatureScanner::class, $this->scanner());
    }

    /**
     * The limitation, asserted rather than written in a comment.
     *
     * `EicarSignatureScanner` knows one signature. It is a wiring check for CI
     * and local work, not an antivirus, and a test that says so out loud is
     * harder to forget than a README paragraph.
     */
    public function test_the_ci_driver_recognises_nothing_but_eicar(): void
    {
        config(['files.scanner' => 'eicar']);

        $realish = "\x7fELF\x02\x01\x01".str_repeat("\x00", 64).'this would be malware';

        $stream = fopen('php://memory', 'rb+');
        self::assertIsResource($stream);
        fwrite($stream, $realish);
        rewind($stream);

        self::assertSame(
            ScanStatus::Clean,
            $this->scanner()->scan($stream),
            'If this ever fails, the CI driver grew a second signature and the comment above is a lie.'
        );

        fclose($stream);
    }

    public function test_the_environment_sample_names_the_scanner_and_says_which_one_production_needs(): void
    {
        // crm/.env.example, not the repository root's. Laravel reads crm/.env
        // and the php service injects no environment of its own, so a knob
        // documented only at the root is a knob env() never sees — which is
        // what had happened to FILES_MAX_SIZE_BYTES since Point 5.1.
        $env = (string) file_get_contents(dirname(__DIR__, 3).'/.env.example');

        self::assertStringContainsString('FILES_VIRUS_SCANNER', $env);
        self::assertStringContainsString('clamav', $env);
    }

    public function test_storage_is_covered_by_the_module_boundary_config(): void
    {
        // The debt Point 5.2 recorded, now paid: AP-02 enforcement can see this
        // module, so another module reaching into it is a violation rather than
        // an uncovered dependency that does not fail the build.
        self::assertStringContainsString(
            'app/Modules/Storage',
            (string) file_get_contents(dirname(__DIR__, 3).'/deptrac.modules.yaml'),
        );
    }
}
