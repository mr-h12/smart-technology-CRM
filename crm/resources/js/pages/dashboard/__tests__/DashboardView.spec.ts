import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 14, slice 1 · 1.1 — the dashboard opens per role, no figures yet
 * (`D-145`, §12.1).
 *
 * Not an authorization suite: `DashboardMetaEndpointTest` proves which tabs a
 * role receives. Proved here is what the screen draws from `GET /dashboard/meta`:
 * only the tabs it returned, Overview first, the empty Procurement and Outdoor
 * tabs, and a period that opens on the current month and is remembered per device.
 */

// Resolved per test rather than imported, so that before the page exists every
// test fails on its own line instead of the whole file failing to load.
const pages = import.meta.glob<{ default: object }>('../DashboardView.vue');

async function dashboardView(): Promise<object> {
    const load = pages['../DashboardView.vue'];
    if (load === undefined) {
        throw new Error('resources/js/pages/dashboard/DashboardView.vue does not exist yet');
    }

    return (await load()).default;
}

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const USER: AuthenticatedUser = {
    id: 'm1', name: 'Mona Manager', email: 'manager@example.test',
    role: { id: 'r2', slug: 'manager', name: 'Manager' },
    permissions: ['dashboard.view.all', 'dashboard.drill_down.all', 'quotation.view_cost_and_margin.all'],
    is_active: true, unconditional_access: false,
};

const MANAGER_META = {
    scope: 'all', tabs: ['overview', 'sales', 'finance', 'procurement', 'outdoor'],
    drill_down: true, money: true, today: '2026-10-21', last_updated_at: null,
};

/** Answers the meta with `data`; since 2.2b the Overview also asks for its figures, answered with zeros. */
function meta(data: unknown = MANAGER_META): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => json(200, {
        data: /\/dashboard\/overview\?/.test(String(input))
            ? { customers: 0, deals: { total: 0, open: 0, closed: 0 }, self_approvals: 0, now: { pending_approvals: 0, returned: 0 }, last_updated_at: null }
            : data,
        meta: { request_id: 'r' },
    }));
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale = 'en') {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: USER },
            }))
            : (fetchMock as unknown as typeof fetch)(input, init));
    await useAuth().login(USER.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const router = createAppRouter();
    await router.push('/dashboard');
    await router.isReady();

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(await dashboardView(), { global: { plugins: [i18n, router] } });
    await flushPromises();

    return wrapper;
}

const tabNames = (wrapper: Awaited<ReturnType<typeof render>>) =>
    wrapper.findAll('[data-testid^="dashboard-tab-"]').map((tab) => tab.attributes('data-testid')!.replace('dashboard-tab-', ''));

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-21T09:00:00Z'));
    localStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('the dashboard route', () => {
    it('exists and needs dashboard.view', () => {
        const router = createAppRouter();

        expect(router.hasRoute('dashboard')).toBe(true);
        expect(router.resolve({ name: 'dashboard' }).meta.requiredPermission).toBe('dashboard.view');
    });
});

