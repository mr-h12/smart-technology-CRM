import { afterEach, describe, expect, it } from 'vitest';
import { displayDate } from '@/domain/displayDate';

/**
 * Module 10 · 3.3 (Q-D) — a date-only field (`po_date`) is a calendar day, not
 * a moment: `DB-08`'s "convert for display" applies to timestamps. Read as UTC
 * midnight and drawn in a zone west of UTC, `2026-09-20` would become the 19th.
 */
describe('displayDate', () => {
    const zone = process.env.TZ;

    afterEach(() => {
        process.env.TZ = zone;
    });

    it('draws the stored calendar day in the reader’s locale', () => {
        expect(displayDate('2026-09-20', 'en')).toBe('20/09/2026');
        expect(displayDate('2026-09-20', 'ar')).toBe(new Date(Date.UTC(2026, 8, 20)).toLocaleDateString('ar-EG', { timeZone: 'UTC' }));
    });

    it('keeps the day whatever the reader’s zone', () => {
        process.env.TZ = 'America/Los_Angeles';

        expect(displayDate('2026-09-20', 'en')).toBe('20/09/2026');
    });

    it('draws a missing date as a dash', () => {
        expect(displayDate(null, 'en')).toBe('—');
    });
});
