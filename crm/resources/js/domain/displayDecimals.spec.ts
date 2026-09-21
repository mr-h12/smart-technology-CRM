import { describe, expect, it } from 'vitest';
import { displayDecimals } from '@/domain/displayDecimals';

/**
 * D-82: money and quantities are shown cut after the third decimal — a string
 * operation, never `Number()`, never rounding. Storage (D-68) and the API
 * strings are untouched; only what a person sees changes.
 */
describe('displayDecimals', () => {
    it('cuts a scale-6 money string after the third decimal', () => {
        expect(displayDecimals('1000.000000')).toBe('1000.000');
    });

    it('cuts a scale-4 quantity string after the third decimal', () => {
        expect(displayDecimals('66.0000')).toBe('66.000');
    });

    it('truncates, never rounds', () => {
        expect(displayDecimals('5219.3049')).toBe('5219.304');
        expect(displayDecimals('0.9999')).toBe('0.999');
    });

    it('leaves a string with three or fewer decimals unchanged — no digit is invented', () => {
        expect(displayDecimals('1000')).toBe('1000');
        expect(displayDecimals('12.5')).toBe('12.5');
        expect(displayDecimals('7.250')).toBe('7.250');
    });

    it('keeps a negative sign (an over-consumed balance, D-81 "no ceiling")', () => {
        expect(displayDecimals('-4.0000')).toBe('-4.000');
    });

    it('passes null and undefined through, so a caller\'s own "—" rule still applies', () => {
        expect(displayDecimals(null)).toBeNull();
        expect(displayDecimals(undefined)).toBeUndefined();
    });
});
