import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Module 2, Point 5.2 — §13 screen 5, *Currencies & FX*.
 *
 * ── One screen, two audiences ──────────────────────────────────────────────
 *
 * §3.11 gives `system settings` to the Super Admin and `—` to the Manager,
 * while **FX rates** on the next row is `✅ Super Admin · ✅ Manager`. §13 names
 * one screen anyway, so the halves are drawn by permission: the rounding unit
 * (`admin.system_settings`, `PATCH /currencies/{code}`) and the rate history
 * plus its form (`admin.fx_rates`). The Manager's view — rates and no rounding
 * — is a real state and is tested as one.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09`: `CurrencyEndpointTest` and `FxRateEndpointTest`
 * prove the refusals against the server. What is proved here is what the screen
 * draws and sends — the API refuses regardless of what this component decides.
 *
 * ── `DB-07` reaches the screen ─────────────────────────────────────────────
 *
 * A rounding unit and an FX rate are decimal **strings** end to end. Every
 * control here is `type="text"`; a `type="number"` binds to a JavaScript float,
 * and `0.01` and `48.5` are exactly the values that may never become one.
 */

const SUPER_ADMIN: AuthenticatedUser = {
    id: '01a0-sa',
    name: 'Test Super Admin',
    email: 'super.admin@example.test',
    is_active: true,
    role: { id: '01a0-role-sa', slug: 'super_admin', name: 'Super Admin' },
    permissions: [],
    unconditional_access: true,
};

/** §3.11: **FX rates** and not `system settings`. The difference is the point. */
const MANAGER: AuthenticatedUser = {
    id: '01a0-mgr',
    name: 'Test Manager',
    email: 'manager@example.test',
    is_active: true,
    role: { id: '01a0-role-mgr', slug: 'manager', name: 'Manager' },
    permissions: ['admin.fx_rates.all', 'admin.create_user.all'],
    unconditional_access: false,
};

const CURRENCIES = [
    { code: 'EGP', rounding_unit: '1', rounding_enabled: true, is_base: true },
    { code: 'USD', rounding_unit: '0.01', rounding_enabled: true, is_base: false },
    { code: 'EUR', rounding_unit: '0.01', rounding_enabled: false, is_base: false },
];

const RATES = [
    { id: 'fx-2', from_currency: 'USD', to_currency: 'EGP', rate: '48.500000', effective_from: '2026-08-28T09:00:00+00:00', created_at: '2026-08-28T09:00:00+00:00' },
    { id: 'fx-1', from_currency: 'EUR', to_currency: 'EGP', rate: '53.100000', effective_from: '2026-08-20T09:00:00+00:00', created_at: '2026-08-20T09:00:00+00:00' },
];

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** `OpenAPI §4.1` — the single-resource envelope. */
function single(data: unknown, status = 200): Response {
    return json(status, { data, meta: { request_id: 'req_test' } });
}

/** `OpenAPI §4.2` — the collection envelope, pagination block and all. */
function page(items: unknown[], overrides: Record<string, unknown> = {}): Response {
    return json(200, {
        data: items,
        meta: {
            pagination: {
                page: 1,
                per_page: 25,
                total: items.length,
                total_pages: 1,
                has_next_page: false,
                has_previous_page: false,
                ...overrides,
            },
            request_id: 'req_test',
        },
    });
}

interface Handler {
    match: RegExp;
    method?: string;
    response: () => Response;
    /** Answer with a promise that never settles, so the loading state stays put. */
    never?: boolean;
}

/** Routed by URL and verb, so a test states what each endpoint answers. */
function routedFetch(handlers: Handler[]) {
    return vi.fn((input: string, init?: { method?: string }) => {
        const method = init?.method ?? 'GET';

        for (const handler of handlers) {
            if (handler.match.test(input) && (handler.method ?? 'GET') === method) {
                return handler.never === true
                    ? new Promise<Response>(() => {})
                    : Promise.resolve(handler.response());
            }
        }

        return Promise.resolve(json(404, { error: { code: 'resource_not_found', message: 'no stub' } }));
    });
}

async function mountCurrencies(
    profile: AuthenticatedUser,
    handlers: Handler[],
    locale: 'ar' | 'en' = 'en',
) {
    vi.resetModules();
    window.localStorage.clear();

    const fetchMock = routedFetch([
        { match: /\/auth\/login$/, method: 'POST', response: () => json(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
        }) },
        ...handlers,
    ]);
    vi.stubGlobal('fetch', fetchMock);

    const { useAuth } = await import('@/stores/auth');
    const auth = useAuth();
    await auth.login(profile.email, 'Passw0rd123');

    const CurrenciesView = (await import('@/pages/currencies/CurrenciesView.vue')).default;

    const wrapper = mount(CurrenciesView, {
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })] },
    });

    await flushPromises();

    return { wrapper, fetchMock };
}

const CURRENCIES_OK: Handler = { match: /\/currencies$/, response: () => single({ currencies: CURRENCIES }) };
const RATES_OK: Handler = { match: /\/fx-rates(\?|$)/, response: () => page(RATES) };

beforeEach(() => {
    window.localStorage.clear();
});

// ── the two halves ─────────────────────────────────────────────────────────

describe('the halves, drawn by permission', () => {
    it('draws both halves for a holder of both rows', async () => {
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK]);

        expect(wrapper.find('[data-testid="currencies-rounding"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="currencies-rates"]').exists()).toBe(true);
    });

    /** §3.11: the Manager holds **FX rates** and not `system settings`. */
    it('draws the rates half and not the rounding half for the Manager', async () => {
        const { wrapper } = await mountCurrencies(MANAGER, [CURRENCIES_OK, RATES_OK]);

        expect(wrapper.find('[data-testid="currencies-rates"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="currencies-rounding"]').exists()).toBe(false);
    });

    /**
     * §5.1 — do not show what the role may not reach, and do not ask for it
     * either. `GET /currencies` carries `admin.system_settings`, so requesting
     * it as the Manager is a guaranteed 403 whose only effect is an error state
     * on a half that should not be drawn at all.
     */
    it('does not request the currencies at all for the Manager', async () => {
        const { fetchMock } = await mountCurrencies(MANAGER, [CURRENCIES_OK, RATES_OK]);

        expect(fetchMock.mock.calls.some(([url]) => /\/currencies/.test(url))).toBe(false);
    });
});

// ── the rounding half ──────────────────────────────────────────────────────

describe('the rounding half', () => {
    it('shows the loading state while the currencies are still in flight', async () => {
        const pending: Handler = { match: /\/currencies$/, response: () => single({ currencies: [] }), never: true };
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [pending, RATES_OK]);

        expect(wrapper.get('[data-testid="currencies-rounding"]').find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('renders a row per currency, with its unit and its switch', async () => {
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK]);

        const rows = wrapper.findAll('[data-currency-code]');

        expect(rows.map((row) => row.attributes('data-currency-code'))).toEqual(['EGP', 'USD', 'EUR']);

        const unit = wrapper.get('[data-currency-code="USD"] [data-testid="currencies-unit"]');

        expect((unit.element as HTMLInputElement).value).toBe('0.01');
    });

    /** `DB-07`: a rounding unit is a decimal string, never a JavaScript float. */
    it('binds the unit to a text control, not to a number control', async () => {
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK]);

        const unit = wrapper.get('[data-currency-code="USD"] [data-testid="currencies-unit"]');

        expect(unit.attributes('type')).toBe('text');
        expect(unit.attributes('inputmode')).toBe('decimal');
    });

    /**
     * Owner decision of 2026-08-28: the base currency is read-only. §13 names
     * it as a field on the screen and `CurrencyController` offers no way to
     * move it, so it is drawn as a fact and not as a control.
     */
    it('marks the base currency without offering a control to change it', async () => {
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK]);

        const marks = wrapper.findAll('[data-testid="currencies-base"]');

        expect(marks).toHaveLength(1);
        expect(wrapper.get('[data-currency-code="EGP"]').find('[data-testid="currencies-base"]').exists()).toBe(true);

        // The base row offers exactly what every other row offers — the unit and
        // the switch — and nothing that would reassign the base.
        const base = wrapper.get('[data-currency-code="EGP"]').findAll('input');
        const other = wrapper.get('[data-currency-code="USD"]').findAll('input');

        expect(base).toHaveLength(other.length);
    });

    it('sends only the fields that were edited on that row', async () => {
        const patched: Handler = {
            match: /\/currencies\/USD$/,
            method: 'PATCH',
            response: () => single({ currency: { ...CURRENCIES[1], rounding_unit: '0.05' } }),
        };
        const { wrapper, fetchMock } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK, patched]);

        await wrapper.get('[data-currency-code="USD"] [data-testid="currencies-unit"]').setValue('0.05');
        await wrapper.get('[data-currency-code="USD"] [data-testid="currencies-save"]').trigger('click');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(call?.[0]).toContain('/currencies/USD');
        expect(JSON.parse((call?.[1] as { body: string }).body)).toEqual({ rounding_unit: '0.05' });
    });

    it('does not submit a row that was not edited', async () => {
        const { wrapper, fetchMock } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK]);

        await wrapper.get('[data-currency-code="USD"] [data-testid="currencies-save"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);
    });

    it('sends the switch when only the switch was changed', async () => {
        const patched: Handler = {
            match: /\/currencies\/EUR$/,
            method: 'PATCH',
            response: () => single({ currency: { ...CURRENCIES[2], rounding_enabled: true } }),
        };
        const { wrapper, fetchMock } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK, patched]);

        await wrapper.get('[data-currency-code="EUR"] [data-testid="currencies-enabled"]').setValue(true);
        await wrapper.get('[data-currency-code="EUR"] [data-testid="currencies-save"]').trigger('click');
        await flushPromises();

        const call = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(JSON.parse((call?.[1] as { body: string }).body)).toEqual({ rounding_enabled: true });
    });

    it('reports a refusal against the field the server named', async () => {
        const refused: Handler = {
            match: /\/currencies\/USD$/,
            method: 'PATCH',
            response: () => json(422, {
                error: {
                    code: 'validation_failed',
                    message: 'The given data was invalid.',
                    details: [{ field: 'rounding_unit', code: 'invalid', message: 'Not a positive number.' }],
                },
            }),
        };
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK, refused]);

        await wrapper.get('[data-currency-code="USD"] [data-testid="currencies-unit"]').setValue('0');
        await wrapper.get('[data-currency-code="USD"] [data-testid="currencies-save"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-currency-code="USD"]').text()).toContain('Not a positive number.');
        expect(wrapper.find('[data-testid="currencies-saved"]').exists()).toBe(false);
    });

    it('shows the error state and offers a retry when the currencies fail to load', async () => {
        const failed: Handler = { match: /\/currencies$/, response: () => json(500, { error: { code: 'server_error' } }) };
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [failed, RATES_OK]);

        const half = wrapper.get('[data-testid="currencies-rounding"]');

        expect(half.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(half.find('[data-testid="error-retry"]').exists()).toBe(true);
    });
});

// ── the rates half ─────────────────────────────────────────────────────────

describe('the rate history', () => {
    it('renders a row per recorded rate, in the order the server sent them', async () => {
        const { wrapper } = await mountCurrencies(MANAGER, [RATES_OK]);

        const rows = wrapper.findAll('[data-testid="rates-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('USD');
        expect(rows[0]?.text()).toContain('48.500000');
        expect(rows[1]?.text()).toContain('EUR');
    });

    it('shows the empty state when nothing has been recorded', async () => {
        const empty: Handler = { match: /\/fx-rates(\?|$)/, response: () => page([]) };
        const { wrapper } = await mountCurrencies(MANAGER, [empty]);

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
    });

    it('shows the permission-denied state on a 403', async () => {
        const denied: Handler = { match: /\/fx-rates(\?|$)/, response: () => json(403, { error: { code: 'permission_denied' } }) };
        const { wrapper } = await mountCurrencies(MANAGER, [denied]);

        expect(wrapper.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    it('hides the pager on a single page and asks for the next page when there is one', async () => {
        const oneOfTwo: Handler = {
            match: /\/fx-rates(\?|$)/,
            response: () => page(RATES, { total: 30, total_pages: 2, has_next_page: true }),
        };
        const { wrapper, fetchMock } = await mountCurrencies(MANAGER, [oneOfTwo]);

        expect(wrapper.find('[data-testid="rates-pagination"]').exists()).toBe(true);

        await wrapper.get('[data-testid="rates-next"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => /\/fx-rates\?.*page=2/.test(url))).toBe(true);
    });

    it('draws no pager when the history fits one page', async () => {
        const { wrapper } = await mountCurrencies(MANAGER, [RATES_OK]);

        expect(wrapper.find('[data-testid="rates-pagination"]').exists()).toBe(false);
    });
});

describe('recording a rate', () => {
    const recorded: Handler = {
        match: /\/fx-rates$/,
        method: 'POST',
        response: () => single({ fx_rate: { ...RATES[0], id: 'fx-3', rate: '49.000000' } }, 201),
    };

    /** `DB-07`: the multiplier that reaches every converted line leaves as a string. */
    it('sends the rate as a string and the codes upper-cased', async () => {
        const { wrapper, fetchMock } = await mountCurrencies(MANAGER, [RATES_OK, recorded]);

        await wrapper.get('[data-testid="rates-from"]').setValue('usd');
        await wrapper.get('[data-testid="rates-to"]').setValue('egp');
        await wrapper.get('[data-testid="rates-rate"]').setValue('49.000000');
        await wrapper.get('[data-testid="rates-form"]').trigger('submit');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([url, init]) => init?.method === 'POST' && /\/fx-rates$/.test(url));

        expect(post).toBeDefined();

        const body = JSON.parse((post?.[1] as { body: string }).body);

        expect(body).toEqual({ from_currency: 'USD', to_currency: 'EGP', rate: '49.000000' });
        expect(typeof body.rate).toBe('string');
    });

    it('binds the rate to a text control, not to a number control', async () => {
        const { wrapper } = await mountCurrencies(MANAGER, [RATES_OK]);

        const rate = wrapper.get('[data-testid="rates-rate"]');

        expect(rate.attributes('type')).toBe('text');
        expect(rate.attributes('inputmode')).toBe('decimal');
    });

    /** `AP-06` — a rate is append-only, so the history has to be re-read. */
    it('re-reads the history after a rate is recorded', async () => {
        const { wrapper, fetchMock } = await mountCurrencies(MANAGER, [RATES_OK, recorded]);

        const before = fetchMock.mock.calls.filter(([url, init]) => (init?.method ?? 'GET') === 'GET' && /\/fx-rates/.test(url)).length;

        await wrapper.get('[data-testid="rates-from"]').setValue('USD');
        await wrapper.get('[data-testid="rates-to"]').setValue('EGP');
        await wrapper.get('[data-testid="rates-rate"]').setValue('49');
        await wrapper.get('[data-testid="rates-form"]').trigger('submit');
        await flushPromises();

        const after = fetchMock.mock.calls.filter(([url, init]) => (init?.method ?? 'GET') === 'GET' && /\/fx-rates/.test(url)).length;

        expect(after).toBe(before + 1);
        expect(wrapper.find('[data-testid="rates-recorded"]').exists()).toBe(true);
    });

    it('reports a refusal against the field the server named', async () => {
        const refused: Handler = {
            match: /\/fx-rates$/,
            method: 'POST',
            response: () => json(422, {
                error: {
                    code: 'validation_failed',
                    message: 'The given data was invalid.',
                    details: [{ field: 'rate', code: 'invalid', message: 'Not a rate.' }],
                },
            }),
        };
        const { wrapper } = await mountCurrencies(MANAGER, [RATES_OK, refused]);

        await wrapper.get('[data-testid="rates-from"]').setValue('USD');
        await wrapper.get('[data-testid="rates-to"]').setValue('EGP');
        await wrapper.get('[data-testid="rates-rate"]').setValue('lots');
        await wrapper.get('[data-testid="rates-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="rates-form"]').text()).toContain('Not a rate.');
        expect(wrapper.find('[data-testid="rates-recorded"]').exists()).toBe(false);
    });
});

// ── §14.2 ──────────────────────────────────────────────────────────────────

describe('the screen in Arabic', () => {
    it('has no hard-coded English heading', async () => {
        const { wrapper } = await mountCurrencies(SUPER_ADMIN, [CURRENCIES_OK, RATES_OK], 'ar');

        const heading = wrapper.get('[data-testid="currencies-heading"]').text();

        expect(heading).toBe(ar.currencies.title);
        expect(heading).not.toBe(en.currencies.title);
    });
});