describe('DashboardView', () => {
    it('reads its tabs from GET /dashboard/meta', async () => {
        const fetchMock = meta();
        await render(fetchMock);

        expect(fetchMock.mock.calls.some(([input]) => /\/api\/v1\/dashboard\/meta$/.test(String(input)))).toBe(true);
    });

    it('draws only the tabs the API returned, Overview open first', async () => {
        const wrapper = await render(meta({ ...MANAGER_META, scope: 'team', tabs: ['overview', 'sales', 'outdoor'], money: false }));

        expect(tabNames(wrapper)).toEqual(['overview', 'sales', 'outdoor']);
        expect(wrapper.get('[data-testid="dashboard-tab-overview"]').attributes('aria-selected')).toBe('true');
    });

    it('shows Procurement as an empty state naming its module, never a number', async () => {
        const wrapper = await render(meta());
        await wrapper.get('[data-testid="dashboard-tab-procurement"]').trigger('click');

        const empty = wrapper.get('[data-testid="dashboard-empty-procurement"]');
        expect(empty.text()).toContain('Available once Procurement is live');
        expect(empty.text()).not.toMatch(/\d/);
    });

    it('shows Outdoor as an empty state in Arabic too', async () => {
        const wrapper = await render(meta(), 'ar');
        await wrapper.get('[data-testid="dashboard-tab-outdoor"]').trigger('click');

        expect(wrapper.get('[data-testid="dashboard-empty-outdoor"]').text()).toContain('سيتوفر بعد تفعيل وحدة الزيارات');
    });

    it('opens on the current month in company time', async () => {
        const wrapper = await render(meta());

        expect((wrapper.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('month');
        expect(wrapper.get('[data-testid="dashboard-period-range"]').attributes('data-from')).toBe('2026-10-01');
        expect(wrapper.get('[data-testid="dashboard-period-range"]').attributes('data-to')).toBe('2026-10-31');
    });

    it('remembers the chosen period on this device', async () => {
        const first = await render(meta());
        await first.get('[data-testid="dashboard-period-kind"]').setValue('year');
        first.unmount();

        const second = await render(meta());

        expect((second.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('year');
        expect(second.get('[data-testid="dashboard-period-range"]').attributes('data-from')).toBe('2026-01-01');
    });

    it('still opens on the current month when browser storage throws', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new DOMException('denied', 'SecurityError'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new DOMException('denied', 'SecurityError'); });

        const wrapper = await render(meta());

        expect((wrapper.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('month');
    });

    it('shows an error state when the meta request fails', async () => {
        const wrapper = await render(vi.fn(async () => json(500, { error: { code: 'server_error', message: 'x' } })));

        expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid^="dashboard-tab-"]')).toHaveLength(0);
    });
});

/**
 * Module 14, slice 2 · 2.2a — the Overview tab (`D-144`, `D-145`). Its figures
 * come from `GET /dashboard/overview` for the chosen period; the "now" figures
 * are labelled as such; "Last updated" turns amber past two hours.
 */
const OVERVIEW = {
    customers: 3,
    deals: { total: 5, open: 3, closed: 2 },
    self_approvals: 1,
    now: { pending_approvals: 2, returned: 1 },
    last_updated_at: '2026-10-21T08:00:00+00:00',
};

function api(answers: { meta?: unknown; overview?: unknown; overviewStatus?: number } = {}): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        const url = String(input);
        if (/\/dashboard\/overview\?/.test(url)) {
            return (answers.overviewStatus ?? 200) === 200
                ? json(200, { data: answers.overview ?? OVERVIEW, meta: { request_id: 'r' } })
                : json(answers.overviewStatus!, { error: { code: 'server_error', message: 'x' } });
        }
        return json(200, { data: answers.meta ?? { ...MANAGER_META, last_updated_at: OVERVIEW.last_updated_at }, meta: { request_id: 'r' } });
    });
}

const overviewCalls = (fetchMock: ReturnType<typeof vi.fn>): string[] =>
    fetchMock.mock.calls.map(([input]) => String(input)).filter((url) => /\/dashboard\/overview\?/.test(url));

