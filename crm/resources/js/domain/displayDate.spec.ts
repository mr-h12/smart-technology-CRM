import { afterEach, describe, expect, it } from 'vitest';
import { displayDate, displayTimestamp } from '@/domain/displayDate';

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
    });

    it('draws Western digits in Arabic, day first (D-70, F-42 · A-06)', () => {
        const arabic = displayDate('2026-09-20', 'ar');

        expect(arabic).not.toMatch(/[٠-٩]/);
        expect(arabic.replace(/[^0-9/]/g, '')).toBe('20/9/2026');
    });

    it('draws a timestamp’s own day in the reader’s zone (DB-08)', () => {
        process.env.TZ = 'Africa/Cairo';

        expect(displayDate('2026-09-20T22:30:00+00:00', 'en')).toBe('21/09/2026');
    });

    it('keeps the day whatever the reader’s zone', () => {
        process.env.TZ = 'America/Los_Angeles';

        expect(displayDate('2026-09-20', 'en')).toBe('20/09/2026');
    });

    it('draws a missing date as a dash', () => {
        expect(displayDate(null, 'en')).toBe('—');
        expect(displayTimestamp(null, 'en')).toBe('—');
    });
});

describe('displayTimestamp', () => {
    it('draws the day and the minute, never the seconds, in Western digits', () => {
        process.env.TZ = 'UTC';

        expect(displayTimestamp('2026-09-20T09:05:07+00:00', 'en')).toBe('20/09/2026, 09:05');

        const arabic = displayTimestamp('2026-09-20T09:05:07+00:00', 'ar');
        expect(arabic).not.toMatch(/[٠-٩]/);
        expect(arabic).not.toContain('07');
    });
});
