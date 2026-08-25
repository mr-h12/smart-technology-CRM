import { describe, expect, it } from 'vitest';
import {
    MINIMUM_LENGTH,
    VERIFICATION_CODE_LENGTH,
    checkPassword,
    isPasswordAcceptable,
    isVerificationCodeShaped,
} from '@/domain/passwordPolicy';

/**
 * Point 5.4 — `D-28`'s hint and `SEC-04`'s code shape.
 *
 * The server decides (`PasswordPolicy`, `ChangePasswordRequest`) and
 * `PasswordPolicyMirrorTest` pins these numbers to it. What is tested here is
 * the other half: that the hint agrees with the rule for the inputs a person
 * actually types, including the Arabic ones.
 */

describe('checkPassword', () => {
    it('answers the three conditions separately, so the form can say which is missing', () => {
        expect(checkPassword('abcdefgh')).toEqual({ length: true, letter: true, digit: false });
        expect(checkPassword('12345678')).toEqual({ length: true, letter: false, digit: true });
        expect(checkPassword('ab12')).toEqual({ length: false, letter: true, digit: true });
    });

    it('accepts a letter in any script, as the server\'s \\p{L} does', () => {
        // Arabic is a first-release language. A Latin-only letter class here
        // would refuse to submit a password the API accepts.
        expect(isPasswordAcceptable('كلمةسرية12')).toBe(true);
    });

    it('counts characters and not UTF-16 code units', () => {
        // Eight astral characters is eight characters. `.length` would say 16
        // and pass a password the server measures as eight — same answer here,
        // but the two disagree the moment the string is shorter.
        expect(checkPassword('😀😀😀a1').length).toBe(false);
    });

    it('does not require a symbol — D-28 says letters and numbers', () => {
        expect(isPasswordAcceptable('Passw0rd')).toBe(true);
    });

    it('holds the documented minimum', () => {
        expect(MINIMUM_LENGTH).toBe(8);
        expect(isPasswordAcceptable('Passw0r')).toBe(false);
    });
});

describe('isVerificationCodeShaped', () => {
    it('is exactly six digits', () => {
        expect(VERIFICATION_CODE_LENGTH).toBe(6);
        expect(isVerificationCodeShaped('012345')).toBe(true);
        expect(isVerificationCodeShaped('12345')).toBe(false);
        expect(isVerificationCodeShaped('1234567')).toBe(false);
        expect(isVerificationCodeShaped('abcdef')).toBe(false);
        expect(isVerificationCodeShaped('')).toBe(false);
    });
});