describe('the Overview tab', () => {
    it('asks for the current month in company time', async () => {
        const fetchMock = api();
        await render(fetchMock);

        expect(overviewCalls(fetchMock)).toHaveLength(1);
        expect(overviewCalls(fetchMock)[0]).toMatch(/\/api\/v1\/dashboard\/overview\?from=2026-10-01&to=2026-10-31$/);
    });

    it('draws a tile for each figure', async () => {
        const wrapper = await render(api());
        const tile = (name: string) => wrapper.get(`[data-testid="dashboard-tile-${name}"]`).text();

        expect(tile('customers')).toContain('3');
        expect(tile('deals-total')).toContain('5');
        expect(tile('deals-open')).toContain('3');
        expect(tile('deals-closed')).toContain('2');
        expect(tile('self-approvals')).toContain('1');
        expect(tile('pending-approvals')).toContain('2');
        expect(tile('returned')).toContain('1');
    });

    it('labels the live figures "now"', async () => {
        const wrapper = await render(api());

        for (const name of ['pending-approvals', 'returned']) {
            expect(wrapper.get(`[data-testid="dashboard-tile-${name}"]`).text()).toContain('now');
        }
        expect(wrapper.get('[data-testid="dashboard-tile-customers"]').text()).not.toContain('now');
    });

    it('asks again when the period changes', async () => {
        const fetchMock = api();
        const wrapper = await render(fetchMock);

        await wrapper.get('[data-testid="dashboard-period-kind"]').setValue('year');
        await flushPromises();

        expect(overviewCalls(fetchMock).at(-1)).toMatch(/from=2026-01-01&to=2026-12-31$/);
    });

    it('marks "Last updated" stale past two hours', async () => {
        const fresh = await render(api());
        expect(fresh.get('[data-testid="dashboard-last-updated"]').attributes('data-stale')).toBe('false');
        fresh.unmount();

        // The Overview's answer carries the newest run too, so both say 06:30 (09:00 now).
        const stale = await render(api({
            meta: { ...MANAGER_META, last_updated_at: '2026-10-21T06:30:00+00:00' },
            overview: { ...OVERVIEW, last_updated_at: '2026-10-21T06:30:00+00:00' },
        }));
        expect(stale.get('[data-testid="dashboard-last-updated"]').attributes('data-stale')).toBe('true');
    });

    it('still says "Not refreshed yet" before J-09 has run', async () => {
        const wrapper = await render(api({ meta: MANAGER_META, overview: { ...OVERVIEW, last_updated_at: null } }));

        expect(wrapper.get('[data-testid="dashboard-last-updated"]').text()).toContain('Not refreshed yet');
    });

    it('keeps the tabs and the period when the figures fail', async () => {
        const wrapper = await render(api({ overviewStatus: 500 }));

        expect(wrapper.find('[data-testid="dashboard-overview-error"]').exists()).toBe(true);
        expect(tabNames(wrapper)).toEqual(['overview', 'sales', 'finance', 'procurement', 'outdoor']);
        expect(wrapper.find('[data-testid="dashboard-period-kind"]').exists()).toBe(true);
    });
});

/**
 * Module 14, slice 3 · 3.1 — money (`D-141`, `D-144`, `D-145`, `D-148`, `D-149`).
 * Asked for only when the meta says `money`; the Overview shows each currency's
 * revenue and profits, the Finance tab the rest, the combine toggle the base totals.
 */
const MONEY = {
    currencies: [
        { currency: 'EGP', won_value: '1600.000000', cost: '900.000000', gross_profit: '600.000000', net_profit: '600.000000', margin_percent: '37.50', delivery_cost: '100.000000', won_chains: 2 },
        { currency: 'USD', won_value: '20.000000', cost: '8.000000', gross_profit: '10.000000', net_profit: '10.000000', margin_percent: '50.00', delivery_cost: '2.000000', won_chains: 1 },
    ],
    combined: { currency: 'EGP', won_value: '2100.000000', gross_profit: '900.000000', net_profit: '900.000000', delivery_cost: '200.000000', unconverted: 1 },
    monthly: [
        { month: '2026-09', currency: 'EGP', won_value: '1000.000000', gross_profit: '400.000000' },
        { month: '2026-10', currency: 'EGP', won_value: '500.000000', gross_profit: '400.000000' },
        { month: '2026-10', currency: 'USD', won_value: '20.000000', gross_profit: '10.000000' },
    ],
};

function moneyApi(metaData: unknown = { ...MANAGER_META, last_updated_at: OVERVIEW.last_updated_at }): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        const url = String(input);
        const data = /\/dashboard\/money\?/.test(url) ? MONEY : /\/dashboard\/overview\?/.test(url) ? OVERVIEW : metaData;
        return json(200, { data, meta: { request_id: 'r' } });
    });
}

