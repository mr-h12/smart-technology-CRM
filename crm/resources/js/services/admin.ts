/**
 * Module 2's endpoints, typed once.
 *
 * `api.ts` is the transport — envelopes, headers, `ApiError`. This is the
 * catalogue of what Module 2 exposes, on the same terms as
 * {@link file://./identity.ts}: a screen names an operation rather than
 * assembling a path, and the response shapes live beside the calls that produce
 * them instead of inside each component.
 *
 * Nothing here decides anything. Every function is one request (`D-67`).
 *
 * ── Every value is a string, and that is `DB-07` ───────────────────────────
 *
 * `defaults.tax_percent` is a percentage and `rounding_unit` is an amount; both
 * travel as decimal strings because a JavaScript number is a float, and a float
 * is what `DB-07` forbids anywhere near money. The server sends strings and
 * this file never parses one — a screen that wants to compare two of them has
 * the same obligation.
 */
import { apiGet, apiPatch } from '@/api';

/**
 * §13 screen 4's fields, keyed by `SystemSetting::value`.
 *
 * `null` means *never set*, not empty: Point 1.1 made the column nullable so
 * the two are distinguishable, and §13 draws a form where a missing field and
 * a blank field are different things to look at.
 *
 * The index signature is deliberate. The server owns the membership — the
 * `SystemSetting` enum — and a union type spelled out here would be a second
 * copy of it, which is the copy that goes stale when a ninth field lands.
 * `SETTING_KEYS` below is the screen's rendering order and not a contract.
 */
export type SystemSettings = Record<string, string | null>;

/**
 * The order §13 screen 4 lists them in, which is the order the form draws.
 *
 * Only these are rendered, and the two §13 names that are missing are missing
 * on purpose: the **logo** is Module 5 (a `files` row, `SEC-15`'s scan and
 * `D-38`'s permission-checked download) and the **PDF and email templates** are
 * Module 9. Owner decision of 2026-08-28: no disabled placeholders for either —
 * a control that cannot be used is a promise the product has not made.
 *
 * A key the server returns and this list omits is still carried in
 * `SystemSettings` and simply not drawn, so a ninth field added server-side
 * appears the moment it is added here rather than breaking the screen.
 */
export const SETTING_KEYS = [
    'company.name',
    'company.address',
    'company.phones',
    'defaults.currency',
    'defaults.tax_percent',
    'locale.language',
    'locale.timezone',
    'locale.date_format',
] as const;

export type SettingKey = (typeof SETTING_KEYS)[number];

export async function readSettings(): Promise<SystemSettings> {
    const result = await apiGet<{ settings: SystemSettings }>('/settings');

    return result.data.settings;
}

/**
 * Write the fields that changed, and only those.
 *
 * `PATCH` and a partial body: `UpdateSettingsRequest` refuses an empty
 * `settings` object with a 422, and sending every field on every save would
 * write an audit entry per field per save — `SETTINGS_UPDATED` is recorded with
 * the old and the new value, and a log full of "changed X to X" is a log nobody
 * reads.
 */
export async function updateSettings(values: Record<string, string>): Promise<SystemSettings> {
    const result = await apiPatch<{ settings: SystemSettings }>('/settings', { settings: values });

    return result.data.settings;
}
