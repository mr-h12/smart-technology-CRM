/**
 * `D-28` and `SEC-04`'s two numbers, mirrored for the form.
 *
 * ── This is a hint, and the server decides ─────────────────────────────────
 *
 * `PasswordPolicy::isSatisfiedBy()` judges every password that reaches
 * `POST /auth/change-password`, and `ChangePasswordRequest` judges its shape
 * before that. This copy exists so the field can say what is wrong while it is
 * being typed rather than after a round trip. A caller who edits it in a
 * debugger gets a green checklist and a `422`.
 *
 * ⚠️ Two copies of a documented rule drift, so `PasswordPolicyMirrorTest`
 * reads both this file and the PHP constants and asserts they agree — the same
 * arrangement `roleAssignment.ts` uses, for the same reason: the duplication
 * is forced by the interaction, so it is pinned rather than trusted.
 */

/** `D-28`: "8-character password with letters and numbers" (§9 Flow 0). */
export const MINIMUM_LENGTH = 8;

/** `SEC-04`'s emailed code, as `VerificationCode::LENGTH` fixes it. */
export const VERIFICATION_CODE_LENGTH = 6;

export interface PasswordChecklist {
    /** At least `MINIMUM_LENGTH` characters. */
    length: boolean;
    /** `D-28`'s "letters" — any script's letter, not only A–Z, since half this product is Arabic. */
    letter: boolean;
    /** `D-28`'s "numbers". */
    digit: boolean;
}

/**
 * The three conditions, each answered separately so the form can show which
 * one is missing rather than a single red field.
 *
 * `\p{L}` with the `u` flag, matching the server's own `preg_match('/\p{L}/u')`
 * — a Latin-only letter class here would call a valid Arabic passphrase invalid and
 * refuse to submit something the API would have accepted.
 */
export function checkPassword(password: string): PasswordChecklist {
    return {
        length: [...password].length >= MINIMUM_LENGTH,
        letter: /\p{L}/u.test(password),
        digit: /\d/u.test(password),
    };
}

export function isPasswordAcceptable(password: string): boolean {
    const checklist = checkPassword(password);

    return checklist.length && checklist.letter && checklist.digit;
}

/** `SEC-04`: exactly six digits, which is what the endpoint's `digits:6` rule means. */
export function isVerificationCodeShaped(code: string): boolean {
    return new RegExp(`^[0-9]{${VERIFICATION_CODE_LENGTH}}$`).test(code);
}
