/**
 * D-82: what a person **sees** of a money or quantity figure is the server's
 * string cut after its third decimal. Storage stays `D-68` (scale 6 money,
 * scale 4 quantities) and the API publishes it as stored; every input keeps
 * and sends the full string (Design System §6.3 "format only for display").
 *
 * A string operation on purpose: no `Number()` (`DB-07`), no rounding
 * (`D-06` — `5219.3049` shows as `5219.304`), no arithmetic (`D-67`, the SPA
 * never owns a calculation). Fewer than three decimals are left as they are;
 * no digit is invented. Percentages and FX rates never come here.
 */
export function displayDecimals<T extends string | null | undefined>(value: T): T {
    if (typeof value !== 'string') {
        return value;
    }

    const dot = value.indexOf('.');

    return (dot === -1 ? value : value.slice(0, dot + 4)) as T;
}
