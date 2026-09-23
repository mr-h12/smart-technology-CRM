<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\Contracts;

use RuntimeException;

/**
 * The faces and the letterhead the customer PDF is built from — Module 9,
 * Point 2.2 — each as a `data:` URI, so a render fetches nothing.
 *
 * `D-79` embedded the faces "with zero OS fallback" and `D-89` carries that
 * forward: §14.6's document must look the same on the on-premise Linux server
 * as it does in development, and an unembedded face does not. The families are
 * `CRM Sans` (Inter, Latin and digits — `Design_System_EN.md` §4.1) and
 * `CRM Sans Arabic` (Noto Sans Arabic), names no operating system ships, so a
 * broken declaration shows up as a wrong-looking page rather than being masked
 * by a system font of the same name.
 */
interface PdfAssetsInterface
{
    /**
     * The four `@font-face` rules, faces inlined.
     *
     * @throws RuntimeException naming the file, when a face is missing
     */
    public function fontFaceCss(): string;

    /** The letterhead lockup (`D-89`'s header). */
    public function logo(): string;

    /** The footer band carrying address, phones and e-mails. */
    public function footerBand(): string;

    /** The faded S.T.I.S mark behind the page. */
    public function watermark(): string;
}
