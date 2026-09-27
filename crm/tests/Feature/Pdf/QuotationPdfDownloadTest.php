<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 9, Point 4.1 — a quotation's PDF downloads under §3.5's
 * *export/download PDF* row, through the one route every file uses
 * (`GET /files/{file}/download`, `D-38`, `OpenAPI §8.3`).
 *
 * The row: Manager ✅ · Team Leader ✅ · Outdoor Supervisor — · Outdoor Sales
 * Own · Indoor Sales Own · Procurement Asgn · CEO ✅. A bare ✅ reads as `All`
 * (§3.2, `D-91`); "own" is the deal owner's (owner ruling 2026-09-11); `Asgn`
 * fails closed (Q2). Every refusal is a 404, so it cannot confirm the file.
 */
final class QuotationPdfDownloadTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use SignsInByRole;

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

    /** @var list<StoragePath> exactly the files this test stored */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        // The disk is the development volume (phpunit.xml sets no
        // STORAGE_PATH), so only the paths this test wrote are removed — a
        // pattern would take files a person uploaded by hand.
        $storage = $this->app->make(StorageServiceInterface::class);

        foreach ($this->stored as $path) {
            $storage->delete($path);
        }

        parent::tearDown();
    }

    /** @return array<string, array{RoleName}> */
    public static function checkmarks(): array
    {
        return [
            'Manager' => [RoleName::Manager],
            'Team Leader (D-91 reads the ✅ as All)' => [RoleName::TeamLeader],
            'CEO' => [RoleName::Ceo],
        ];
    }

    #[DataProvider('checkmarks')]
    public function test_that_a_checkmark_downloads_any_quotations_pdf(RoleName $role): void
    {
        $fileId = $this->storedPdf($this->insertQuotation($this->userWith(RoleName::IndoorSales)->id));

        $response = $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role));

        $response->assertOk();
        self::assertSame(self::PDF, $response->streamedContent());
    }

    public function test_that_the_ceo_downloads_but_holds_no_grant_to_generate(): void
    {
        // §3.5's note: the CEO downloads an existing PDF and never generates one.
        $ceo = $this->userWith(RoleName::Ceo);
        $decide = $this->app->make(AuthorizeAction::class);

        self::assertFalse($decide->decide($ceo->id, 'quotation', 'generate_pdf')->granted);

        $fileId = $this->storedPdf($this->insertQuotation($this->userWith(RoleName::OutdoorSales)->id));

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor(RoleName::Ceo))->assertOk();
    }

    /** @return array<string, array{RoleName, RoleName}> the role, and another sales role */
    public static function salesRoles(): array
    {
        return [
            'Outdoor Sales' => [RoleName::OutdoorSales, RoleName::IndoorSales],
            'Indoor Sales' => [RoleName::IndoorSales, RoleName::OutdoorSales],
        ];
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['Outdoor Sales' => [RoleName::OutdoorSales], 'Indoor Sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_a_sales_role_downloads_the_pdf_of_its_own_deal(RoleName $role): void
    {
        $fileId = $this->storedPdf($this->insertQuotation($this->userWith($role)->id));

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role))->assertOk();
    }

    #[DataProvider('salesRoles')]
    public function test_that_a_sales_role_cannot_download_another_employees_pdf(RoleName $role, RoleName $other): void
    {
        $fileId = $this->storedPdf($this->insertQuotation($this->userWith($other)->id));

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role))->assertNotFound();
    }

    public function test_that_an_unowned_deals_pdf_is_nobodys_own(): void
    {
        $fileId = $this->storedPdf($this->insertQuotation(null));

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor(RoleName::IndoorSales))->assertNotFound();
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor(RoleName::Manager))->assertOk();
    }

    /** @return array<string, array{RoleName}> */
    public static function refused(): array
    {
        return [
            'Procurement (Asgn fails closed, Q2)' => [RoleName::Procurement],
            'Outdoor Supervisor (a dash on the row)' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('refused')]
    public function test_that_the_pdf_is_a_404_to(RoleName $role): void
    {
        $fileId = $this->storedPdf($this->insertQuotation($this->userWith(RoleName::IndoorSales)->id));

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearerFor($role))->assertNotFound();
    }

    /**
     * A clean PDF stored against the quotation the way 3.4's job will store
     * one: Storage's own `store()`, `files` row and `quotation_files` pivot.
     */
    private function storedPdf(string $quotationId): string
    {
        $source = tempnam(sys_get_temp_dir(), 'crm-pdf-');
        self::assertIsString($source);
        file_put_contents($source, self::PDF);

        try {
            $path = $this->app->make(StorageServiceInterface::class)
                ->store(AttachmentParent::Quotation, $quotationId, AllowedFileType::Pdf, $source);
        } finally {
            unlink($source);
        }

        $this->stored[] = $path;

        $files = $this->app->make(FileWriterInterface::class);
        $fileId = $files->create($path, 'QT-2026-0001.pdf', 'application/pdf', strlen(self::PDF), $this->userWith(RoleName::Manager)->id);
        $files->attach(AttachmentParent::Quotation, $quotationId, $fileId);

        DB::table('files')->where('id', $fileId)->update(['scan_status' => 'clean']);

        return $fileId;
    }
}
