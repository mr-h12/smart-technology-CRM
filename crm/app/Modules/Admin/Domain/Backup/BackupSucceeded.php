<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Backup;

/** Module 16 · 5 (`D-153`): a `J-10` run that wrote both artefacts with their checksums. */
final readonly class BackupSucceeded
{
    public function __construct(public string $runId) {}
}
