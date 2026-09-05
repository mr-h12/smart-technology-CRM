<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Rbac\VerifyPermissionMatrix;
use Illuminate\Console\Command;

/**
 * The entry point, and nothing else — `EnsureAuditPartitionsCommand`'s shape.
 *
 * Thin the way a controller is thin: invoke the use case, render the answer,
 * choose an exit code. **The non-zero exit is the alarm** (`OBS-06`): a
 * scheduler or a CI step notices an exit code and nothing else, so a command
 * that printed the drift and returned 0 would report success for the single
 * state it exists to detect.
 */
final class VerifyPermissionMatrixCommand extends Command
{
    protected $signature = 'rbac:verify';

    protected $description = 'SEC-07: report how far the live permission grants have drifted from §3';

    public function handle(VerifyPermissionMatrix $verify): int
    {
        $divergence = $verify->handle();

        if ($divergence->isAligned()) {
            $this->info('rbac:verify — the live grant set matches §3.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'rbac:verify — the live grant set differs from §3: %d granted beyond it, %d missing from it.',
            count($divergence->extra),
            count($divergence->missing),
        ));

        foreach ($divergence->extra as $line) {
            $this->line('  granted beyond §3:  '.$line);
        }

        foreach ($divergence->missing as $line) {
            $this->line('  missing from live:  '.$line);
        }

        // Said out loud because the first reading of this output is usually
        // wrong: §3.12 rule 5 makes the live matrix a configuration change, so
        // a line here may be a deliberate decision rather than a defect. The
        // audit log names who changed it and when.
        $this->line('');
        $this->line('Each line is a difference, not necessarily a defect — SEC-07 makes the live matrix editable.');
        $this->line('`audit_log` where event = ROLE_PERMISSIONS_UPDATED says who changed it and when.');

        return self::FAILURE;
    }
}
