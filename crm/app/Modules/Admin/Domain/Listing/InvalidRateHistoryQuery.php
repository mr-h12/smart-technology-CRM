<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a rate-history query that cannot be honoured.
 *
 * §6.1: *"Invalid or excessive values return `400 invalid_request`."* §6.2:
 * *"Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**."*
 *
 * Which is why the query is parsed here and not by a Form Request: a failed
 * Form Request is a `422 validation_failed`, and the contract asks for a 400.
 * The SPA acts on the difference — 422 means a person typed something wrong in
 * a form, 400 means the client built a URL this API does not offer.
 *
 * **A second copy of Identity's `InvalidListQuery`, deliberately.** Admin may
 * not reach into Identity — `deptrac.modules.yaml` grants it `Framework`,
 * `SharedContracts` and `AuditContract` and nothing else, and `CLAUDE.md`'s
 * module-isolation rule says the same from the other side. Moving the original
 * to a shared layer is a boundary change and therefore its own point; it is
 * recorded in `CHECKLIST.md` beside the `ApiEnvelope` debt, which is the same
 * debt for the same reason.
 */
final class InvalidRateHistoryQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid rate-history query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'admin.list_query.'.$this->detailCode;
    }
}
