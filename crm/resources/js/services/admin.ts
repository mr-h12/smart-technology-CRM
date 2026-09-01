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
import { apiDelete, apiGet, apiPatch, apiPost, type Pagination } from '@/api';

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

// ── §13 screen 5 — Currencies & FX (Point 5.2) ─────────────────────────────

/**
 * `CurrencyController::payload()`.
 *
 * `rounding_unit` is a decimal **string** for the same reason `tax_percent` is:
 * §5.3's `0.01` has to survive a round trip through JSON intact, and a
 * JavaScript number is a float (`DB-07`).
 *
 * `is_base` is stated and never sent back. Which currency is the base is not an
 * edit this API offers — `PATCH /currencies/{code}` takes the unit and the
 * switch and nothing else — so the screen draws it as a fact.
 */
export interface Currency {
    code: string;
    rounding_unit: string;
    rounding_enabled: boolean;
    is_base: boolean;
}

/** `FxRateController::payload()`. `rate` is a decimal string — `DB-07`. */
export interface FxRate {
    id: string;
    from_currency: string;
    to_currency: string;
    rate: string;
    /** ISO 8601 in UTC (`DB-08`); the screen converts for display. */
    effective_from: string;
    created_at: string;
}

export async function listCurrencies(): Promise<Currency[]> {
    const result = await apiGet<{ currencies: Currency[] }>('/currencies');

    return result.data.currencies;
}

/**
 * `D-65`'s switch and `D-52`'s unit, one currency at a time.
 *
 * Partial on purpose: `UpdateCurrencyRoundingRequest` refuses a body naming
 * neither with a 422, and sending both on every save would claim a change to
 * the switch that the person never made.
 */
export async function updateCurrencyRounding(
    code: string,
    changes: { rounding_unit?: string; rounding_enabled?: boolean },
): Promise<Currency> {
    const result = await apiPatch<{ currency: Currency }>(`/currencies/${code}`, changes);

    return result.data.currency;
}

/**
 * `GET /fx-rates` — `OpenAPI §4.2`'s collection envelope, newest first.
 *
 * `page` is the only parameter there is: `ListingQuery` declares no filter and
 * no sort for this resource, and §6.2 makes an undeclared one a 400 rather than
 * something quietly ignored.
 *
 * `pagination` is nullable rather than defaulted. The block is `meta`'s and a
 * caller that invented one from `items.length` would report the page size as
 * the total — the screen draws no pager when it is absent instead.
 */
export async function listFxRates(page: number): Promise<{ items: FxRate[]; pagination: Pagination | null }> {
    const result = await apiGet<FxRate[]>(`/fx-rates?page=${page}`);

    return { items: result.data, pagination: result.meta.pagination ?? null };
}

/**
 * `POST /fx-rates` — the only write this resource has (`AP-06`).
 *
 * No `effective_from`: the field is optional and absent means *now*, which is
 * the only moment this screen offers. Back-dating a rate is a capability the
 * endpoint has and the screen does not — recorded as a gap rather than half
 * built, since a control that writes the wrong moment silently re-prices
 * nothing and quietly misdates the history.
 */
export async function recordFxRate(payload: {
    from_currency: string;
    to_currency: string;
    rate: string;
}): Promise<FxRate> {
    const result = await apiPost<{ fx_rate: FxRate }>('/fx-rates', payload);

    return result.data.fx_rate;
}

// ── §13 screen 6 — Limits & SLAs (Point 5.3) ───────────────────────────────

/**
 * One row of `GET /system-limits`, as `SystemLimitRepositoryInterface::all()`
 * describes it.
 *
 * `value` is `null` for **never configured**, which Point 1.1 made a real state
 * by leaving the column nullable: five of the six limits have no documented
 * value, and `0` would be a different answer.
 *
 * `unit` and `value_type` come from the server because they come from
 * `SystemLimit` — §13 screen 6 mixes days, hours and megabytes on one form, and
 * a client that decided which was which would be a second copy of the enum.
 * `unit` is `null` for a time of day, where the value is the reading.
 */
export interface SystemLimitEntry {
    value: string | null;
    unit: string | null;
    value_type: string;
}

