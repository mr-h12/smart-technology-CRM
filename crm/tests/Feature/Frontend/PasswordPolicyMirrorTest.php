<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Modules\Identity\Domain\Authentication\VerificationCode;
use App\Modules\Identity\Domain\PasswordPolicy;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 5.4 — the SPA's copy of `D-28` and `SEC-04`'s two numbers.
 *
 * `resources/js/domain/passwordPolicy.ts` mirrors {@see PasswordPolicy} and
 * {@see VerificationCode} so the account-security form can say what is wrong
 * while it is being typed. The server is the authority — `ChangePasswordTest`
 * proves that end — and this asserts the two have not drifted apart.
 *
 * ── Why the duplication is allowed to exist ────────────────────────────────
 *
 * The same trade-off `RoleAssignmentMirrorTest` records: the alternative is a
 * checklist that cannot tick until a round trip, or an endpoint whose only job
 * is to restate a number §9 Flow 0 already prints. The copy is forced by the
 * interaction, so it is pinned rather than trusted.
 *
 * ⚠️ **The letter class is mirrored too, and that is the half that matters.**
 * The server tests `\p{L}` with `/u`; an `[A-Za-z]` check in the SPA would
 * mark a valid Arabic passphrase invalid and refuse to send something the API
 * would have accepted — in the first-release language, on a security screen.
 */
final class PasswordPolicyMirrorTest extends TestCase
{
    private const MIRROR = 'resources/js/domain/passwordPolicy.ts';

    public function test_the_minimum_length_matches_d_28(): void
    {
        self::assertSame(PasswordPolicy::MINIMUM_LENGTH, self::intConstant('MINIMUM_LENGTH'));
    }

    public function test_the_verification_code_length_matches_sec_04(): void
    {
        self::assertSame(VerificationCode::LENGTH, self::intConstant('VERIFICATION_CODE_LENGTH'));
    }

    public function test_the_letter_test_is_unicode_aware_like_the_server(): void
    {
        $source = self::read();

        self::assertStringContainsString('/\p{L}/u.test(', $source,
            'D-28 says "letters"; the server asks \p{L} with /u, and Arabic is a first-release language.');
        self::assertStringNotContainsString('[A-Za-z]', $source);
    }

    private static function intConstant(string $name): int
    {
        $source = self::read();

        if (preg_match('/export const '.$name.' = (\d+);/', $source, $matches) !== 1) {
            throw new RuntimeException('passwordPolicy.ts no longer exports '.$name.'.');
        }

        return (int) $matches[1];
    }

    private static function read(): string
    {
        $contents = file_get_contents(base_path(self::MIRROR));

        if ($contents === false) {
            throw new RuntimeException('Could not read '.self::MIRROR.'.');
        }

        return $contents;
    }
}
