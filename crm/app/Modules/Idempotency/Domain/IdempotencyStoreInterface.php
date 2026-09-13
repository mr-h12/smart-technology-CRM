<?php

declare(strict_types=1);

namespace App\Modules\Idempotency\Domain;

/**
 * `OpenAPI §9.1`'s store, keyed on "the same actor + route + key".
 *
 * Three verbs, in the order a request uses them. `claim()` is the whole
 * concurrency story: it either takes the key or reports who has it, in one
 * statement, so two copies of one request cannot both proceed.
 */
interface IdempotencyStoreInterface
{
    /**
     * Take the key for this request. Null when it was free — the caller now
     * owns it and must `complete()` or `release()` it — or the existing
     * record when somebody already holds it.
     */
    public function claim(string $userId, string $route, string $key, string $requestHash): ?IdempotencyRecord;

    /** @param  array<string, mixed>  $body */
    public function complete(string $userId, string $route, string $key, int $status, array $body): void;

    /** Give the key back when no final response was produced (§9.1 "final status"). */
    public function release(string $userId, string $route, string $key): void;
}
