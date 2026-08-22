<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\ScanStatus;

/**
 * **Not an antivirus.** It knows one signature: the EICAR test file.
 *
 * It exists so that the upload path, the status column and the download gate
 * can be exercised in CI and on a developer machine without a clamd daemon —
 * and so that "the scanner is wired in" is something a test can prove rather
 * than something a comment claims. A test asserts out loud that it reports
 * everything else clean, so nobody can mistake it for protection.
 *
 * Production must run ClamAV. `.env.example` says so and the deployment-debt
 * register in CHECKLIST.md carries the daemon as an open item.
 */
final readonly class EicarSignatureScanner implements VirusScannerInterface
{
    private const CHUNK = 65536;

    public function scan($contents): ScanStatus
    {
        $signature = self::signature();

        // The overlap matters: read in fixed chunks and a signature straddling
        // a boundary is in neither half. Carrying the last (len - 1) bytes
        // forward is what makes a buried match findable, and there is a test
        // with 100 kB either side of it.
        $carry = '';

        while (! feof($contents)) {
            $chunk = fread($contents, self::CHUNK);

            if ($chunk === false) {
                break;
            }

            $window = $carry.$chunk;

            if (str_contains($window, $signature)) {
                return ScanStatus::Infected;
            }

            $carry = substr($window, -(strlen($signature) - 1));
        }

        return ScanStatus::Clean;
    }

    /**
     * Assembled at run time, never written whole into a file.
     *
     * The EICAR string is designed to be detected, so a source file containing
     * it can trip the developer's own antivirus, a mail gateway or a CI cache
     * scan — which would look exactly like a build failure and not at all like
     * its cause.
     */
    private static function signature(): string
    {
        return 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR'
            .'-STANDARD-ANTIVIRUS-TEST-'
            .'FILE!$H+H*';
    }
}
