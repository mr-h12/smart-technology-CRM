<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use App\Modules\Storage\Infrastructure\LocalStorageService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 5.2 — storage behind an interface, local driver first.
 *
 * §17 fixes four things about a stored file and this test holds all four: the
 * path shape `/{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext`, the UUID
 * name with the original kept only in the database, the root outside the web
 * root, and no direct access. §14.2 adds the fifth — "local file system behind
 * an abstraction layer" — which is the reason the callers are scanned rather
 * than trusted.
 */
final class StorageServiceTest extends TestCase
{
    private const SAMPLE = 'a supplier quotation, as bytes';

    /** A fixed instant so the year and month in a path are facts, not the clock's mood. */
    private const NOW = '2026-08-22T09:15:00+00:00';

    private const PARENT_ID = '0198f3d4-1a2b-7c3d-8e4f-5a6b7c8d9e0f';

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    /**
     * The suite writes to the real attachment volume, not to a fake.
     *
     * A faked disk would prove that Laravel's fake works; §14.2 asks whether
     * *this* driver puts bytes where §17 says. The price is that the test
     * leaves litter in a directory a deployment also uses, so everything it
     * wrote is removed here — every path under the fixed test parent id, in any
     * year and month, plus the empty folders left behind.
     */
    protected function tearDown(): void
    {
        foreach ($this->scratch as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $root = config('filesystems.disks.secure_uploads.root');

        if (is_string($root)) {
            $found = glob($root.'/*/*/*/'.self::PARENT_ID);

            foreach ($found === false ? [] : $found as $directory) {
                $stored = glob($directory.'/*');

                foreach ($stored === false ? [] : $stored as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                // Up through entity_type, month and year. rmdir refuses a
                // non-empty directory, which is exactly the guard wanted here:
                // a month that holds real attachments is left alone.
                @rmdir($directory);
                @rmdir(dirname($directory));
                @rmdir(dirname($directory, 2));
                @rmdir(dirname($directory, 3));
            }
        }

        parent::tearDown();
    }

    private function service(): StorageServiceInterface
    {
        $service = $this->app->make(StorageServiceInterface::class);
        self::assertInstanceOf(StorageServiceInterface::class, $service);

        return $service;
    }

    /** Writes $contents to a scratch file and returns its path, the way an upload arrives. */
    private function source(string $contents = self::SAMPLE): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return $path;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    // ---------------------------------------------------------------- the contract

    public function test_the_contract_resolves_to_the_local_driver(): void
    {
        self::assertInstanceOf(LocalStorageService::class, $this->service());
    }

    public function test_the_domain_contract_names_no_framework_type(): void
    {
        $contract = self::root().'/app/Modules/Storage/Domain/Contracts/StorageServiceInterface.php';
        self::assertFileExists($contract);

        $source = (string) file_get_contents($contract);

        foreach (['Illuminate\\', 'Symfony\\', 'Laravel\\', 'UploadedFile', 'Filesystem'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                "The Domain layer may depend on nothing (deptrac.layers.yaml, Coding Standards §3.1), and the contract names {$forbidden}."
            );
        }
    }

    // ---------------------------------------------------------------- the path shape

    public function test_the_path_follows_the_documented_shape(): void
    {
        $path = $this->service()->store(
            AttachmentParent::SupplierQuotation,
            self::PARENT_ID,
            'offer.pdf',
            $this->source(),
        );

        self::assertMatchesRegularExpression(
            '#^2026/08/supplier_quotation/'.self::PARENT_ID.'/[0-9a-f-]{36}\.pdf$#',
            $path->value,
            '§17: /{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext'
        );
    }

    /**
     * @return array<string, array{AttachmentParent, string}>
     */
    public static function parents(): array
    {
        return [
            'deal' => [AttachmentParent::Deal, 'deal'],
            'supplier quotation' => [AttachmentParent::SupplierQuotation, 'supplier_quotation'],
            'purchase order' => [AttachmentParent::PurchaseOrder, 'purchase_order'],
            'report' => [AttachmentParent::Report, 'report'],
        ];
    }

    #[DataProvider('parents')]
    public function test_each_parent_owns_its_path_segment(AttachmentParent $parent, string $segment): void
    {
        $path = $this->service()->store($parent, self::PARENT_ID, 'offer.pdf', $this->source());

        self::assertStringContainsString("/{$segment}/", $path->value);
    }

    #[DataProvider('parents')]
    public function test_every_path_segment_matches_a_pivot_table_from_d71(AttachmentParent $parent, string $segment): void
    {
        self::assertSame($segment.'_files', $parent->pivotTable());
    }

    public function test_the_month_is_zero_padded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-09T12:00:00+00:00'));

        $path = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        self::assertStringStartsWith('2026/01/', $path->value);
    }

    public function test_the_year_turns_over_at_the_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31T23:59:59+00:00'));
        $last = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        $this->travelTo(CarbonImmutable::parse('2027-01-01T00:00:01+00:00'));
        $first = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        self::assertStringStartsWith('2026/12/', $last->value);
        self::assertStringStartsWith('2027/01/', $first->value);
    }

