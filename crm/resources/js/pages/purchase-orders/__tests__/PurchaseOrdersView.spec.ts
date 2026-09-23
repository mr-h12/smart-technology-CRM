import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import PurchaseOrdersView from '@/pages/purchase-orders/PurchaseOrdersView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 10 · 3.3 — the purchase orders, `GET /purchase-orders` (§4.6).
 *
 * "Search works on both numbers": the screen sends the one `q` and the server
 * searches `po_number` and `customer_po_reference` alike (2.2's
 * `SearchIndex::PurchaseOrders`) — so the proof here is that the box reaches
 * `q` untouched, and that the rows are the server's.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const ORDER = {
    id: 'po1',
    po_number: 'PO-2026-0001',
    customer_po_reference: '4500123987',
    po_date: '2026-09-20',
    quotation_id: 'q1',
    quotation_code: 'QT-2026-0007',
    customer_id: 'c1',
    customer_name: 'Acme Industrial',
    final_total: '1150.123456',
    currency: 'EGP',
};

const PAGINATION = { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Sales',
    email: 'sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['quotation.view.own'],
    is_active: true,
    unconditional_access: false,
};

function respond(options: { orders?: unknown[]; status?: number; pagination?: Record<string, unknown> } = {}): ReturnType<typeof vi.fn> {
    const { orders = [ORDER], status = 200, pagination = PAGINATION } = options;

    return vi.fn(async () =>
        status === 200
            ? json(200, { data: orders, meta: { request_id: 'r1', pagination } })
            : json(status, { error: { code: status === 403 ? 'forbidden' : 'server_error', message: 'no' }, meta: { request_id: 'r1' } }),
    );
}

async function render(fetchMock: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, { data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: USER } }))
            : (fetchMock as unknown as typeof globalThis.fetch)(input, init));
    await useAuth().login(USER.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();
    await router.push('/purchase-orders');
    await router.isReady();

    const wrapper = mount(PurchaseOrdersView, { global: { plugins: [i18n, router] } });
    await flushPromises();

    return wrapper;
}

/** The query of every list request, in order. */
function queries(fetchMock: ReturnType<typeof vi.fn>): URLSearchParams[] {
    return fetchMock.mock.calls
        .map((call) => String(call[0]))
        .filter((url) => url.includes('/purchase-orders'))
        .map((url) => new URL(url, 'http://localhost').searchParams);
}

describe('the purchase orders list (Module 10 · 3.3)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('draws each order with its numbers, date, quotation link and total', async () => {
        const wrapper = await render(respond());

        const row = wrapper.find('[data-testid="purchase-orders-row"]');
        expect(row.text()).toContain('PO-2026-0001');
        expect(row.text()).toContain('4500123987');
        expect(row.text()).toContain('Acme Industrial');
        // `D-82`: the server's string, cut after the third decimal.
        expect(row.find('[data-testid="purchase-orders-total"]').text()).toBe('1150.123 EGP');
        // `DB-08`: a date-only field is the calendar day stored, in the reader's locale.
        expect(row.find('[data-testid="purchase-orders-date"]').text()).toBe('20/09/2026');
        // Q-B: the order lives on its quotation.
        expect(row.find('a').attributes('href')).toBe('/quotations/q1');
        expect(row.find('a').text()).toBe('QT-2026-0007');
    });

    it('falls back to the customer id when the server names no customer', async () => {
        const wrapper = await render(respond({ orders: [{ ...ORDER, customer_name: null }] }));

        expect(wrapper.find('[data-testid="purchase-orders-row"]').text()).toContain('c1');
    });

    it('searches both numbers through one q, back on page one', async () => {
        const fetchMock = respond({ pagination: { ...PAGINATION, total: 30, total_pages: 2, has_next_page: true } });
        const wrapper = await render(fetchMock);

        // From page two: a new question must not keep the old answer's page.
        await wrapper.find('[data-testid="purchase-orders-next"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="purchase-orders-search"]').setValue('4500123987');
        await wrapper.find('[data-testid="purchase-orders-filters"]').trigger('submit');
        await flushPromises();

        const [first, paged, searched] = queries(fetchMock);
        expect(first?.has('q')).toBe(false);
        expect(paged?.get('page')).toBe('2');
        expect(searched?.get('q')).toBe('4500123987');
        expect(searched?.get('page')).toBe('1');
    });

    it('pages forward with the same question', async () => {
        const fetchMock = respond({ pagination: { ...PAGINATION, total: 30, total_pages: 2, has_next_page: true } });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="purchase-orders-next"]').trigger('click');
        await flushPromises();

        expect(queries(fetchMock)[1]?.get('page')).toBe('2');
        expect(wrapper.find('[data-testid="purchase-orders-previous"]').attributes('disabled')).toBeDefined();
    });

    it('says none are visible rather than drawing an empty table, and a different sentence after a search', async () => {
        const wrapper = await render(respond({ orders: [] }));
        expect(wrapper.find('[data-testid="purchase-orders-table"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No purchase orders are visible to you');

        await wrapper.find('[data-testid="purchase-orders-search"]').setValue('nothing');
        await wrapper.find('[data-testid="purchase-orders-filters"]').trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('Nothing matches this search');
    });

    it('draws a 403 as a refusal and a 500 as a fault — never as an empty list', async () => {
        const refused = await render(respond({ status: 403 }));
        expect(refused.find('[data-testid="permission-denied-state"]').exists()).toBe(true);

        const broken = await render(respond({ status: 500 }));
        expect(broken.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(broken.text()).not.toContain('No purchase orders are visible to you');
    });
});
