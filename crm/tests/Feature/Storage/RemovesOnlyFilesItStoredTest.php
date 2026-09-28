<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Storage\Domain\AttachmentParent;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-31 · 1.1 — an upload test's cleanup removes what that test stored and
 * nothing else. The disk is the development volume (phpunit.xml forces no
 * STORAGE_PATH, so `StorageServiceTest` can check the real one), and DB-01
 * forbids deleting a file a person uploaded there.
 */
final class RemovesOnlyFilesItStoredTest extends TestCase
{
    use RemovesOnlyFilesItStored;

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            if (is_file($file)) {
                unlink($file);
            }

            // Up the folders this test made, stopping at the first that holds
            // anything else — at the latest the root, which holds real years.
            for ($directory = dirname($file); is_dir($directory) && count(scandir($directory) ?: []) === 2; $directory = dirname($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_a_file_present_before_the_test_survives_the_cleanup(): void
    {
        $before = $this->file(AttachmentParent::PurchaseOrder);

        $this->rememberStoredFiles(AttachmentParent::PurchaseOrder);
        $this->removeFilesStoredSince(AttachmentParent::PurchaseOrder);

        self::assertFileExists($before);
    }

    public function test_a_file_stored_during_the_test_is_removed(): void
    {
        $this->rememberStoredFiles(AttachmentParent::PurchaseOrder);
        $during = $this->file(AttachmentParent::PurchaseOrder);

        $this->removeFilesStoredSince(AttachmentParent::PurchaseOrder);

        self::assertFileDoesNotExist($during);
    }

    /** A file at the §17 depth a parent's glob matches, under a year no real upload has. */
    private function file(AttachmentParent $parent): string
    {
        $root = config('filesystems.disks.secure_uploads.root');
        self::assertIsString($root);

        $directory = $root.'/2000/01/'.$parent->value.'/'.Str::uuid7()->toString();
        mkdir($directory, 0750, true);
        $file = $directory.'/'.Str::uuid7()->toString().'.pdf';
        file_put_contents($file, '%PDF-1.4 probe');

        return $this->made[] = $file;
    }
}
