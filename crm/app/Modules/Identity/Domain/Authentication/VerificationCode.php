<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * `SEC-04`'s emailed verification code — §9 Flow 0 step 2.
 *
 * ── Six digits, and why that is defensible only with the rest of the flow ──
 *
 * Six decimal digits is 10^6 — about 20 bits. On its own that is not a secret.
 * It is acceptable here because it is never the only thing standing in the way:
 * the caller must already hold a live session (`auth`), must already know the
 * **current password**, the code lives 15 minutes, generation is rate-limited
 * (`SEC-11`), and a fixed number of wrong codes destroys the challenge outright.
 * Take any one of those away and six digits becomes a defect. That is written
 * down here because the number is the part a later reader will be tempted to
 * keep while dropping one of the others.
 *
 * ── `random_int`, not `rand` and not `mt_rand` ─────────────────────────────
 *
 * `random_int` is PHP's CSPRNG and throws rather than degrading if no source of
 * entropy is available. `mt_rand` is a Mersenne Twister: its output is
 * predictable from previous outputs, which for a code mailed to an inbox means
 * an attacker who has seen one code can compute the next.
 *
 * ── Digits, deliberately ───────────────────────────────────────────────────
 *
 * A person retypes this from a mail into a form, sometimes on a phone in the
 * field (§14.2's PWA). Letters bring case questions and the 0/O and 1/l pairs
 * with them, and the entropy they add is worth less than the entry errors they
 * cause. The strength is in the surrounding controls, not in the alphabet.
 */
final readonly class VerificationCode
{
    public const LENGTH = 6;

    private function __construct(#[SensitiveParameter] public string $value) {}

    /** A fresh code from the CSPRNG. */
    public static function issue(): self
    {
        // Zero-padded so every code is exactly LENGTH characters: without the
        // padding, one code in ten is five digits and the "must be 6" rule the
        // form applies would reject a code the system itself issued.
        return new self(str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT));
    }

    /**
     * A code as the caller typed it.
     *
     * @throws InvalidArgumentException when it is not LENGTH digits
     */
    public static function fromPresented(#[SensitiveParameter] string $presented): self
    {
        if (preg_match('/^[0-9]{'.self::LENGTH.'}$/D', $presented) !== 1) {
            throw new InvalidArgumentException('A verification code is '.self::LENGTH.' digits.');
        }

        return new self($presented);
    }
}
