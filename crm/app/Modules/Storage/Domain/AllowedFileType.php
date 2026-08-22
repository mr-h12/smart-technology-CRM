<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * The six types D-40 allows, keyed by the extension they are stored under.
 *
 * §17 spells the list out where D-40 abbreviates it: "PDF · JPG · PNG · WEBP ·
 * DOCX · XLSX". The abbreviation matters — D-40 says "images", and a GIF is an
 * image. §17 enumerates three, so three is the number.
 *
 * The MIME type here is the one libmagic reports, which is not always the one a
 * browser sends: a Word document is a zip, and only inspecting the entries
 * inside it separates `wordprocessingml` from `application/zip`. Measured in
 * this image (file-5.44), not assumed.
 *
 * The list is a decision (D-40), not a managed list, so it lives in code. If it
 * ever becomes user-editable it belongs in Module 2's settings table with the
 * size ceiling, and this enum becomes the mapping the setting selects from.
 */
enum AllowedFileType: string
{
    case Pdf = 'pdf';
    case Jpeg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Docx = 'docx';
    case Xlsx = 'xlsx';

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Webp => 'image/webp',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** How a whole file of this type is told apart from a truncated one. */
    public function integrity(): FileIntegrityCheck
    {
        return match ($this) {
            self::Pdf => FileIntegrityCheck::PdfEndMarker,
            self::Jpeg, self::Png, self::Webp => FileIntegrityCheck::DecodableImage,
            self::Docx, self::Xlsx => FileIntegrityCheck::ConsistentZip,
        };
    }

    public static function fromMimeType(string $mimeType): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->mimeType() === $mimeType) {
                return $type;
            }
        }

        return null;
    }
}
