import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { displayDecimals, displayQuantity } from '@/domain/displayDecimals';

/**
 * D-110 (amending D-82 for screens, F-42 · A-26): a screen shows money in D-99's
 * form — two places, rounded half-up, grouped — and a quantity without its
 * trailing zeros. A string operation, never `Number()` (`DB-07`). Storage (D-68)
 * and the API strings are untouched; only what a person sees changes.
 */
describe('displayDecimals (money)', () => {
    it('shows two places, grouped', () => {
        expect(displayDecimals('16610.000')).toBe('16,610.00');
        expect(displayDecimals('1000.000000')).toBe('1,000.00');
    });

    it('rounds half-up on the digit string, as the PDF does (D-99)', () => {
        expect(displayDecimals('5219.305000')).toBe('5,219.31');
        expect(displayDecimals('5219.304999')).toBe('5,219.30');
        expect(displayDecimals('999.995')).toBe('1,000.00');
    });

    it('pads a short fraction and keeps a sign, never a negative zero', () => {
        expect(displayDecimals('12.5')).toBe('12.50');
        expect(displayDecimals('7')).toBe('7.00');
        expect(displayDecimals('-0.400000')).toBe('-0.40');
        expect(displayDecimals('-0.001')).toBe('0.00');
    });

    it('passes null and undefined through, so a caller\'s own "—" rule still applies', () => {
        expect(displayDecimals(null)).toBeNull();
        expect(displayDecimals(undefined)).toBeUndefined();
    });

    it('never turns the string into a float', () => {
        expect(readFileSync('resources/js/domain/displayDecimals.ts', 'utf8')).not.toMatch(/\b(Number|parseFloat)\(/);
    });
});

describe('displayQuantity', () => {
    it('drops trailing zeros and a bare point', () => {
        expect(displayQuantity('10.000')).toBe('10');
        expect(displayQuantity('66.0000')).toBe('66');
        expect(displayQuantity('2.5000')).toBe('2.5');
        expect(displayQuantity('100')).toBe('100');
        expect(displayQuantity('-4.0000')).toBe('-4');
    });

    it('passes null through', () => {
        expect(displayQuantity(null)).toBeNull();
    });
});
