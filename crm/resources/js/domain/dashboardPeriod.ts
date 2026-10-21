/**
 * The dashboard's period (`D-145`): week · month · year · custom, calendar
 * boundaries in company time, the week Sunday → Saturday. Drawn from the
 * company's `today` the server sends, so the browser's clock and zone never
 * decide which month is "current".
 *
 * The chosen kind is remembered per device. Browser storage can be missing or
 * throw (a private window, blocked site data), so every access is guarded and a
 * failure falls back to the month.
 */
export type PeriodKind = 'week' | 'month' | 'year' | 'custom';

export const PERIOD_KINDS: readonly PeriodKind[] = ['week', 'month', 'year', 'custom'];

export interface Period {
    kind: PeriodKind;
    /** `Y-m-d`, inclusive */
    from: string;
    /** `Y-m-d`, inclusive */
    to: string;
}

const STORAGE_KEY = 'dashboard.period';

/** A `Y-m-d` read and written at UTC midnight, so no local zone shifts the day. */
const day = (ymd: string): Date => new Date(`${ymd}T00:00:00Z`);
const ymd = (date: Date): string => date.toISOString().slice(0, 10);

export function periodFor(kind: PeriodKind, today: string, custom?: { from: string; to: string }): Period {
    const date = day(today);
    const year = date.getUTCFullYear();
    const month = date.getUTCMonth();

    switch (kind) {
        case 'week': {
            const sunday = new Date(Date.UTC(year, month, date.getUTCDate() - date.getUTCDay()));
            return { kind, from: ymd(sunday), to: ymd(new Date(sunday.getTime() + 6 * 86_400_000)) };
        }
        case 'year':
            return { kind, from: `${year}-01-01`, to: `${year}-12-31` };
        case 'custom':
            return custom !== undefined && custom.from <= custom.to
                ? { kind, ...custom }
                : { ...periodFor('month', today), kind };
        case 'month':
        default:
            return { kind: 'month', from: ymd(new Date(Date.UTC(year, month, 1))), to: ymd(new Date(Date.UTC(year, month + 1, 0))) };
    }
}

/** The remembered period, recomputed against `today`; the month when nothing usable is stored. */
export function rememberedPeriod(today: string): Period {
    try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null') as Partial<Period> | null;
        if (stored !== null && PERIOD_KINDS.includes(stored.kind as PeriodKind)) {
            const custom = typeof stored.from === 'string' && typeof stored.to === 'string' ? { from: stored.from, to: stored.to } : undefined;
            return periodFor(stored.kind as PeriodKind, today, custom);
        }
    } catch {
        // Storage unavailable or unreadable: the default below.
    }

    return periodFor('month', today);
}

export function rememberPeriod(period: Period): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(period.kind === 'custom' ? period : { kind: period.kind }));
    } catch {
        // A per-device convenience only; the page works without it.
    }
}
