<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Exceptions\UploadRejected;
use App\Modules\Storage\Domain\UploadRejectionReason;
use App\Modules\Storage\Infrastructure\FinfoUploadValidator;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Point 5.3 — upload validation.
 *
 * §17: "True MIME type, not the extension — a spoofed `.pdf` is rejected", the
 * six types of D-40, and the 30 MB ceiling of D-71. The name a browser sends is
 * attacker-controlled and so is the Content-Type header it sends with it; the
 * only thing that is not is the bytes, so the bytes are what decides.
 */
final class UploadValidationTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private static function ceiling(): int
    {
        $ceiling = config('files.max_size_bytes');
        self::assertIsInt($ceiling);

        return $ceiling;
    }

    private function validator(): UploadValidatorInterface
    {
        $validator = $this->app->make(UploadValidatorInterface::class);
        self::assertInstanceOf(UploadValidatorInterface::class, $validator);

        return $validator;
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-validate-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return $path;
    }

    // ------------------------------------------------------------- fixtures

    /** A whole, if minimal, PDF — optionally padded to an exact byte count. */
    private static function pdf(int $padTo = 0): string
    {
        $head = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\nstartxref\n9\n";
        $tail = "%%EOF\n";
        $pad = max(0, $padTo - strlen($head) - strlen($tail));

        return $head.str_repeat(' ', $pad).$tail;
    }

    private static function image(string $format): string
    {
        $image = imagecreatetruecolor(16, 16);
        self::assertNotFalse($image);

        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
            default => self::fail("No fixture for {$format}."),
        };
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crm-zip-');
        self::assertIsString($path);
        $this->scratch[] = $path;

        $archive = new ZipArchive;
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        $archive->close();

        return (string) file_get_contents($path);
    }

    private function docx(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'word/document.xml' => '<?xml version="1.0"?><document/>',
        ]);
    }

    private function xlsx(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
        ]);
    }

    /** A real ELF header — 224 bytes is enough for libmagic and for the point. */
    private static function executable(): string
    {
        return "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 8)
            ."\x02\x00\x3e\x00\x01\x00\x00\x00".str_repeat("\x00", 200);
    }

    // ------------------------------------------------------------- accepted

    public function test_a_pdf_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Pdf, $this->validator()->validate($this->file(self::pdf())));
    }

    public function test_a_png_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Png, $this->validator()->validate($this->file(self::image('png'))));
    }

    public function test_a_jpeg_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Jpeg, $this->validator()->validate($this->file(self::image('jpg'))));
    }

    public function test_a_webp_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Webp, $this->validator()->validate($this->file(self::image('webp'))));
    }

    public function test_a_docx_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Docx, $this->validator()->validate($this->file($this->docx())));
    }

    public function test_an_xlsx_is_accepted(): void
    {
        self::assertSame(AllowedFileType::Xlsx, $this->validator()->validate($this->file($this->xlsx())));
    }

    public function test_the_six_allowed_types_are_exactly_the_ones_d40_lists(): void
    {
        self::assertSame(
            ['pdf', 'jpg', 'png', 'webp', 'docx', 'xlsx'],
            array_map(static fn (AllowedFileType $type): string => $type->value, AllowedFileType::cases()),
            'D-40 and §17: PDF · JPG · PNG · WEBP · DOCX · XLSX.'
        );
    }

    // ------------------------------------------------------------- spoofing

    public function test_an_executable_renamed_to_pdf_is_rejected(): void
    {
        $this->assertRejectedWith(
            UploadRejectionReason::UnsupportedType,
            $this->file(self::executable()),
        );
    }

    public function test_a_plain_zip_renamed_to_docx_is_rejected(): void
    {
        // libmagic tells an OOXML package from a zip by what is inside it, so a
        // renamed archive does not become a Word document.
        $this->assertRejectedWith(
            UploadRejectionReason::UnsupportedType,
            $this->file($this->zip(['readme.txt' => 'not office at all'])),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unapprovedContents(): array
    {
        return [
            'shell script' => ["#!/bin/sh\necho hi\n"],
            'html' => ['<!doctype html><html><body>hi</body></html>'],
            'svg' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script/></svg>'],
            'plain text' => ["a plain note, nothing more\n"],
        ];
    }

    #[DataProvider('unapprovedContents')]
    public function test_an_unapproved_type_is_rejected(string $contents): void
    {
        $this->assertRejectedWith(UploadRejectionReason::UnsupportedType, $this->file($contents));
    }

    public function test_a_gif_is_rejected_even_though_it_is_an_image(): void
    {
        // D-40 reads "images" in shorthand; §17 enumerates three. A GIF is a
        // valid image and still not one of them.
        $this->assertRejectedWith(UploadRejectionReason::UnsupportedType, $this->file(self::image('gif')));
    }

    public function test_the_type_comes_from_the_bytes_not_from_the_name(): void
    {
        // A real PDF wearing a hostile extension. The validator is handed a path
        // ending in .exe and still answers Pdf, because nothing about the name
        // reaches the decision — which is also why the interface takes no name.
        $path = $this->file(self::pdf()).'.exe';
        file_put_contents($path, self::pdf());
        $this->scratch[] = $path;

        self::assertSame('exe', pathinfo($path, PATHINFO_EXTENSION));
        self::assertSame(AllowedFileType::Pdf, $this->validator()->validate($path));
    }

    public function test_an_executable_wearing_a_pdf_extension_is_still_refused(): void
    {
        // The mirror image, and the one §17 names: the extension says PDF, the
        // bytes say ELF, and the bytes win.
        $path = $this->file('').'.pdf';
        file_put_contents($path, self::executable());
        $this->scratch[] = $path;

        $this->assertRejectedWith(UploadRejectionReason::UnsupportedType, $path);
    }

    // ------------------------------------------------------------- size

    public function test_an_empty_file_is_rejected(): void
    {
        $this->assertRejectedWith(UploadRejectionReason::EmptyFile, $this->file(''));
    }

    public function test_a_file_at_exactly_the_ceiling_is_accepted(): void
    {
        $ceiling = self::ceiling();

        $path = $this->file(self::pdf($ceiling));
        self::assertSame($ceiling, filesize($path));

        self::assertSame(AllowedFileType::Pdf, $this->validator()->validate($path));
    }

    public function test_a_file_one_byte_over_the_ceiling_is_rejected(): void
    {
        $ceiling = self::ceiling();

        $path = $this->file(self::pdf($ceiling + 1));
        self::assertSame($ceiling + 1, filesize($path));

        $this->assertRejectedWith(UploadRejectionReason::TooLarge, $path);
    }

    public function test_the_ceiling_is_read_from_configuration_not_compiled_in(): void
    {
        config(['files.max_size_bytes' => 512]);

        $this->assertRejectedWith(UploadRejectionReason::TooLarge, $this->file(self::pdf(4096)));
    }

    public function test_the_configured_ceiling_is_the_documented_thirty_megabytes(): void
    {
        self::assertSame(30 * 1024 * 1024, self::ceiling(), 'D-71');
    }

    // ------------------------------------------------------------- corruption

    public function test_a_truncated_jpeg_is_rejected(): void
    {
        // finfo reads a header and stops, so it calls this a JPEG. It is not one.
        $this->assertRejectedWith(
            UploadRejectionReason::Corrupted,
            $this->file(substr(self::image('jpg'), 0, 40)),
        );
    }

    public function test_a_truncated_docx_is_rejected(): void
    {
        // Cut at 4 KB, not at 64 bytes. A 64-byte fragment is not even
        // recognisable as OOXML — libmagic calls it application/zip and it is
        // refused as an unsupported type, which proves nothing about the
        // integrity check. At 4 KB the entry names are still readable, so the
        // type is a DOCX and only the central directory disagrees. Measured.
        $whole = $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'word/document.xml' => '<?xml version="1.0"?><document/>',
            'word/media/blob.bin' => random_bytes(64 * 1024),
        ]);

        $fragment = $this->file(substr($whole, 0, 4096));

        self::assertSame(
            AllowedFileType::Docx->mimeType(),
            (string) mime_content_type($fragment),
            'The fragment must still look like a DOCX, or this test is about the wrong thing.'
        );

        $this->assertRejectedWith(UploadRejectionReason::Corrupted, $fragment);
    }

    public function test_a_pdf_with_no_end_marker_is_rejected(): void
    {
        $this->assertRejectedWith(UploadRejectionReason::Corrupted, $this->file("%PDF-1.4\n"));
    }

    // ------------------------------------------------------------- the contract

    public function test_the_validator_resolves_to_the_finfo_implementation(): void
    {
        self::assertInstanceOf(FinfoUploadValidator::class, $this->validator());
    }

    public function test_the_domain_contract_names_no_framework_type(): void
    {
        $contract = dirname(__DIR__, 3).'/app/Modules/Storage/Domain/Contracts/UploadValidatorInterface.php';
        self::assertFileExists($contract);

        $source = (string) file_get_contents($contract);

        foreach (['Illuminate\\', 'Symfony\\', 'Laravel\\', 'UploadedFile'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                "The Domain layer may depend on nothing (deptrac.layers.yaml); the contract names {$forbidden}."
            );
        }
    }

    // ------------------------------------------------------------- messages

    /**
     * Every rejection has to be explainable to a user in both languages. The
     * exception carries a key, not a sentence: CLAUDE.md keeps user-facing text
     * in lang files, and the domain has no business calling a translator.
     */
    public function test_every_rejection_reason_has_an_arabic_and_an_english_message(): void
    {
        foreach (UploadRejectionReason::cases() as $reason) {
            $key = $reason->translationKey();

            foreach (['en', 'ar'] as $locale) {
                // The third argument is `fallback`, and it defaults to **true**:
                // Lang::has('x', 'ar') answers yes for a key that exists only in
                // English. Deleting the Arabic line left this test green until
                // the flag was passed explicitly.
                self::assertTrue(
                    Lang::has($key, $locale, false),
                    "Missing {$locale} translation for {$key}."
                );
            }
        }
    }

    public function test_the_arabic_message_is_actually_arabic(): void
    {
        foreach (UploadRejectionReason::cases() as $reason) {
            $message = (string) Lang::get($reason->translationKey(), [], 'ar');

            self::assertMatchesRegularExpression(
                '/\p{Arabic}/u',
                $message,
                "The Arabic entry for {$reason->translationKey()} carries no Arabic."
            );
        }
    }

    public function test_the_rejection_names_no_server_path(): void
    {
        // A validation message is shown to a user. §17 keeps files out of reach;
        // a message quoting /var/crm-files hands back the layout for free.
        try {
            $this->validator()->validate($this->file(self::executable()));
            self::fail('The executable was accepted.');
        } catch (UploadRejected $rejected) {
            self::assertStringNotContainsString('/var/', $rejected->getMessage());
            self::assertStringNotContainsString(sys_get_temp_dir(), $rejected->getMessage());
        }
    }

    private function assertRejectedWith(UploadRejectionReason $expected, string $path): void
    {
        try {
            $type = $this->validator()->validate($path);
        } catch (UploadRejected $rejected) {
            self::assertSame($expected, $rejected->reason);

            return;
        }

        self::fail("Expected {$expected->name}; the file was accepted as {$type->value}.");
    }
}