/**
 * §13 screen 6's fields, keyed by `SystemLimit::value`.
 *
 * The index signature is deliberate, on `SystemSettings`'s terms: the server
 * owns the membership, and a union spelled out here would be the copy that goes
 * stale. There is no `LIMIT_KEYS` beside it either — unlike screen 4, this
 * screen draws **every** key the response carries, in the order it arrives,
 * which is `SystemLimit::cases()` and therefore §13's.
 */
export type SystemLimits = Record<string, SystemLimitEntry>;

export async function readLimits(): Promise<SystemLimits> {
    const result = await apiGet<{ limits: SystemLimits }>('/system-limits');

    return result.data.limits;
}

/**
 * Write the limits that changed, and only those.
 *
 * `UpdateSystemLimits` records one audit entry per limit with the old and the
 * new value (`AUD-01`), and `UpdateSystemLimitsRequest` refuses an empty change
 * set with a 422 — the same contract `updateSettings` above is written against.
 */
export async function updateLimits(values: Record<string, string>): Promise<SystemLimits> {
    const result = await apiPatch<{ limits: SystemLimits }>('/system-limits', { limits: values });

    return result.data.limits;
}

// ── `DB-05`'s managed lists — the screen §13 does not name (Point 5.4) ──────

/**
 * The four lists, in `ManagedList`'s own order — §4.2's sectors, §7.3's units
 * and service types, and delivery terms.
 *
 * A fifth list is a migration rather than a setting (the enum's docblock says
 * why), so this really is the closed set and the screen may draw a control per
 * entry. The strings are the URL segments the API routes on.
 */
export const MANAGED_LISTS = ['sectors', 'units', 'service_types', 'delivery_terms', 'companies'] as const;

export type ManagedListName = (typeof MANAGED_LISTS)[number];

/**
 * One row of `GET /managed-lists/{list}`, as `ManagedListController::payload()`
 * builds it.
 *
 * **Both labels, always.** §14.2 makes Arabic and English first-release
 * languages and the response never omits one — an entry accepted with a single
 * label would render blank for half the company, which is why `AddListEntry`
 * requires both.
 *
 * `position` is a real number here and not a decimal string: it is a sort key,
 * not an amount, so `DB-07` has nothing to say about it.
 */
export interface ListEntry {
    code: string;
    label_en: string;
    label_ar: string;
    position: number;
}

/**
 * `page` is the only parameter, on `listFxRates`'s terms: `ListingQuery`
 * declares no filter and no sort for this resource, and §6.2 makes an
 * undeclared one a 400 rather than something quietly ignored.
 *
 * No permission is named because the endpoint names none — authentication
 * alone, for the reason `routes/api.php` sets out at length.
 */
export async function listEntries(
    list: ManagedListName,
    page: number,
): Promise<{ items: ListEntry[]; pagination: Pagination | null }> {
    const result = await apiGet<ListEntry[]>(`/managed-lists/${list}?page=${page}`);

    return { items: result.data, pagination: result.meta.pagination ?? null };
}

/**
 * `POST /managed-lists/{list}`.
 *
 * There is still no `PATCH`, but `DELETE /managed-lists/{list}/{code}` now
 * exists and **archives** — a soft delete, so `DB-01` holds and the response
 * says `archived` rather than `deleted`. Step 6 Point 6.1 added it on the
 * owner's ruling of 2026-08-31; **this file has no caller for it yet**, which
 * is Point 6.2's screen work.
 */
export async function addListEntry(list: ManagedListName, entry: ListEntry): Promise<ListEntry> {
    const result = await apiPost<{ entry: ListEntry }>(`/managed-lists/${list}`, entry);

    return result.data.entry;
}

/**
 * `DELETE /managed-lists/{list}/{code}` — Step 6 Point 6.1's withdrawal.
 *
 * **Archive, not delete.** The server soft-deletes (`DB-01`) and answers
 * `{ archived: true }`, so nothing here says "deleted": the row is still in the
 * table and a name that claimed otherwise would be this file telling the screen
 * something untrue about storage.
 *
 * The code is the address, not a body — `OpenAPI §7.1`'s resource route. The
 * caller reloads afterwards rather than splicing the row out locally, because
 * the page it is on and the total it shows are the server's answers.
 */
export async function archiveListEntry(list: ManagedListName, code: string): Promise<void> {
    await apiDelete<{ archived: boolean }>(`/managed-lists/${list}/${encodeURIComponent(code)}`);
}
