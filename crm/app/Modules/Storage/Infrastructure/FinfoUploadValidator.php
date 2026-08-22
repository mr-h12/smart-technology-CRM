<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\AllowedFileType;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Exceptions\UploadRejected;
use App\Modules\Storage\Domain\FileIntegrityCheck;
use App\Modules\Storage\Domain\UploadRejectionReason;
use ZipArchive;

/**
 * Upload validation against the bytes (§17, D-40, D-71, SEC-15).
 *
 * Three questions in order, because the answer to each only means something if
 * the one before it held: is there a file of a sane size, is it a type we
 * accept, and is it whole.
 */
final readonly class FinfoUploadValidator implements UploadValidatorInterface
{
    /**
     * How much of the file libmagic is shown.
     *
     * Enough, and bounded. Measured in this image: 8 KB identifies a 26 MB DOCX
     * correctly, because a zip's first local header names `[Content_Types].xml`
     * and the entry that distinguishes Word from Excel follows it. Reading the
     * whole file instead would put up to 30 MB (D-71) into a PHP string for
     * every upload, against a memory_limit shared with the rest of the request.
     */
    private const HEAD_BYTES = 8192;

    /** A PDF's `%%EOF` is the last thing in the file, after any trailing newline. */
    private const PDF_TAIL_BYTES = 1024;

    public function __construct(private int $maxSizeBytes) {}

    public function validate(string $sourcePath): AllowedFileType
    {
        $size = is_file($sourcePath) ? filesize($sourcePath) : false;

        if ($size === false) {
            throw new UploadRejected(UploadRejectionReason::Unreadable);
        }

        if ($size === 0) {
            throw new UploadRejected(UploadRejectionReason::EmptyFile);
        }

        if ($size > $this->maxSizeBytes) {
            throw new UploadRejected(UploadRejectionReason::TooLarge);
        }

        $type = AllowedFileType::fromMimeType($this->detectMimeType($sourcePath));

        if ($type === null) {
            throw new UploadRejected(UploadRejectionReason::UnsupportedType);
        }

        if (! $this->isWhole($sourcePath, $type->integrity())) {
            throw new UploadRejected(UploadRejectionReason::Corrupted);
        }

        return $type;
    }

    /**
     * The procedural finfo API, not the class.
     *
     * `finfo` is a root-namespace class and would be reported by deptrac as an
     * uncovered dependency — a boundary crossing nobody decided on. The
     * functions carry no such weight and do the same work.
     */
    private function detectMimeType(string $path): string
    {
        $head = file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        if ($head === false) {
            throw new UploadRejected(UploadRejectionReason::Unreadable);
        }

        $handle = finfo_open(FILEINFO_MIME_TYPE);

        if ($handle === false) {
            throw new UploadRejected(UploadRejectionReason::Unreadable);
        }

        try {
            $detected = finfo_buffer($handle, $head);
        } finally {
            finfo_close($handle);
        }

        return $detected === false ? '' : $detected;
    }

    private function isWhole(string $path, FileIntegrityCheck $check): bool
    {
        return match ($check) {
            FileIntegrityCheck::PdfEndMarker => $this->endsWithPdfMarker($path),
            FileIntegrityCheck::DecodableImage => @getimagesize($path) !== false,
            FileIntegrityCheck::ConsistentZip => $this->isConsistentZip($path),
        };
    }

    private function endsWithPdfMarker(string $path): bool
    {
        $size = filesize($path);

        if ($size === false) {
            return false;
        }

        $offset = max(0, $size - self::PDF_TAIL_BYTES);
        $tail = file_get_contents($path, false, null, $offset, self::PDF_TAIL_BYTES);

        return $tail !== false && str_contains($tail, '%%EOF');
    }

    /**
     * Opening the archive is the check; CHECKCONS only tightens it.
     *
     * What a break proved, and it was not what this comment first claimed: a
     * truncated package is refused by a *plain* open too, because the
     * end-of-central-directory record is simply gone (ER_NOZIP, 19). Dropping
     * CHECKCONS changed nothing in the suite. The flag stays because it is
     * strictly stronger — it also refuses an archive whose central directory
     * exists but disagrees with the local headers — but that extra strictness
     * is **not** exercised by any test here, and should not be relied on until
     * one constructs such a file.
     *
     * libmagic is no help either way: it reads the first entry names and calls
     * a 4 KB fragment of a 26 MB DOCX a DOCX. Measured.
     */
    private function isConsistentZip(string $path): bool
    {
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        $archive->close();

        return true;
    }
}
