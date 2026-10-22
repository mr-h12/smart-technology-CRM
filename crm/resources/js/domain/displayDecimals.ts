/**
 * D-110 (amending D-82 for screens): what a person **sees** of a money figure is
 * D-99's form — two places, rounded half-up — the same value the PDF prints,
 * grouped by thousands on screen only (the PDF prints no separators). Storage
 * stays `D-68` and the API publishes it as stored; every input keeps and sends
 * the full string (Design System §6.3 "format only for display"), except that a
 * pre-filled quantity, amount or percentage drops its trailing zeros through
 * {@link displayQuantity} — the same number, never rounded (D-112, U-5).
 *
 * String and `BigInt` work on purpose: no float (`DB-07`), and no arithmetic on
 * a price (`D-67`) — this only rounds what is drawn. Percentages ({@link displayPercent})
 * and FX rates never come here.
 */
export function displayDecimals<T extends string | null | undefined>(value: T): T | string {
    if (typeof value !== 'string') {
        return value;
    }

    const negative = value.startsWith('-');
    const [whole = '0', fraction = ''] = value.replace(/^[-+]/, '').split('.');
    const cents = BigInt(whole + fraction.padEnd(3, '0').slice(0, 2)) + (fraction.charAt(2) >= '5' ? 1n : 0n);
    const digits = cents.toString().padStart(3, '0');
    const grouped = digits.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative && cents > 0n ? '-' : ''}${grouped}.${digits.slice(-2)}`;
}

/** D-99's quantity: the stored string without its trailing zeros (`10.000` → `10`). */
export function displayQuantity<T extends string | null | undefined>(value: T): T | string {
    return typeof value === 'string' ? value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') : value;
}

/**
 * D-152 (U-3): the other direction of a typed rate, as `ExchangeRate::inverseOf()`
 * stores it — the rate cut to `D-68`'s eight places (`ExchangeRate::of`), then
 * 1 ÷ r cut to eight (`bcdiv('1', r, 8)`). `BigInt` on the digit string, never a
 * float (`DB-07`). Null when the rate is not a plain decimal, is nothing at eight
 * places, or its inverse is: a display preview only, the server still decides.
 */
export function displayInverseRate(rate: string): string | null {
    if (!/^\d+(\.\d+)?$/.test(rate)) {
        return null;
    }

    const [whole = '0', fraction = ''] = rate.split('.');
    const scaled = BigInt(whole + fraction.padEnd(8, '0').slice(0, 8));
    const inverse = scaled > 0n ? 10n ** 16n / scaled : 0n;

    if (inverse === 0n) {
        return null;
    }

    const digits = inverse.toString().padStart(9, '0');

    return displayQuantity(`${digits.slice(0, -8)}.${digits.slice(-8)}`);
}

/** D-112: a percentage loses its trailing zeros the same way (`15.000` → `15`), on screen and in a pre-filled input. */
export const displayPercent = displayQuantity;