const moneyCalls = (fetchMock: ReturnType<typeof vi.fn>): string[] =>
    fetchMock.mock.calls.map(([input]) => String(input)).filter((url) => /\/dashboard\/money\?/.test(url));

describe('money', () => {
    it('shows each currency\'s revenue and profits on the Overview', async () => {
        const fetchMock = moneyApi();
        const wrapper = await render(fetchMock);

        expect(moneyCalls(fetchMock)[0]).toMatch(/\/api\/v1\/dashboard\/money\?from=2026-10-01&to=2026-10-31$/);
        const egp = wrapper.get('[data-testid="dashboard-money-EGP"]').text();
        expect(egp).toContain('1,600.00');
        expect(egp).toContain('600.00');
        expect(wrapper.get('[data-testid="dashboard-money-USD"]').text()).toContain('20.00');
        expect(wrapper.get('[data-testid="dashboard-net-profit-note"]').text()).toContain('equals gross profit until Procurement');
    });

    it('never asks for money without the meta\'s money', async () => {
        const fetchMock = moneyApi({ ...MANAGER_META, scope: 'team', tabs: ['overview', 'sales', 'outdoor'], money: false });
        const wrapper = await render(fetchMock);

        expect(moneyCalls(fetchMock)).toHaveLength(0);
        expect(wrapper.find('[data-testid^="dashboard-money-"]').exists()).toBe(false);
    });

    it('lists margin and delivery cost per currency on the Finance tab, with a bar per month', async () => {
        const wrapper = await render(moneyApi());
        await wrapper.get('[data-testid="dashboard-tab-finance"]').trigger('click');
        await flushPromises();

        const egp = wrapper.get('[data-testid="dashboard-finance-EGP"]').text();
        expect(egp).toContain('37.50');
        expect(egp).toContain('100.00');
        expect(wrapper.findAll('[data-testid="dashboard-month-bar"]')).toHaveLength(3);
    });

    it('combines into the base currency on request, naming what could not be converted', async () => {
        const wrapper = await render(moneyApi());
        await wrapper.get('[data-testid="dashboard-tab-finance"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="dashboard-combined"]').exists()).toBe(false);

        await wrapper.get('[data-testid="dashboard-combine"]').setValue(true);

        const combined = wrapper.get('[data-testid="dashboard-combined"]').text();
        expect(combined).toContain('2,100.00');
        expect(combined).toContain('1');
    });
});

/**
 * Module 14, slice 4 · 4.1 — the Sales tab I (`D-144`, `D-150`): the funnel,
 * conversion and win/loss rates, the sales cycle, average deal size (money only)
 * and workload (drill-down holders only).
 */
const SALES = {
    funnel: [
        { status: 'lead', deals: 1 }, { status: 'contacted', deals: 3 }, { status: 'waiting_customer_request', deals: 0 },
        { status: 'supplier_rfq', deals: 0 }, { status: 'supplier_quotation', deals: 0 }, { status: 'quotation_sent', deals: 0 },
        { status: 'negotiations', deals: 0 }, { status: 'won', deals: 1 }, { status: 'purchasing', deals: 1 },
        { status: 'delivery', deals: 0 }, { status: 'delivery_complete', deals: 0 }, { status: 'lost', deals: 1 },
    ],
    conversion: { created: 7, reached_won: 2, rate_percent: '28.57' },
    win_loss: { won: 3, lost: 1, win_rate_percent: '75.00' },
    average_sales_cycle_days: '10.00',
    average_deal_size: [{ currency: 'EGP', value: '800.000000' }],
    workload: [{ owner_id: 'u1', name: 'Mona Member', open_deals: 4 }],
};

function salesApi(sales: unknown = SALES): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        const url = String(input);
        const data = /\/dashboard\/sales\?/.test(url) ? sales : /\/dashboard\/money\?/.test(url) ? MONEY : /\/dashboard\/overview\?/.test(url) ? OVERVIEW : MANAGER_META;
        return json(200, { data, meta: { request_id: 'r' } });
    });
}

