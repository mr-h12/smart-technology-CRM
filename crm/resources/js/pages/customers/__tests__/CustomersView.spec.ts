import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import CustomersView from '@/pages/customers/CustomersView.vue';

/**
 * Module 3, Point 4.0 — the screen the route resolves to.
 *
 * ── What 4.0 owes, and what it does not ────────────────────────────────────
 *
 * `navigation.ts` says plainly why a link is not added before its screen:
 * "a dead link is not a permission problem, it is a lie". So this point ships
 * a screen that really loads customers and really renders Design System §5.2's
 * four states — **not** a placeholder, and **not** the table, the filters or
 * the paginator, which are Points 4.1 and 4.2.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 · `SEC-09`. The refusals are proved against the server by
 * `CustomerListEndpointTest`. What is proved here is what the screen draws.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 2,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

const CUSTOMER = { id: 'c1', name: 'Alpha Trading', customer_status: 'prospect', is_archived: false, is_incomplete: false };

function render(locale = 'en') {
    return mount(CustomersView, {
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CustomersView', () => {
    it('shows the loading state before the first page arrives', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        expect(render().find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('reports how many customers the caller can reach', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [CUSTOMER, { ...CUSTOMER, id: 'c2' }], meta: { pagination: PAGINATION } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customer-total"]').text()).toContain('2');
        expect(view.find('[data-testid="empty-state"]').exists()).toBe(false);
    });

    /**
     * ⚠️ The state three roles see today. `team`, `out` and `asgn` have no
     * mechanism (owner's deferral, 2026-08-29), so a Team Leader's list is
     * empty — and an empty state is the honest rendering of an empty result,
     * not of a refusal.
     */
    it('shows the empty state when the scope reaches no rows', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [], meta: { pagination: { ...PAGINATION, total: 0 } } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="empty-state"]').exists()).toBe(true);
    });

    it('shows the error state when the request fails, and retries on demand', async () => {
        const fetchMock = vi.fn(async () => json(500, { error: { code: 'server_error', message: 'no' } }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);

        fetchMock.mockImplementation(async () => json(200, { data: [CUSTOMER], meta: { pagination: PAGINATION } }));
        await view.find('[data-testid="error-retry"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    /** `SEC-09`: hiding is presentation. A 403 is still the server's answer, and the screen says so. */
    it('shows the permission-denied state on a 403', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'permission_denied', message: 'no' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
    });

    it('renders its title in Arabic for an Arabic caller', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: [], meta: { pagination: { ...PAGINATION, total: 0 } } })));

        const view = render('ar');
        await flushPromises();

        expect(view.text()).toContain('العملاء');
    });
});
