<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Administration;

use RuntimeException;

/**
 * `OpenAPI §6.1` and `§6.2` — a list query that cannot be honoured.
 *
 * §6.1: "Invalid or excessive values return `400 invalid_request`." §6.2:
 * "Reject unknown filter, sort, group, or include values with `400
 * invalid_request`; **never ignore them silently**."
 *
 * This is why the query is parsed in Domain instead of by a Form Request. A
 * failed Form Request is a `422 validation_failed`, and the contract asks for
 * `400 invalid_request` here — a difference the SPA acts on, because 422 means
 * "the person typed something wrong" and 400 means "the client built a bad
 * URL".
 */
final class InvalidListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'identity.list_query.'.$this->detailCode;
    }
}
