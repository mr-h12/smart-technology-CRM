<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use RuntimeException;

/**
 * `PdfAssetsInterface` from the files in `crm/resources/pdf/`.
 *
 * Read from disk and inlined rather than served: `§17` keeps uploads outside
 * the web root and these are not uploads, but the same reasoning applies in
 * reverse — a document that fetched its own logo over HTTP would depend on the
 * server being reachable from the renderer, which on the `pdf` queue it need
 * not be. Each file is read once per instance; the container binds this as a
 * singleton, so a batch of quotations reads the faces once.
 */
final class FilePdfAssets implements PdfAssetsInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $basePath) {}

    public function fontFaceCss(): string
    {
        $faces = [
            ['CRM Sans', 400, 'fonts/inter-400.woff2'],
            ['CRM Sans', 700, 'fonts/inter-700.woff2'],
            ['CRM Sans Arabic', 400, 'fonts/noto-sans-arabic-400.woff2'],
            ['CRM Sans Arabic', 700, 'fonts/noto-sans-arabic-700.woff2'],
        ];

        $css = '';

        foreach ($faces as [$family, $weight, $file]) {
            $css .= sprintf(
                "@font-face { font-family: '%s'; font-style: normal; font-weight: %d; font-display: block;"
                ." src: url('%s') format('woff2'); }\n",
                $family,
                $weight,
                $this->dataUri($file, 'font/woff2'),
            );
        }

        return $css;
    }

    public function logo(): string
    {
        return $this->dataUri('letterhead/logo.png', 'image/png');
    }

    public function footerBand(): string
    {
        return $this->dataUri('letterhead/footer-band.jpg', 'image/jpeg');
    }

    public function watermark(): string
    {
        return $this->dataUri('letterhead/watermark.jpg', 'image/jpeg');
    }

    private function dataUri(string $relativePath, string $mimeType): string
    {
        return $this->cache[$relativePath] ??= sprintf(
            'data:%s;base64,%s',
            $mimeType,
            base64_encode($this->read($relativePath)),
        );
    }

    private function read(string $relativePath): string
    {
        $path = $this->basePath.'/'.$relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            // Named, because a document rendered without its face or its logo
            // is a document nobody notices is wrong until the customer has it.
            throw new RuntimeException("The PDF asset {$relativePath} is missing from {$this->basePath}.");
        }

        return $contents;
    }
}