async function openSales(fetchMock: ReturnType<typeof vi.fn>) {
    const wrapper = await render(fetchMock);
    await wrapper.get('[data-testid="dashboard-tab-sales"]').trigger('click');
    await flushPromises();
    return wrapper;
}

describe('the Sales tab', () => {
    it('asks for the period and draws every funnel stage in order', async () => {
        const fetchMock = salesApi();
        const wrapper = await openSales(fetchMock);

        expect(fetchMock.mock.calls.map(([u]) => String(u)).some((u) => /\/dashboard\/sales\?from=2026-10-01&to=2026-10-31$/.test(u))).toBe(true);
        const stages = wrapper.findAll('[data-testid="dashboard-funnel-stage"]');
        expect(stages).toHaveLength(12);
        expect(stages[0]!.attributes('data-status')).toBe('lead');
        expect(stages[11]!.attributes('data-status')).toBe('lost');
    });

    it('shows the rates, the cycle and the deal size', async () => {
        const wrapper = await openSales(salesApi());

        expect(wrapper.get('[data-testid="dashboard-conversion"]').text()).toContain('28.57');
        expect(wrapper.get('[data-testid="dashboard-win-rate"]').text()).toContain('75.00');
        expect(wrapper.get('[data-testid="dashboard-sales-cycle"]').text()).toContain('10.00');
        expect(wrapper.get('[data-testid="dashboard-deal-size-EGP"]').text()).toContain('800.00');
        expect(wrapper.get('[data-testid="dashboard-workload"]').text()).toContain('Mona Member');
    });

    it('leaves out what the answer leaves out, and says "—" for a rate with nothing to divide', async () => {
        const { average_deal_size: _size, workload: _load, ...counts } = SALES;
        const wrapper = await openSales(salesApi({ ...counts, conversion: { created: 0, reached_won: 0, rate_percent: null } }));

        expect(wrapper.find('[data-testid^="dashboard-deal-size-"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="dashboard-workload"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="dashboard-conversion"]').text()).toContain('—');
    });
});

/**
 * Module 14, slice 5 · 5.1 — the Sales tab II (`D-144`, `D-145`, `D-147`, `D-150`):
 * lost by reason, by employee, by sector, top customers, best and worst products —
 * each drawn only when the answer carries it.
 */
const BREAKDOWNS = {
    base_currency: 'EGP',
    lost_by_reason: [{ code: 'price_too_high', deals: 3 }, { code: 'other', deals: 1 }],
    by_employee: [{ owner_id: 'u1', name: 'Mona Member', won_deals: 2, money: [{ currency: 'EGP', won_value: '1500.000000' }] }],
    by_sector: [{ sector: 'medical', won_chains: 2, money: [{ currency: 'EGP', won_value: '1500.000000' }] }],
    top_customers: [{ customer_id: 'c1', name: 'Delta Medical', base_value: '1000.000000', money: [{ currency: 'EGP', won_value: '1000.000000' }] }],
    products: {
        best: [{ catalog_item_id: 'p1', name: 'Widget', base_value: '1400.000000', money: [{ currency: 'EGP', won_value: '1400.000000' }] }],
        worst: [{ catalog_item_id: 'p2', name: 'Gadget', base_value: '1100.000000', money: [{ currency: 'EGP', won_value: '100.000000' }] }],
    },
};

function breakdownsApi(breakdowns: unknown = BREAKDOWNS): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        const url = String(input);
        const data = /\/dashboard\/breakdowns\?/.test(url) ? breakdowns
            : /\/dashboard\/sales\?/.test(url) ? SALES
                : /\/dashboard\/money\?/.test(url) ? MONEY
                    : /\/dashboard\/overview\?/.test(url) ? OVERVIEW
                        : /\/managed-lists\/lost_reasons/.test(url) ? [{ code: 'price_too_high', label_en: 'Price too high', label_ar: 'السعر أعلى من المتوقع', position: 1 }, { code: 'other', label_en: 'Other', label_ar: 'أخرى', position: 7 }]
                            : /\/managed-lists\/sectors/.test(url) ? [{ code: 'medical', label_en: 'Medical', label_ar: 'طبي', position: 1 }]
                                : MANAGER_META;
        return json(200, { data, meta: { request_id: 'r', pagination: { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false } } });
    });
}

