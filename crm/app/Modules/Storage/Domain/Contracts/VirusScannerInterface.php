<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\ScanStatus;

/**
 * SEC-15 and §17: virus scanning is mandatory on every upload.
 *
 * Takes a stream, not a path and not a string: an upload may be 30 MB (D-71),
 * ClamAV's INSTREAM protocol wants chunks anyway, and a path would tie the
 * contract to a local filesystem the domain is not supposed to know about.
 *
 * Returns `Clean` or `Infected` and nothing else. When it cannot reach the
 * scanner it throws — see ScannerUnavailable for why that is not a status.
 */
interface VirusScannerInterface
{
    /**
     * @param  resource  $contents
     *
     * @throws \App\Modules\Storage\Domain\Exceptions\ScannerUnavailable
     */
    public function scan($contents): ScanStatus;
}
