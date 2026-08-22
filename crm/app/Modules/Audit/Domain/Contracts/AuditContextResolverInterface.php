<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\AuditContext;

/** Where the ambient half of `AUD-02` comes from. */
interface AuditContextResolverInterface
{
    public function current(): AuditContext;
}
