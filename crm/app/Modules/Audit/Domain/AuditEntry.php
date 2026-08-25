<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One row of `audit_log`, assembled and checked before anything can store it.
 *
 * ── Why floats are refused ─────────────────────────────────────────────────
 *
 * `DB-07` forbids floating point anywhere near money, and this is where a price
 * change is preserved **permanently** (`D-30`, `AUD-03`). `json_encode(0.1 +
 * 0.2)` writes `0.30000000000000004` into a row nobody can ever correct. So a
 * float in a payload is refused outright rather than stored as an approximation
 * of what happened — money travels as a decimal string, which is what `D-68`
 * persists anyway.
 *
 * The check is recursive: the value that matters is rarely at the top level,
 * it is the third line item's unit price.
 */
final readonly class AuditEntry
{
    /** The width of `audit_log.user_agent`. */
    public const USER_AGENT_LIMIT = 512;

    public DateTimeImmutable $recordedAt;

    /**
     * @param  array<array-key, mixed>|null  $oldValues
     * @param  array<array-key, mixed>|null  $newValues
     */
    public function __construct(
        public AuditEvent $event,
        public string $entityType,
        public string $entityId,
        public ?array $oldValues,
        public ?array $newValues,
        public AuditContext $context,
        DateTimeInterface $recordedAt,
    ) {
        self::assertNoFloats($oldValues, 'old_values');
        self::assertNoFloats($newValues, 'new_values');

        // DB-08: stored UTC. Normalised here rather than trusted from the
        // caller, because a caller that passes local time produces a row that
        // is wrong in a way nothing later can detect.
        $this->recordedAt = DateTimeImmutable::createFromInterface($recordedAt)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Truncated to what the column holds.
     *
     * A User-Agent is attacker-influenced and unbounded, and letting the insert
     * fail would take the business transaction with it (`AUD-01`, `DB-11`) —
     * a denial of service through a header. `D-69` discards an overlong
     * correlation id instead of truncating it, and the difference is the
     * point: a truncated trace id matches nothing upstream and is worse than
     * none, while a truncated user agent is still the user agent.
     */
    public function userAgent(): ?string
    {
        $agent = $this->context->userAgent;

        if ($agent === null) {
            return null;
        }

        return mb_strcut($agent, 0, self::USER_AGENT_LIMIT);
    }

    /**
     * `AUD-05` — the structured record, correlation id included.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'event' => $this->event->value,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'actor_id' => $this->context->actorId,
            // SEC-10: a structured log line that named only the actor would
            // read identically whether or not the action was taken through
            // somebody else's account.
            'impersonated_user_id' => $this->context->impersonatedUserId,
            'ip' => $this->context->ip,
            'request_id' => $this->context->requestId,
            'correlation_id' => $this->context->correlationId,
            'recorded_at' => $this->recordedAt->format(DateTimeInterface::RFC3339),
        ];
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     */
    private static function assertNoFloats(?array $values, string $field): void
    {
        if ($values === null) {
            return;
        }

        array_walk_recursive($values, static function (mixed $value) use ($field): void {
            if (is_float($value)) {
                throw new InvalidArgumentException(
                    "DB-07 forbids floating point: {$field} carries a float, and an audit row "
                    .'is permanent (D-30). Pass money as a decimal string.',
                );
            }
        });
    }
}
