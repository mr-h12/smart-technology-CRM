import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import SystemSettingsView from '@/pages/settings/SystemSettingsView.vue';

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

function render(locale = 'en') {
    return mount(SystemSettingsView, { global: { plugins: [i18n(locale)] } });
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('SystemSettingsView', () => {
    it('shows the loading state before the settings arrive', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        const view = render();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('renders the eight fields the API exposes, and no more', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = render();
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

        const view = render();
        await flushPromises();

        const html = view.html();

        expect(html).not.toContain('company.logo');
        expect(html).not.toContain('templates.pdf');
        expect(html).not.toContain('templates.email');
    });

    it('fills each control with the stored value', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = render();
        await flushPromises();

        const name = view.get('[data-setting-key="company.name"] input');

        expect((name.element as HTMLInputElement).value).toBe('Smart Technology');
    });

    /** Null is "never set" and must render as empty, not as the word "null". */
    it('renders an unset field as empty rather than as a literal', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = render();
        await flushPromises();

        const address = view.get('[data-setting-key="company.address"] input');

        expect((address.element as HTMLInputElement).value).toBe('');
    });

    it('shows the error state and offers a retry when the reading fails', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(500, { error: { code: 'server_error' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-retry"]').exists()).toBe(true);
    });

    /** §3.12 rule 1 — the server refuses, and the screen says so in its own words. */
    it('shows the permission-denied state on a 403', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'permission_denied' } })));

        const view = render();
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

        const view = render();
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

        const view = render();
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

        const view = render();
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

        const view = render();
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

        const view = render();
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

        const view = render();
        await flushPromises();

        await view.get('[data-setting-key="company.name"] input').setValue('Unsaved Work');
        await view.get('[data-testid="settings-save"]').trigger('submit');
        await flushPromises();

        expect((view.get('[data-setting-key="company.name"] input').element as HTMLInputElement).value)
            .toBe('Unsaved Work');
    });

    // ── §14.2 ──────────────────────────────────────────────────────────────

    it('has no hard-coded English when the locale is Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(SETTINGS)));

        const view = render('ar');
        await flushPromises();

        const heading = view.get('[data-testid="settings-heading"]').text();

        expect(heading).toBe(ar.settings.title);
        expect(heading).not.toBe(en.settings.title);
    });
});
