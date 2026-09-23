/**
 * A date-only field (`po_date`, `YYYY-MM-DD`) in the reader's locale (Module 10
 * · 3.3, Q-D). It is a calendar day, not a moment, so it is drawn in UTC — the
 * zone it parses in — and never shifts a day for a reader west of UTC. A
 * timestamp is a different thing and keeps its local conversion (`DB-08`).
 *
 * ponytail: the eight older `onDate()` copies (debt row "table boilerplate")
 * draw date-only fields in the local zone; they move here when that row is paid.
 */
export function displayDate(value: string | null, locale: string): string {
    return value === null ? '—' : new Date(value).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-GB', { timeZone: 'UTC' });
}