describe('the Sales tab II', () => {
    it('draws each breakdown with its labels', async () => {
        const wrapper = await openSales(breakdownsApi());

        expect(wrapper.get('[data-testid="dashboard-lost-by-reason"]').text()).toContain('Price too high');
        expect(wrapper.get('[data-testid="dashboard-lost-by-reason"]').text()).toContain('3');
        expect(wrapper.get('[data-testid="dashboard-by-employee"]').text()).toContain('Mona Member');
        expect(wrapper.get('[data-testid="dashboard-by-employee"]').text()).toContain('1,500.00');
        expect(wrapper.get('[data-testid="dashboard-by-sector"]').text()).toContain('Medical');
        expect(wrapper.get('[data-testid="dashboard-top-customers"]').text()).toContain('Delta Medical');
        expect(wrapper.get('[data-testid="dashboard-products-best"]').text()).toContain('Widget');
        expect(wrapper.get('[data-testid="dashboard-products-worst"]').text()).toContain('Gadget');
    });

    it('draws only what the answer carries', async () => {
        const wrapper = await openSales(breakdownsApi({ base_currency: 'EGP', lost_by_reason: [], by_employee: [{ owner_id: 'u1', name: 'Mona Member', won_deals: 2 }] }));

        expect(wrapper.find('[data-testid="dashboard-by-employee"]').text()).not.toContain('.00');
        for (const part of ['by-sector', 'top-customers', 'products-best', 'products-worst']) {
            expect(wrapper.find(`[data-testid="dashboard-${part}"]`).exists()).toBe(false);
        }
    });
});

/**
 * Module 14, slice 6 · 6.1 — drill-down (`D-145`): for a holder of `drill_down`,
 * a figure opens the list it counts, with that list's filters; for anyone else
 * (the CEO) it is a number, never a link.
 */
describe('drill-down', () => {
    const href = (wrapper: Awaited<ReturnType<typeof render>>, testid: string): string | undefined =>
        wrapper.find(`[data-testid="${testid}"] a`).attributes('href');

    it('turns the Overview figures into links to their lists', async () => {
        const wrapper = await render(api());

        expect(href(wrapper, 'dashboard-tile-deals-total')).toBe('/deals?created_from=2026-10-01&created_to=2026-10-31');
        expect(href(wrapper, 'dashboard-tile-deals-open')).toBe('/deals?created_from=2026-10-01&created_to=2026-10-31&progress=open');
        expect(href(wrapper, 'dashboard-tile-deals-closed')).toBe('/deals?created_from=2026-10-01&created_to=2026-10-31&progress=closed');
        expect(href(wrapper, 'dashboard-tile-customers')).toBe('/customers?created_from=2026-10-01&created_to=2026-10-31');
        expect(href(wrapper, 'dashboard-tile-pending-approvals')).toBe('/approvals');
        expect(href(wrapper, 'dashboard-tile-returned')).toBe('/quotations');
    });

    it('links each funnel stage and each workload row', async () => {
        const wrapper = await openSales(salesApi());

        expect(wrapper.findAll('[data-testid="dashboard-funnel-stage"] a')[0]!.attributes('href')).toBe('/deals?status=lead&created_from=2026-10-01&created_to=2026-10-31');
        expect(wrapper.find('[data-testid="dashboard-workload"] a').attributes('href')).toBe('/deals?owner=u1&progress=open');
    });

    it('draws no link without drill-down', async () => {
        const wrapper = await render(api({ meta: { ...MANAGER_META, drill_down: false, tabs: ['overview', 'sales', 'finance', 'outdoor'], last_updated_at: OVERVIEW.last_updated_at } }));

        expect(wrapper.find('[data-testid^="dashboard-tile-"] a').exists()).toBe(false);
    });
});
