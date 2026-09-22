<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * `PdfRendererInterface` through Browsershot and the `pdf` image's Chromium.
 *
 * The Chromium flags are `docker/php/verify.php`'s, the render CI already
 * proves in that image: `--no-sandbox` because the container is the sandbox
 * and user namespaces are not available to `www-data` in it, and
 * `--disable-dev-shm-usage` because Docker's default `/dev/shm` is 64 MB.
 * With Chrome's own sandbox off, the page gets no JavaScript: the template
 * needs none, and a value that slipped past escaping then cannot run.
 *
 * A missing browser is refused before a process starts, so the `app` image —
 * which CI asserts has none — fails at once and by name instead of spending
 * the timeout discovering it.
 */
final readonly class BrowsershotPdfRenderer implements PdfRendererInterface
{
    public function __construct(
        private string $chromePath,
        private string $nodeModulesPath,
        private int $timeoutSeconds,
    ) {}

    public function render(string $html): string
    {
        if (! is_executable($this->chromePath)) {
            throw PdfRenderingFailed::browserUnavailable($this->chromePath);
        }

        try {
            return Browsershot::html($html)
                ->setChromePath($this->chromePath)
                ->setNodeModulePath($this->nodeModulesPath)
                ->noSandbox()
                ->disableJavascript()
                ->addChromiumArguments(['disable-dev-shm-usage', 'disable-gpu'])
                ->format('A4')
                ->showBackground()
                ->timeout($this->timeoutSeconds)
                ->pdf();
        } catch (Throwable $e) {
            throw PdfRenderingFailed::because($e);
        }
    }
}
