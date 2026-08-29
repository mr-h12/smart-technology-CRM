import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import SystemSettingsView from '@/pages/settings/SystemSettingsView.vue';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 2, Point 5.1 — §13 screen 4, *System Settings*.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09`: enforcement is at the API, and
 * `SettingsEndpointTest` is where the refusals are proved against the server.
 * What is proved here is what the screen **draws and sends** — a form that
 * silently drops a field, or one that reports success on a refusal, is a
 * presentation defect worth catching on its own.
 *
 * ── Eight fields, not ten ──────────────────────────────────────────────────
 *
 * §13 screen 4 names ten. The logo is Module 5 (`SEC-15`'s scan, `D-38`'s
 * permission-checked download) and the PDF/email templates are Module 9, so
 * `SystemSetting` exposes eight and the screen renders eight. **Owner decision
 * of 2026-08-28, option (A):** no disabled placeholders for the two that are
 * not built — a control that cannot be used is a promise the product has not
 * made.
 *
 * ── `DB-07` reaches the screen ─────────────────────────────────────────────
 *
 * `defaults.tax_percent` is a decimal **string** end to end. A number input
 * that handed `12.5` back to the API as a JavaScript float would be the one
 * place in this product where a float touches a money-adjacent value.
 */

const SETTINGS = {
    'company.name': 'Smart Technology',
    'company.address': null,
    'company.phones': null,
    'defaults.currency': 'EGP',
    'defaults.tax_percent': '14',
    'locale.language': 'ar',
    'locale.timezone': 'Africa/Cairo',
    'locale.date_format': 'd/m/Y',
};

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function envelope(settings: Record<string, string | null>): Response {
    return json(200, { data: { settings }, meta: { request_id: 'req_test' } });
}

function i18n(locale = 'en') {
    return createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
}

/**
 * A role holding the settings row and nothing else.
 *
 * ⚠️ **Deliberately not the Super Admin**, and that is what keeps every case
 * below reading the way it did before S-02 merged the three screens into one
 * page. This profile draws section one and **no** other section, so a test
 * about the eight fields still stubs one endpoint and asserts one form. §3.12
 * rule 5 makes such a role real rather than a fixture convenience: the matrix
 * is configuration, and an administrator may build exactly this.
 */
const SETTINGS_ADMIN: AuthenticatedUser = {
    id: '01a0-set',
    name: 'Settings Administrator',
    email: 'settings.admin@example.test',
    is_active: true,
    role: { id: '01a0-role-set', slug: 'settings_admin', name: 'Settings Administrator' },
    permissions: ['admin.system_settings.all'],
    unconditional_access: false,
};

const SUPER_ADMIN: AuthenticatedUser = {
    id: '01a0-sa',
    name: 'Test Super Admin',
    email: 'super.admin@example.test',
    is_active: true,
    role: { id: '01a0-role-sa', slug: 'super_admin', name: 'Super Admin' },
    permissions: [],
    unconditional_access: true,
};

/** §3.11: **FX rates** and neither of the other two rows. */
const MANAGER: AuthenticatedUser = {
    id: '01a0-mgr',
    name: 'Test Manager',
    email: 'manager@example.test',
    is_active: true,
    role: { id: '01a0-role-mgr', slug: 'manager', name: 'Manager' },
    permissions: ['admin.fx_rates.all'],
    unconditional_access: false,
};

/**
 * Sign in, then mount.
 *
 * The login response is answered here and **everything else is delegated to
 * whatever the test already stubbed**, so each case below keeps its own
 * one-endpoint stub and its own assertions on it. `auth`'s state is module
 * level, so signing in again is what resets it between cases.
 */
async function render(locale = 'en', profile: AuthenticatedUser = SETTINGS_ADMIN) {
    const delegate = globalThis.fetch as typeof globalThis.fetch;

    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');

    return mount(SystemSettingsView, { global: { plugins: [i18n(locale)] } });
}

beforeEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('SystemSettingsView', () => {
    it('shows the loading state before the settings arrive', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        const view = await render();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('renders the eight fields the API exposes, and no more', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = await render();
        await flushPromises();

        const fields = view.findAll('[data-setting-key]');

        expect(fields.map((f) => f.attributes('data-setting-key')).sort()).toEqual([
            'company.address',
            'company.name',
            'company.phones',
            'defaults.currency',
            'defaults.tax_percent',
            'locale.date_format',
            'locale.language',
            'locale.timezone',
        ]);
    });

    it('does not render a control for the logo or the templates', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = await render();
        await flushPromises();

        const html = view.html();

        expect(html).not.toContain('company.logo');
        expect(html).not.toContain('templates.pdf');
        expect(html).not.toContain('templates.email');
    });

    it('fills each control with the stored value', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = await render();
        await flushPromises();

        const name = view.get('[data-setting-key="company.name"] input');

        expect((name.element as HTMLInputElement).value).toBe('Smart Technology');
    });

    /** Null is "never set" and must render as empty, not as the word "null". */
    it('renders an unset field as empty rather than as a literal', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = await render();
        await flushPromises();

        const address = view.get('[data-setting-key="company.address"] input');

        expect((address.element as HTMLInputElement).value).toBe('');
    });

    it('shows the error state and offers a retry when the reading fails', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(500, { error: { code: 'server_error' } })));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-retry"]').exists()).toBe(true);
    });

    /** §3.12 rule 1 — the server refuses, and the screen says so in its own words. */
    it('shows the permission-denied state on a 403', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'permission_denied' } })));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    // ── saving ─────────────────────────────────────────────────────────────

    it('sends only the fields that were edited', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH' ? envelope({ ...SETTINGS, 'company.name': 'Changed' }) : envelope(SETTINGS),
        );
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="company.name"] input').setValue('Changed');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(patch).toBeDefined();
        expect(JSON.parse(patch![1]?.body as string)).toEqual({
            settings: { 'company.name': 'Changed' },
        });
    });

    it('does not submit at all when nothing was edited', async () => {
        // Typed with both parameters even though the body never varies: the
        // assertion below reads `calls[n][1]`, and a zero-argument mock types
        // every call as an empty tuple.
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => envelope(SETTINGS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);
    });

    /** `DB-07`: the tax percentage leaves as a decimal string, never a float. */
    it('sends the tax percentage as a string', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH' ? envelope({ ...SETTINGS, 'defaults.tax_percent': '12.5' }) : envelope(SETTINGS),
        );
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="defaults.tax_percent"] input').setValue('12.5');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');
        const body = JSON.parse(patch![1]?.body as string);

        expect(body.settings['defaults.tax_percent']).toBe('12.5');
        expect(typeof body.settings['defaults.tax_percent']).toBe('string');
    });

    it('confirms the save and adopts what the server returned', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? envelope({ ...SETTINGS, 'company.name': 'Server Wins' })
                : envelope(SETTINGS),
        ));

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="company.name"] input').setValue('Changed');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="settings-saved"]').exists()).toBe(true);
        expect((view.get('[data-setting-key="company.name"] input').element as HTMLInputElement).value)
            .toBe('Server Wins');
    });

    /** A refusal is never reported as a success — §5.1's 422, field by field. */
    it('reports a validation refusal against the field it names', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'The given data was invalid.',
                        details: [{ field: 'settings.defaults.tax_percent', code: 'invalid', message: 'Not a number.' }],
                    },
                })
                : envelope(SETTINGS),
        ));

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="defaults.tax_percent"] input').setValue('lots');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="settings-saved"]').exists()).toBe(false);
        expect(view.get('[data-setting-key="defaults.tax_percent"]').text()).toContain('Not a number.');
    });

    it('keeps the edit on screen when the save is refused', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH' ? json(500, { error: { code: 'server_error' } }) : envelope(SETTINGS),
        ));

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="company.name"] input').setValue('Unsaved Work');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        expect((view.get('[data-setting-key="company.name"] input').element as HTMLInputElement).value)
            .toBe('Unsaved Work');
    });

    // ── S-02.2 / S-02.3: assisted input ────────────────────────────────────

    const WITH_CURRENCIES = vi.fn(async (input: string) =>
        /\/currencies$/.test(String(input))
            ? json(200, { data: { currencies: [
                { code: 'EGP', rounding_unit: '1', rounding_enabled: true, is_base: true },
                { code: 'USD', rounding_unit: '0.01', rounding_enabled: true, is_base: false },
            ] }, meta: {} })
            : envelope(SETTINGS));

    /** §14.2 closes this set at two, so it is the one field that may be closed. */
    it('offers the language as a real select of the two documented languages', async () => {
        vi.stubGlobal('fetch', WITH_CURRENCIES);

        const view = await render();
        await flushPromises();

        const select = view.get('[data-setting-key="locale.language"] select');
        const values = select.findAll('option').map((o) => o.attributes('value'));

        expect(values).toEqual(['', 'ar', 'en']);
        expect(view.findAll('[data-testid="setting-select"]')).toHaveLength(1);
    });

    it('sends the chosen language as a string', async () => {
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (init?.method === 'PATCH') {
                return envelope({ ...SETTINGS, 'locale.language': 'en' });
            }

            return /\/currencies$/.test(String(input))
                ? json(200, { data: { currencies: [] }, meta: {} })
                : envelope(SETTINGS);
        });
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.get('[data-setting-key="locale.language"] select').setValue('en');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(JSON.parse(patch![1]?.body as string)).toEqual({ settings: { 'locale.language': 'en' } });
    });

    it('offers the currencies the API returns under the default-currency field', async () => {
        vi.stubGlobal('fetch', WITH_CURRENCIES);

        const view = await render();
        await flushPromises();

        const field = view.get('[data-setting-key="defaults.currency"]');
        const options = field.findAll('datalist option').map((o) => o.attributes('value'));

        expect(options).toEqual(['EGP', 'USD']);
        expect(field.get('input').attributes('list')).toBe('options-defaults.currency');
    });

    /** The platform's IANA list, not one written here. */
    it('offers the IANA time zones under the time-zone field', async () => {
        vi.stubGlobal('fetch', WITH_CURRENCIES);

        const view = await render();
        await flushPromises();

        const field = view.get('[data-setting-key="locale.timezone"]');
        const options = field.findAll('datalist option').map((o) => o.attributes('value'));

        expect(options.length).toBeGreaterThan(100);
        expect(options).toContain('Africa/Cairo');

        // ⚠️ The list must be **attached**. Measured: break 2 detached every
        // `list` binding and this case still passed on the options alone — an
        // orphaned datalist is a dropdown that renders nothing.
        expect(field.get('input').attributes('list')).toBe('options-locale.timezone');
    });

    it('suggests date formats and tax percentages without closing either set', async () => {
        vi.stubGlobal('fetch', WITH_CURRENCIES);

        const view = await render();
        await flushPromises();

        const dateField = view.get('[data-setting-key="locale.date_format"]');
        const taxField = view.get('[data-setting-key="defaults.tax_percent"]');

        expect(dateField.findAll('datalist option').map((o) => o.attributes('value')))
            .toEqual(['d/m/Y', 'Y-m-d', 'd-m-Y']);
        expect(taxField.findAll('datalist option').map((o) => o.attributes('value')))
            .toEqual(['0', '14']);

        // Attached, not merely present — see the time-zone case above.
        expect(dateField.get('input').attributes('list')).toBe('options-locale.date_format');
        expect(taxField.get('input').attributes('list')).toBe('options-defaults.tax_percent');

        // Still text controls, still typeable — and `DB-07` on the tax.
        expect(dateField.get('input').attributes('type')).toBe('text');
        expect(taxField.get('input').attributes('type')).toBe('text');
        expect(taxField.get('input').attributes('inputmode')).toBe('decimal');
    });

    /**
     * ⚠️ **The regression a `<select>` would have shipped.** The server closes
     * none of these sets (`SystemSetting::rule()` is `string`), so a stored
     * value outside the suggestions is legal — and a select would have silently
     * dropped it from the control meant to be showing it.
     */
    it('still shows a stored value that is not among the suggestions', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: string) =>
            /\/currencies$/.test(String(input))
                ? json(200, { data: { currencies: [] }, meta: {} })
                : envelope({ ...SETTINGS, 'locale.timezone': 'Mars/Olympus_Mons', 'defaults.tax_percent': '7.5' })));

        const view = await render();
        await flushPromises();

        expect((view.get('[data-setting-key="locale.timezone"] input').element as HTMLInputElement).value)
            .toBe('Mars/Olympus_Mons');
        expect((view.get('[data-setting-key="defaults.tax_percent"] input').element as HTMLInputElement).value)
            .toBe('7.5');
    });

    /** A suggestion list the caller could not load costs the dropdown, not the field. */
    it('keeps the currency field usable when the currencies cannot be read', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: string) =>
            /\/currencies$/.test(String(input))
                ? json(403, { error: { code: 'permission_denied' } })
                : envelope(SETTINGS)));

        const view = await render();
        await flushPromises();

        const field = view.get('[data-setting-key="defaults.currency"]');

        expect(field.find('datalist').exists()).toBe(false);
        expect(field.get('input').attributes('list')).toBeUndefined();
        expect((field.get('input').element as HTMLInputElement).value).toBe('EGP');
    });

    // ── S-02: one page, four sections, three permissions ───────────────────

    /**
     * §13 names screens 4, 5 and 6 separately; the owner merged them into one
     * page on 2026-08-29 (pending `D-xx`). What that must **not** do is delete
     * a grant: §3.11 gives *system settings* and *system limits* to the Super
     * Admin and **FX rates to the Manager as well**, so the page is composed of
     * sections drawn by their own rows rather than one block behind one.
     */
    function routed(handlers: { match: RegExp; response: () => Response }[]) {
        return vi.fn((input: string) => {
            for (const handler of handlers) {
                if (handler.match.test(input)) {
                    return Promise.resolve(handler.response());
                }
            }

            return Promise.resolve(json(404, { error: { code: 'resource_not_found' } }));
        });
    }

    const ALL_SECTIONS = [
        { match: /\/settings$/, response: () => envelope(SETTINGS) },
        { match: /\/currencies$/, response: () => json(200, { data: { currencies: [] }, meta: {} }) },
        { match: /\/fx-rates/, response: () => json(200, { data: [], meta: { pagination: { page: 1, per_page: 25, total: 0, total_pages: 1, has_next_page: false, has_previous_page: false } } }) },
        { match: /\/system-limits$/, response: () => json(200, { data: { limits: {} }, meta: {} }) },
    ];

    it('draws all four sections for a holder of all three rows', async () => {
        vi.stubGlobal('fetch', routed(ALL_SECTIONS));

        const view = await render('en', SUPER_ADMIN);
        await flushPromises();

        expect(view.find('[data-testid="settings-section"]').exists()).toBe(true);
        expect(view.find('[data-testid="currencies-rounding"]').exists()).toBe(true);
        expect(view.find('[data-testid="currencies-rates"]').exists()).toBe(true);
        expect(view.find('[data-testid="limits-heading"]').exists()).toBe(true);
    });

    /** One `<h1>` for the page, however many sections it carries. */
    it('gives the page exactly one top-level heading', async () => {
        vi.stubGlobal('fetch', routed(ALL_SECTIONS));

        const view = await render('en', SUPER_ADMIN);
        await flushPromises();

        expect(view.findAll('h1')).toHaveLength(1);
        expect(view.get('[data-testid="settings-heading"]').element.tagName).toBe('H1');
    });

    /**
     * ⚠️ **The regression this merge could have shipped.** §3.11 grants the
     * Manager *FX rates*; a single page behind `admin.system_settings` would
     * have taken it away. They reach the page and see their one section.
     */
    it('draws only the rates section for the Manager', async () => {
        vi.stubGlobal('fetch', routed(ALL_SECTIONS));

        const view = await render('en', MANAGER);
        await flushPromises();

        expect(view.find('[data-testid="currencies-rates"]').exists()).toBe(true);
        expect(view.find('[data-testid="settings-section"]').exists()).toBe(false);
        expect(view.find('[data-testid="currencies-rounding"]').exists()).toBe(false);
        expect(view.find('[data-testid="limits-heading"]').exists()).toBe(false);
    });

    /** §5.1 — do not show what the role may not reach, and do not ask for it. */
    it('requests nothing a section the caller lacks would have needed', async () => {
        const fetchMock = routed(ALL_SECTIONS);
        vi.stubGlobal('fetch', fetchMock);

        await render('en', MANAGER);
        await flushPromises();

        const asked = fetchMock.mock.calls.map(([url]) => String(url));

        expect(asked.some((url) => /\/settings$/.test(url))).toBe(false);
        expect(asked.some((url) => /\/system-limits$/.test(url))).toBe(false);
        expect(asked.some((url) => /\/currencies$/.test(url))).toBe(false);
        expect(asked.some((url) => /\/fx-rates/.test(url))).toBe(true);
    });

    // ── §14.2 ──────────────────────────────────────────────────────────────

    it('has no hard-coded English when the locale is Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = await render('ar');
        await flushPromises();

        const heading = view.get('[data-testid="settings-heading"]').text();

        expect(heading).toBe(ar.settings.title);
        expect(heading).not.toBe(en.settings.title);
    });
});
