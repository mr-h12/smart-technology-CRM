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

/**
 * Point 4.1 — Design System §5.2's Table/List for Customers.
 *
 * §5.2 requires "Pagination, server-side filters/sort/search, column
 * priorities, empty/loading/error states" and §6.5 adds the row and header
 * rules. The filters and the search box are Point 4.2; what is proved here is
 * the table, the **server-side** sort and the paginator.
 *
 * ── Why every sort assertion reads the URL ─────────────────────────────────
 *
 * §6.5: "Every list is server-paginated. Do not create a UI that requires
 * loading all records." A sort implemented with `Array.sort` on the page in
 * hand would pass any assertion made about the rendered order and would still
 * be the defect this point exists to avoid — it orders 25 rows out of 4,000.
 * So the assertions are about what was **asked of the server**.
 */

const ROW = {
    id: 'c1',
    name: 'Alpha Trading',
    customer_status: 'prospect',
    sector: 'medical',
    region: 'Cairo',
    contact_person: 'Mona Adel',
    phone: '+20 100 000 0000',
    phone2: null,
    whatsapp: null,
    email: null,
    sales_owner_id: null,
    start_date: '2024-03-01',
    notes: null,
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-08-01T09:00:00Z',
    updated_at: '2026-08-01T09:00:00Z',
};

/** The URLs the component asked for, in order — the only honest record of a server-side sort. */
function stubList(bodies: { data: unknown[]; meta: { pagination: typeof PAGINATION } }[]): string[] {
    const asked: string[] = [];
    let call = 0;

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            asked.push(url);

            return json(200, bodies[Math.min(call++, bodies.length - 1)]);
        }),
    );

    return asked;
}

function page(items: unknown[], pagination: Partial<typeof PAGINATION> = {}) {
    return { data: items, meta: { pagination: { ...PAGINATION, ...pagination } } };
}

describe('CustomersView — §5.2 table', () => {
    it('renders one row per customer with §4.2 columns', async () => {
        stubList([page([ROW, { ...ROW, id: 'c2', name: 'Beta Medical' }], { total: 2 })]);

        const view = render();
        await flushPromises();

        const rows = view.findAll('[data-testid="customers-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('Alpha Trading');
        expect(rows[0]?.text()).toContain('Mona Adel');
        expect(rows[0]?.text()).toContain('+20 100 000 0000');
        expect(rows[1]?.text()).toContain('Beta Medical');
    });

    /** `CustomerListCriteria::DEFAULT_SORT` — the server's documented default, stated rather than assumed. */
    it('asks for the documented default order on the first load', async () => {
        const asked = stubList([page([ROW])]);

        render();
        await flushPromises();

        expect(asked[0]).toContain('sort=name');
    });

    it('asks the server for the reversed order when the active column is clicked', async () => {
        const asked = stubList([page([ROW])]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-sort-name"]').trigger('click');
        await flushPromises();

        expect(asked[1]).toContain('sort=-name');
    });

    it('asks the server, not the browser, when a different column is chosen', async () => {
        const asked = stubList([page([ROW])]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-sort-start_date"]').trigger('click');
        await flushPromises();

        expect(asked[1]).toContain('sort=start_date');
    });

    /** A sort changes what "page 2" contains, so staying on it shows a page of a different list. */
    it('returns to the first page when the sort changes', async () => {
        const asked = stubList([page([ROW], { total: 40, total_pages: 2, has_next_page: true })]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-next"]').trigger('click');
        await flushPromises();
        expect(asked[1]).toContain('page=2');

        await view.find('[data-testid="customers-sort-name"]').trigger('click');
        await flushPromises();

        expect(asked[2]).toContain('page=1');
    });

    it('marks the sorted column with aria-sort and leaves the others unsorted', async () => {
        stubList([page([ROW])]);

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customers-column-name"]').attributes('aria-sort')).toBe('ascending');
        expect(view.find('[data-testid="customers-column-start_date"]').attributes('aria-sort')).toBe('none');

        await view.find('[data-testid="customers-sort-name"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="customers-column-name"]').attributes('aria-sort')).toBe('descending');
    });

    /** §4.5: the status is derived and read-only. `prospect` is a code, not a word a person reads. */
    it('translates the derived status instead of printing its code', async () => {
        stubList([page([{ ...ROW, customer_status: 'deal_not_completed' }])]);

        const view = render();
        await flushPromises();

        const badge = view.find('[data-testid="customers-status"]');

        expect(badge.text()).toBe('Deal not completed');
        expect(view.find('[data-testid="customers-row"]').text()).not.toContain('deal_not_completed');
    });

    it('renders a placeholder for a field the customer has not filled in', async () => {
        stubList([page([{ ...ROW, contact_person: null, phone: null }])]);

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customers-row"]').text()).not.toContain('null');
    });

    /**
     * Both halves in one test, deliberately. The "hides it" half alone **passed
     * against a screen with no paginator at all** in this point's RED run — a
     * test that cannot fail is the defect, not the evidence.
     */
    it('renders the paginator only when the server reports more than one page', async () => {
        stubList([page([ROW], { total: 40, total_pages: 2, has_next_page: true })]);

        const paged = render();
        await flushPromises();

        expect(paged.find('[data-testid="customers-pagination"]').exists()).toBe(true);
        expect(paged.find('[data-testid="customers-previous"]').attributes('disabled')).toBeDefined();

        stubList([page([ROW])]);

        const single = render();
        await flushPromises();

        expect(single.find('[data-testid="customers-pagination"]').exists()).toBe(false);
    });

    it('renders its column headers in Arabic for an Arabic caller', async () => {
        stubList([page([ROW])]);

        const view = render('ar');
        await flushPromises();

        expect(view.find('[data-testid="customers-table"]').text()).toContain('القطاع');
    });
});
