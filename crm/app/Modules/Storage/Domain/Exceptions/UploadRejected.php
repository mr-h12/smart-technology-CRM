<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Exceptions;

use App\Modules\Storage\Domain\UploadRejectionReason;
use RuntimeException;

/**
 * Carries the reason and nothing else.
 *
 * The message is for a log, not for a screen, and it deliberately names no path:
 * §17 keeps attachments out of reach, and a validation error quoting
 * /var/crm-files/2026/08/... hands the storage layout to whoever uploaded the
 * file. The user-facing text comes from translationKey().
 */
final class UploadRejected extends RuntimeException
{
    public function __construct(public readonly UploadRejectionReason $reason)
    {
        parent::__construct('Upload rejected: '.$reason->value);
    }

    public function translationKey(): string
    {
        return $this->reason->translationKey();
    }
}