    public function test_the_month_is_taken_in_utc_not_in_the_viewers_timezone(): void
    {
        // 01:00 on 1 January in Riyadh is 22:00 on 31 December in UTC. DB-08 stores
        // UTC and displays local, so the folder is December — a path that followed
        // the viewer would file the same object under two different months.
        $this->travelTo(CarbonImmutable::parse('2027-01-01T01:00:00+03:00'));

        $path = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        self::assertStringStartsWith('2026/12/', $path->value);
    }

    // ---------------------------------------------------------------- the name

    public function test_the_stored_name_is_a_uuid_and_never_the_original(): void
    {
        $path = $this->service()->store(
            AttachmentParent::Report,
            self::PARENT_ID,
            'تقرير المبيعات النهائي.pdf',
            $this->source(),
        );

        self::assertStringNotContainsString('تقرير', $path->value);
        self::assertStringNotContainsString('المبيعات', $path->value);
    }

    public function test_two_uploads_of_the_same_name_do_not_collide(): void
    {
        $service = $this->service();

        $first = $service->store(AttachmentParent::Deal, self::PARENT_ID, 'offer.pdf', $this->source('one'));
        $second = $service->store(AttachmentParent::Deal, self::PARENT_ID, 'offer.pdf', $this->source('two'));

        self::assertNotSame($first->value, $second->value);
        self::assertSame('one', $service->read($first));
        self::assertSame('two', $service->read($second));
    }

    public function test_the_extension_is_lowercased(): void
    {
        $path = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'SCAN.PDF', $this->source());

