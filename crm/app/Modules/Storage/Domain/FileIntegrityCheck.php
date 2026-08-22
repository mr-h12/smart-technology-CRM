<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * How completeness is established, per family.
 *
 * libmagic reads a header and stops, so it calls a 40-byte fragment of a JPEG a
 * JPEG — measured, not assumed. Type detection therefore says nothing about
 * whether the file is whole, and a second check is needed for each family:
 *
 *   - a PDF ends with `%%EOF`;
 *   - an image can be decoded to dimensions;
 *   - an OOXML package is a zip whose central directory agrees with itself.
 *
 * Named in the domain, performed in the infrastructure — the rule is a business
 * decision, the file handle is not.
 */
enum FileIntegrityCheck
{
    case PdfEndMarker;
    case DecodableImage;
    case ConsistentZip;
}
