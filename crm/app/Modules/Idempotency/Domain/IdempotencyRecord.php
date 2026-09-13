<?php

declare(strict_types=1);

namespace App\Modules\Idempotency\Domain;

/**
 * What the store already holds for an actor + route + key (`OpenAPI §9.1`).
 *
 * `status` and `body` are null together: the first request is still running.
 * Otherwise they are the response it ended with, kept verbatim for a replay.
 *
 * @phpstan-type Body array<string, mixed>
 */
final readonly class IdempotencyRecord
{
    /** @param  array<string, mixed>|null  $body */
    public function __construct(
        public string $requestHash,
        public ?int $status,
        public ?array $body,
    ) {}

    public function inFlight(): bool
    {
        return $this->status === null;
    }
}
