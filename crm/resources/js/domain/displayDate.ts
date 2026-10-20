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

/** A weekly report's range, one inline string: `11/10/2026 – 15/10/2026`; a single day stays a single date. */
function displayDateRange(start: string, end: string, locale: string): string {
    return start === end ? displayDate(start, locale) : `${displayDate(start, locale)} – ${displayDate(end, locale)}`;
}

/** A report's period: RPT-MN names its month from its first day — `September 2026`, `سبتمبر 2026` (the owner, 2026-10-20); any other type its range. */
export function displayReportPeriod(report: { type: string; period_start: string; period_end: string }, locale: string): string {
    return report.type === 'monthly'
        ? new Date(report.period_start).toLocaleDateString(tag(locale), { month: 'long', year: 'numeric', timeZone: 'UTC' })
        : displayDateRange(report.period_start, report.period_end, locale);
}

/** A timestamp to the minute: the seconds are noise on a screen. */
export function displayTimestamp(value: string | null, locale: string): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString(tag(locale), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
