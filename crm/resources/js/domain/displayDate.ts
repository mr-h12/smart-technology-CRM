/**
 * Every date a screen draws (F-42 · A-06): Western digits in both languages
 * (`D-70`, hence `-u-nu-latn`) and one day-first order.
 *
 * A date-only field (`po_date`, `YYYY-MM-DD`) is a calendar day, not a moment,
 * so it is drawn in UTC — the zone it parses in — and never shifts a day for a
 * reader west of UTC (Module 10 · 3.3, Q-D). A timestamp is a moment and is
 * drawn in the reader's own zone (`DB-08`).
 */
function tag(locale: string): string {
    return locale.startsWith('ar') ? 'ar-EG-u-nu-latn' : 'en-GB';
}

export function displayDate(value: string | null, locale: string): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString(tag(locale), /^\d{4}-\d{2}-\d{2}$/.test(value) ? { timeZone: 'UTC' } : {});
}

/** A timestamp to the minute: the seconds are noise on a screen. */
export function displayTimestamp(value: string | null, locale: string): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString(tag(locale), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