        self::assertStringEndsWith('.pdf', $path->value);
    }

    public function test_a_traversing_original_name_cannot_escape_the_root(): void
    {
        $path = $this->service()->store(
            AttachmentParent::Deal,
            self::PARENT_ID,
            '../../../../etc/passwd.pdf',
            $this->source(),
        );

        self::assertStringNotContainsString('..', $path->value);
        self::assertStringNotContainsString('etc', $path->value);
    }

    public function test_a_parent_id_that_is_not_a_uuid_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->store(AttachmentParent::Deal, '../../etc', 'a.pdf', $this->source());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableExtensions(): array
    {
        return [
            'empty' => [''],
            'carries a separator' => ['../a'],
            'carries a second dot' => ['p.hp'],
            'carries a space' => ['pd f'],
            'absurdly long' => ['extensionthatisnotone'],
        ];
    }

    #[DataProvider('unusableExtensions')]
    public function test_an_extension_that_is_not_a_plain_word_is_refused(string $extension): void
    {
        $this->expectException(InvalidArgumentException::class);

        StoragePath::for(
            AttachmentParent::Deal,
            self::PARENT_ID,
            self::PARENT_ID,
            $extension,
            CarbonImmutable::parse(self::NOW)->toDateTimeImmutable(),
        );
    }

    public function test_a_name_with_no_extension_at_all_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'invoice', $this->source());
    }

    // ---------------------------------------------------------------- write, read, exist, delete

    public function test_a_stored_file_can_be_read_back_byte_for_byte(): void
    {
        $service = $this->service();

        $path = $service->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        self::assertSame(self::SAMPLE, $service->read($path));
    }

    public function test_exists_answers_true_after_a_write_and_false_after_a_delete(): void
    {
        $service = $this->service();

        $path = $service->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());
        self::assertTrue($service->exists($path));

        $service->delete($path);
        self::assertFalse($service->exists($path));
    }

    public function test_the_source_file_is_left_where_it_was(): void
    {
        $source = $this->source();

        $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $source);

        self::assertFileExists($source, 'store() copies; deleting the upload temp file belongs to the caller.');
    }

    public function test_the_bytes_land_under_the_configured_root_at_the_documented_path(): void
    {
        $path = $this->service()->store(AttachmentParent::Deal, self::PARENT_ID, 'a.pdf', $this->source());

        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        self::assertFileExists($root.'/'.$path->value);
    }

    // ---------------------------------------------------------------- §17 access

    public function test_the_upload_root_is_outside_the_web_root(): void
    {
        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        self::assertFalse(
            str_starts_with($root, public_path()),
            '§17: no direct access — a file under public/ is served by nginx before Laravel sees the request.'
        );
    }

    public function test_the_upload_disk_registers_no_public_route(): void
    {
        // Laravel 11 serves a local disk over /storage when `serve` is true. §17
        // requires every file to pass a permission check, so this disk must not.
        //
        // The driver is asserted first on purpose: without it, `serve` reads as
        // null on a disk that does not exist, null casts to false, and the test
        // reports success for a configuration nobody wrote.
        self::assertSame('local', config('filesystems.disks.secure_uploads.driver'));
        self::assertFalse(config('filesystems.disks.secure_uploads.serve'));
        self::assertNull(config('filesystems.disks.secure_uploads.url'));
    }

    public function test_a_failed_write_throws_rather_than_returning_false(): void
    {
        self::assertSame('local', config('filesystems.disks.secure_uploads.driver'));
        self::assertTrue(
            config('filesystems.disks.secure_uploads.throw'),
            'A silent false on write loses a file and reports success. §17 makes files part of the backup set; they have to exist first.'
        );
    }

    // ---------------------------------------------------------------- the abstraction holds

    /**
     * §14.2: "local file system behind an abstraction layer". The layer only
     * exists if nothing goes around it, and that is a property of every file in
     * the application, not of this module.
     */
    public function test_nothing_outside_the_storage_driver_touches_the_filesystem(): void
    {
        $allowed = self::root().'/app/Modules/Storage/Infrastructure';

        $forbidden = [
            'Facades\\Storage',
            'file_put_contents(',
            'file_get_contents(',
            'fopen(',
            'unlink(',
            'move_uploaded_file(',
            'mkdir(',
            'rmdir(',
            'storeAs(',
        ];

        $offences = [];
        $files = self::phpFiles();

        // A glob that matches nothing passes this test forever. Nine files is
        // below today's count and above zero, which is the only claim needed.
        self::assertGreaterThan(9, count($files), 'The scanner read nothing, so it proved nothing.');

        foreach ($files as $file) {
            if (str_starts_with($file, $allowed)) {
                continue;
            }

            $source = self::withoutComments((string) file_get_contents($file));

            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $offences[] = str_replace(self::root().'/', '', $file).' → '.$needle;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            'Filesystem access belongs behind StorageServiceInterface (§14.2, §17).'
        );
    }

    /**
     * AP-02's rule is that modules talk through interfaces. Nothing enforces it
     * for this module yet — see the debt test below — so it is enforced here.
     */
    public function test_only_the_container_binding_names_the_concrete_driver(): void
    {
        $allowed = [
            self::root().'/app/Modules/Storage',
            self::root().'/app/Providers/AppServiceProvider.php',
        ];

        $offences = [];

        foreach (self::phpFiles() as $file) {
            foreach ($allowed as $exempt) {
                if (str_starts_with($file, $exempt)) {
                    continue 2;
                }
            }

            if (str_contains(self::withoutComments((string) file_get_contents($file)), 'LocalStorageService')) {
                $offences[] = str_replace(self::root().'/', '', $file);
            }
        }

        self::assertSame([], $offences, 'Depend on StorageServiceInterface; the driver is chosen once, in the binding.');
    }

    /**
     * The gap this stands in for: `app/Modules/Storage` appears in no layer of
     * deptrac.modules.yaml, so a module importing the driver directly is
     * reported as *uncovered* rather than as a violation, and uncovered does not
     * fail the build. AP-02 names twelve modules and Storage is not one of them
     * — §14.2 lists it as a stack row — so adding a thirteenth layer is a change
     * to a documented decision, not a tidy-up. Recorded until it is approved.
     */
    public function test_storage_is_not_yet_covered_by_the_module_boundary_config(): void
    {
        self::assertStringNotContainsString(
            'app/Modules/Storage',
            (string) file_get_contents(self::root().'/deptrac.modules.yaml'),
            'Storage now has a deptrac layer — delete this debt test and the hand-rolled scanner above it.'
        );
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        $files = [];

        foreach (['app', 'routes'] as $directory) {
            $found = glob(self::root().'/'.$directory.'/{,*/,*/*/,*/*/*/,*/*/*/*/}*.php', GLOB_BRACE);

            foreach ($found === false ? [] : $found as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /** A scanner that trips over its own explanation is a scanner nobody keeps. */
    private static function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    // ---------------------------------------------------------------- the debt

    /**
     * §17: "outside the application directory" — not merely outside public/.
     * storage/ would fail this, which is why the root is the crm-storage volume
     * docker-compose.yml already mounts for php and every worker.
     */
    public function test_the_root_is_outside_the_application_directory(): void
    {
        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        self::assertFalse(
            str_starts_with($root, self::root()),
            '§17 puts attachments outside the application directory; a root under the repository is inside the bind mount.'
        );
        self::assertFalse(str_starts_with($root, base_path()));
    }

    public function test_the_root_exists_and_the_application_user_can_write_to_it(): void
    {
        // Checked at runtime rather than read out of docker-compose.yml, which
        // is not inside the bind mount and so does not exist in this container.
        // The property that matters is not "a volume is declared" but "this
        // process can write there", and only one of the two can be observed.
        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        self::assertDirectoryExists($root, 'docker/php/Dockerfile creates it; docker-compose.yml mounts crm-storage over it.');
        self::assertDirectoryIsWritable($root);
    }

    public function test_the_root_is_configurable_without_a_code_change(): void
    {
        self::assertStringContainsString(
            "env('STORAGE_PATH'",
            (string) file_get_contents(self::root().'/config/filesystems.php'),
            'AP-08 is config over code: moving the root must not need an edit to a class.'
        );
    }
}
