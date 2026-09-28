<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pdf\SignsInByRole;
use Tests\TestCase;

/**
 * F-22 · 1.2 — `D-97`: a file that is not CSV is refused with a clear `422`
 * that names CSV and quotes nothing from the file, judged by its content (its
 * true MIME type, `SEC-15`), not its extension. E2-4 found a fake `.xlsx`
 * answered "…does not accept: PK\u0003\u0004."
 *
 * ── A real file, not `UploadedFile::fake()` ───────────────────────────────
 *
 * The fake reports its MIME type from its **name**
 * (`Illuminate\Http\Testing\File::getMimeType()`), so a test built on it would
 * pass on the extension alone. These upload a real temporary file, which
 * `UploadedFile::getMimeType()` reads through libmagic — measured in this
 * image on 2026-09-28: a CSV is `text/csv`, but a one-column or `;`-separated
 * one is `text/plain`, a zip is `application/zip`, and a 0-byte file is
 * `application/x-empty` (the owner kept its own message, 2026-09-28).
 */
final class ImportFileTypeTest extends TestCase
{
    use RefreshDatabase;
    use SignsInByRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<string, array{string}> */
    public static function imports(): array
    {
        return [
            'customers' => ['/api/v1/customers/import'],
            'suppliers' => ['/api/v1/suppliers/import'],
            'catalog items' => ['/api/v1/catalog-items/import'],
        ];
    }

    #[DataProvider('imports')]
    public function test_that_an_xlsx_is_refused_as_not_csv(string $endpoint): void
    {
        $this->assertRefusedAsNotCsv($this->upload($endpoint, self::xlsx(), 'QA-E2.xlsx'));
    }

    /** The break this catches: a rule judged by the extension (`mimes:csv`). */
    #[DataProvider('imports')]
    public function test_that_a_zip_named_csv_is_refused_as_not_csv(string $endpoint): void
    {
        $this->assertRefusedAsNotCsv($this->upload($endpoint, self::xlsx(), 'customers.csv'));
    }

    public function test_that_the_refusal_is_in_arabic_when_asked(): void
    {
        $message = $this->upload('/api/v1/customers/import', self::xlsx(), 'QA-E2.xlsx', 'ar')
            ->assertStatus(422)
            ->json('error.details.0.message');

        self::assertIsString($message);
        self::assertStringContainsString('CSV', $message);
        self::assertStringContainsString('فقط', $message);
    }

    /** Guard, passing before the rule too: the extension must not decide the other way either. */
    public function test_that_a_csv_named_txt_is_imported(): void
    {
        $this->upload('/api/v1/customers/import', "name,phone\nAcme Trading,0100\n", 'data.txt')
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1);
    }

    /**
     * Guard, passing before the rule too. The break it catches: an allow-list
     * without `application/x-empty`, which answers an empty file "not CSV".
     */
    public function test_that_an_empty_file_is_still_answered_as_empty(): void
    {
        self::assertSame('application/x-empty', $this->file('', 'customers.csv')->getMimeType());

        $this->upload('/api/v1/customers/import', '', 'customers.csv')
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.message', 'This file is empty. The first row must name the columns.');
    }

    /** @return array<string, array{string}> */
    public static function plainTextCsvs(): array
    {
        return [
            'one column' => ["name\nAcme Trading\n"],
            'semicolon separated' => ["name;phone\nAcme Trading;0100\n"],
        ];
    }

    /**
     * Guard, passing before the rule too. The break it catches: an allow-list
     * of `text/csv` alone, which refuses these real CSVs.
     */
    #[DataProvider('plainTextCsvs')]
    public function test_that_a_csv_libmagic_calls_plain_text_is_imported(string $contents): void
    {
        // The precondition the guard depends on — if libmagic ever answers
        // `text/csv` here, this guard stops guarding and must say so.
        self::assertSame('text/plain', $this->file($contents, 'customers.csv')->getMimeType());

        $this->upload('/api/v1/customers/import', $contents, 'customers.csv')
            ->assertStatus(201)
            ->assertJsonPath('data.imported_count', 1);
    }

    /** The ZIP signature an `.xlsx` opens with, then padding — E2-4's own file. */
    private static function xlsx(): string
    {
        return "PK\x03\x04".str_repeat("\0", 100);
    }

    /** @param TestResponse<\Illuminate\Http\JsonResponse> $response */
    private function assertRefusedAsNotCsv(TestResponse $response): void
    {
        $message = $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'file')
            ->json('error.details.0.message');

        self::assertIsString($message);
        self::assertStringContainsString('CSV', $message);
        self::assertStringNotContainsString('PK', $message);
        self::assertStringNotContainsString('\u0003', (string) $response->getContent());
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function upload(string $endpoint, string $contents, string $name, string $locale = 'en'): TestResponse
    {
        return $this->post(
            $endpoint,
            ['file' => $this->file($contents, $name)],
            $this->bearerFor(RoleName::Manager) + ['Accept-Language' => $locale],
        );
    }

    private function file(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'f22');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        // `test: true` — a file this test wrote, not one PHP received over HTTP.
        return new UploadedFile($path, $name, null, null, true);
    }
}
