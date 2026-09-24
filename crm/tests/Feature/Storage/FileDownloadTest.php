<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Storage\Application\ParentAwareAttachmentPermission;
use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentLink;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Infrastructure\DenyAllAttachmentPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Point 5.4 — the download endpoint.
 *
 * D-38: "Attachment permission = permission on the parent entity". §17: "No
 * direct access — every file served through a permission-checking API". And the
 * OpenAPI contract decides what a refusal looks like: 404 means "does not exist
 * **or** is not visible to the caller. Do not reveal which case applies", so a
 * denial and a miss have to be indistinguishable from outside.
 */
final class FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = '%PDF-1.4 pretend this is a supplier quotation %%EOF';

    private const DEAL_ID = '0198f3d4-1a2b-7c3d-8e4f-5a6b7c8d9e0f';

    /** Grants everything, so that a test about headers is about headers. */
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

    /**
     * Stores real bytes and inserts the row that describes them.
     *
     * @return string the file id
     */
    private function storedFile(
        string $originalName = 'offer.pdf',
        string $scanStatus = 'clean',
        bool $attached = true,
        bool $deleted = false,
    ): string {
        $service = $this->app->make(StorageServiceInterface::class);
        self::assertInstanceOf(StorageServiceInterface::class, $service);

        $source = tempnam(sys_get_temp_dir(), 'crm-download-');
        self::assertIsString($source);
        file_put_contents($source, self::BYTES);

        $path = $service->store(AttachmentParent::Deal, self::DEAL_ID, AllowedFileType::Pdf, $source);
        unlink($source);

        $id = Str::uuid7()->toString();

        DB::table('files')->insert([
            'id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
            'original_name' => $originalName,
            'mime_type' => AllowedFileType::Pdf->mimeType(),
            'size_bytes' => strlen(self::BYTES),
            'storage_path' => $path->value,
            'scan_status' => $scanStatus,
            'scanned_at' => $scanStatus === 'pending' ? null : now(),
        ]);

        if ($attached) {
            self::seedDeal();
            DB::table('deal_files')->insert(['deal_id' => self::DEAL_ID, 'file_id' => $id]);
        }

        return $id;
    }

    /**
     * `deal_files.deal_id` is a real foreign key onto `deals` (Module 5 Point
     * 1.1), so attaching a file needs a row it actually references rather than
     * the bare identifier this file used before that constraint existed.
     */
    private static function seedDeal(): void
    {
        $customerId = Str::uuid7()->toString();

        DB::table('customers')->insert([
            'id' => $customerId,
            'name' => 'Test Customer for a Download Fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert([
            'id' => self::DEAL_ID,
            'code' => 'DL-2026-9001',
            'customer_id' => $customerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function download(string $id): TestResponse
    {
        return $this->get("/api/v1/files/{$id}/download");
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

    // ---------------------------------------------------------------- success

    public function test_an_authorised_user_receives_the_bytes(): void
    {
        $this->allowAll();
        $id = $this->storedFile();

        $response = $this->actingAs(User::factory()->create())->download($id);

        $response->assertOk();
        self::assertSame(self::BYTES, $response->streamedContent());
    }

    public function test_the_response_carries_the_documented_headers(): void
    {
        $this->allowAll();
        $id = $this->storedFile();

        $response = $this->actingAs(User::factory()->create())->download($id);

        $response->assertHeader('Content-Type', AllowedFileType::Pdf->mimeType());
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Length', (string) strlen(self::BYTES));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_response_is_not_cacheable_by_anything_shared(): void
    {
        // A permission-checked byte stream must not sit in a proxy where the
        // next caller can be handed it without a check (§17, SEC-14).
        $this->allowAll();
        $id = $this->storedFile();

        $response = $this->actingAs(User::factory()->create())->download($id);

        // Asserted before the header is read: Laravel's own 404 carries
        // `no-cache, private`, so without this the test passes on a route that
        // does not exist.
        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');

        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function test_an_arabic_filename_survives_the_content_disposition_header(): void
    {
        $this->allowAll();
        $id = $this->storedFile('عرض سعر المورد.pdf');

        $disposition = (string) $this->actingAs(User::factory()->create())
            ->download($id)->headers->get('Content-Disposition');

        // RFC 5987: the raw bytes cannot travel in a header, so the UTF-8 name
        // rides in filename* and an ASCII fallback in filename.
        self::assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        self::assertStringContainsString('%D8%B9', $disposition);
    }

    // ---------------------------------------------------------------- refusal

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->allowAll();

        $this->download($this->storedFile())->assertUnauthorized();
    }

    public function test_a_caller_without_permission_on_the_parent_gets_404_not_403(): void
    {
        // OpenAPI §: 404 covers "does not exist or is not visible to the caller.
        // Do not reveal which case applies." A 403 here would confirm the file
        // exists to someone who may not know that.
        $id = $this->storedFile();

        $this->actingAs(User::factory()->create())->download($id)->assertNotFound();
    }

    public function test_a_denial_and_a_miss_are_indistinguishable(): void
    {
        $real = $this->storedFile();
        $imaginary = Str::uuid7()->toString();

        $user = User::factory()->create();

        $denied = $this->actingAs($user)->download($real);
        $missing = $this->actingAs($user)->download($imaginary);

        self::assertSame(404, $denied->getStatusCode());
        self::assertSame($denied->getStatusCode(), $missing->getStatusCode());
        // The whole envelope but `meta.request_id`, which is minted per
        // request (`OpenAPI §3.3`) and so differs by design (F-17 · 1.1).
        self::assertSame($denied->json('error'), $missing->json('error'));
        self::assertSame(
            $denied->headers->get('Content-Type'),
            $missing->headers->get('Content-Type'),
        );
    }

    /**
     * The authorisation decision is re-made on every request, not on the first.
     *
     * `Route::getController()` memoises the controller on the Route object, and
     * a Route outlives a request. With the services injected through the
     * constructor this endpoint answered **200, 200, 200** to allow → deny →
     * allow: whatever policy the first request resolved was the policy for the
     * life of the process. PHP-FPM hides it by starting fresh each time; a
     * long-lived worker would not, and neither did the test suite.
     *
     * OpenAPI §9.1 is explicit that authorisation is checked per request, so
     * this is pinned rather than left to the shape of the constructor.
     */
    public function test_the_policy_is_consulted_on_every_request_not_only_the_first(): void
    {
        $user = User::factory()->create();
        $id = $this->storedFile();

        $this->allowAll();
        $allowed = $this->actingAs($user)->download($id)->getStatusCode();

        $this->app->bind(AttachmentPermissionInterface::class, DenyAllAttachmentPermission::class);
        $revoked = $this->actingAs($user)->download($id)->getStatusCode();

        $this->allowAll();
        $restored = $this->actingAs($user)->download($id)->getStatusCode();

        self::assertSame([200, 404, 200], [$allowed, $revoked, $restored]);
    }

    public function test_the_default_policy_routes_by_parent(): void
    {
        // Deals Point 4.1 replaced the deny-all default with
        // `DealAttachmentPermission` — the crossing `DenyAllAttachmentPermission`'s
        // own docblock predicted. **Module 6 Point 5.1 replaced that single
        // binding with a composite**, because a second parent arrived and
        // growing Deals' class to answer for it would have put §3.6's rule
        // inside Module 5.
        //
        // Still only pinning *which* class is bound: what each module answers
        // for its own parent is `DealAttachmentPermissionTest`'s and
        // `SupplierQuotationAttachmentPermissionTest`'s, and neither belongs in
        // Storage's suite.
        self::assertInstanceOf(
            ParentAwareAttachmentPermission::class,
            $this->app->make(AttachmentPermissionInterface::class),
        );
    }

    public function test_a_file_attached_to_nothing_is_refused(): void
    {
        // D-38 makes the parent the source of permission. A file with no parent
        // has no permission to inherit, so it cannot be granted — which is the
        // J-11 orphan, refused rather than served.
        $this->allowAll();
        $id = $this->storedFile(attached: false);

        $this->actingAs(User::factory()->create())->download($id)->assertNotFound();
    }

    public function test_a_soft_deleted_file_is_refused(): void
    {
        $this->allowAll();
        $id = $this->storedFile(deleted: true);

        $this->actingAs(User::factory()->create())->download($id)->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unscannedStatuses(): array
    {
        return ['pending' => ['pending'], 'infected' => ['infected']];
    }

    #[DataProvider('unscannedStatuses')]
    public function test_a_file_that_is_not_clean_is_refused(string $status): void
    {
        // SEC-15 makes scanning mandatory on every upload. A file whose scan has
        // not returned clean is not servable, whoever asks.
        $this->allowAll();
        $id = $this->storedFile(scanStatus: $status);

        $this->actingAs(User::factory()->create())->download($id)->assertNotFound();
    }

    public function test_the_permission_is_asked_about_the_actual_parent(): void
    {
        // An ArrayObject, not `array &$seen`: a by-reference constructor
        // promotion is not a thing in PHP, so the first version of this spy
        // recorded into a copy and reported an empty list while the download
        // succeeded — a green assertion away from being a green lie.
        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject;

        $this->app->bind(AttachmentPermissionInterface::class, fn (): AttachmentPermissionInterface => new class($seen) implements AttachmentPermissionInterface
        {
            /** @param  \ArrayObject<int, string>  $seen */
            public function __construct(private readonly \ArrayObject $seen) {}

            public function mayView(AttachmentLink $link, string $actorId): bool
            {
                $this->seen[] = $link->parent->value.':'.$link->parentId;

                return true;
            }
        });

        $id = $this->storedFile();
        $this->actingAs(User::factory()->create())->download($id)->assertOk();

        self::assertSame(['deal:'.self::DEAL_ID], $seen->getArrayCopy());
    }

    // ---------------------------------------------------------------- exposure

    public function test_the_stored_path_is_not_addressable_through_the_application(): void
    {
        $this->allowAll();
        $this->storedFile();

        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        $found = glob($root.'/*/*/*/'.self::DEAL_ID.'/*');
        self::assertNotFalse($found);
        self::assertNotSame([], $found);

        $relative = str_replace($root.'/', '', $found[0]);

        // The same path the file really has on disk, asked for over HTTP, by an
        // authenticated user. The assertion is that the **bytes** never come
        // back — not that the status is 404: these paths fall to the SPA
        // catch-all, which answers 200 with the application shell, and a deep
        // link is supposed to do that. What must not happen is the file.
        foreach (['/'.$relative, '/storage/'.$relative] as $url) {
            $response = $this->actingAs(User::factory()->create())->get($url);

            self::assertStringNotContainsString(self::BYTES, (string) $response->getContent(), $url);
            self::assertStringNotContainsString(
                'pdf',
                strtolower((string) $response->headers->get('Content-Type')),
                $url
            );
        }
    }

    public function test_the_endpoint_is_the_only_route_that_serves_a_stored_file(): void
    {
        $serving = array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn ($route): bool => str_contains((string) $route->uri(), 'storage')
                || str_contains((string) $route->uri(), 'crm-files'),
        );

        self::assertSame([], array_values($serving), 'A local disk with serve=true would appear here.');
    }
}
