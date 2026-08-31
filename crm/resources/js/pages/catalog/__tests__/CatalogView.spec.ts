import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CatalogView from '@/pages/catalog/CatalogView.vue';
import { createAppRouter } from '@/router';

/**
 * Module 4, Point 4.3 — §8's *Catalog* screen.
 *
 * ── §7.3, read from the source ─────────────────────────────────────────────
 *
 * "Descriptive data only — **no prices**. Two tabs: Product · Service, grouped
 * by company/team name." All three clauses are asserted below: the tabs are a
 * `filter[kind]` and not two endpoints, the grouping is `group_by=company` and
 * not a client-side sort, and no price, cost or margin reaches the screen.
 *
 * ── The list is the server's, and the test reads the URL to prove it ───────
 *
 * Design System §5.2 requires "server-side filters/sort/search" and §6.5 that
 * "Every list is server-paginated." `CatalogItemListCriteria` is the declared
 * surface — `ALLOWED_FILTERS = ['kind','category','is_active']`,
 * `ALLOWED_SORTS = ['name','created_at']`, `ALLOWED_GROUPS = ['company']`,
 * `DEFAULT_SORT = 'name'` — and `OpenAPI §6.2` answers anything undeclared with
 * a 400, so a control this screen invents is a control that breaks it.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put enforcement at the API, where
 * `CatalogListEndpointTest` proves it. What is proved here is what the screen
 * draws — including that a 403 is drawn as a refusal rather than as an empty
 * list, which would read as "the catalog is empty".
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

const PRODUCT = {
    id: 'c1',
    kind: 'product',
    name: 'Copper cable',
    product_code: 'P-100',
    category: 'wiring',
    unit: 'metre',
    service_type: null,
    company: 'Acme Industrial',
    description: 'Single core',
    notes: null,
    is_active: true,
    created_at: '2026-08-30T00:00:00+00:00',
    updated_at: '2026-08-30T00:00:00+00:00',
};

const SERVICE = {
    ...PRODUCT,
    id: 'c2',
    kind: 'service',
    name: 'On-site installation',
    product_code: null,
    category: null,
    unit: null,
    service_type: 'installation',
    notes: 'Two technicians',
};

function render(locale = 'en') {
    return mount(CatalogView, {
        global: {
            plugins: [createAppRouter(), createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

/** The URL of the nth fetch, so a filter can be read as the server will read it. */
function urlOf(fetchMock: ReturnType<typeof vi.fn>, index = 0): string {
    const call = fetchMock.mock.calls[index];

    expect(call).toBeDefined();

    return String(call?.[0]);
}

function page(rows: unknown[], pagination: Partial<typeof PAGINATION> = {}): Response {
    return json(200, { data: rows, meta: { pagination: { ...PAGINATION, ...pagination } } });
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CatalogView — the four states', () => {
    it('shows the loading state before the first page arrives', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        expect(render().find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('reports how many items the caller can reach', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([PRODUCT, { ...PRODUCT, id: 'c3' }])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="catalog-total"]').text()).toContain('2');
        expect(view.findAll('[data-testid="catalog-row"]')).toHaveLength(2);
    });

    it('shows the empty state when nothing comes back', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([], { total: 0 })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="catalog-table"]').exists()).toBe(false);
    });

    it('shows the error state when the request fails, and retries on demand', async () => {
        const fetchMock = vi.fn(async () => json(500, { error: { code: 'server_error', message: 'no' } }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);

        await view.find('[data-testid="error-retry"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.length).toBe(2);
    });

    /** A 403 is a boundary and a 500 is a fault; an empty list is neither. */
    it('draws a refusal as a refusal, never as an empty list', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'forbidden', message: 'no' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="empty-state"]').exists()).toBe(false);
    });
});

describe('CatalogView — §7.3, the two tabs and the grouping', () => {
    /** §7.3: "grouped by company/team name". `ALLOWED_GROUPS` is exactly `['company']`. */
    it('asks the server to group by company on every load', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        render();
        await flushPromises();

        expect(urlOf(fetchMock)).toContain('group_by=company');
    });

    /**
     * §7.3 names two tabs and no third, and Point 1.2 put both in one table
     * behind `kind`. So a tab is `filter[kind]` — one endpoint, not two — and
     * one of the two is always active.
     */
    it('opens on the Product tab and asks for products only', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        render();
        await flushPromises();

        expect(urlOf(fetchMock)).toContain('filter%5Bkind%5D=product');
    });

    it('asks for services when the Service tab is chosen, under the declared filter name', async () => {
        const fetchMock = vi.fn(async () => page([SERVICE]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="catalog-tab-service"]').trigger('click');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('filter%5Bkind%5D=service');
        // The tab is the question, so the answer starts at its first page.
        expect(urlOf(fetchMock, 1)).not.toContain('page=2');
    });

    /** §8: "announced status changes" — a tab strip states which tab is current. */
    it('marks the current tab for a screen reader, not only by colour', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([PRODUCT])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="catalog-tab-product"]').attributes('aria-selected')).toBe('true');
        expect(view.find('[data-testid="catalog-tab-service"]').attributes('aria-selected')).toBe('false');
    });

    /**
     * The heading is drawn from the order the server returned, not from a
     * client-side `sort()`. `group_by` changes the ordering and not the
     * envelope, so the screen's whole job is to notice where the value changes.
     */
    it('draws one heading per company, in the order the server sent', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([
            { ...PRODUCT, id: 'a1', company: 'Acme Industrial' },
            { ...PRODUCT, id: 'a2', company: 'Acme Industrial' },
            { ...PRODUCT, id: 'b1', company: 'Borealis' },
        ], { total: 3 })));

        const view = render();
        await flushPromises();

        const headings = view.findAll('[data-testid="catalog-group-heading"]');

        expect(headings).toHaveLength(2);
        expect(headings[0]?.text()).toContain('Acme Industrial');
        expect(headings[1]?.text()).toContain('Borealis');
    });

    /** The server sorts nulls last; an item with no company still needs a heading to sit under. */
    it('gives the items with no company a heading of their own', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([
            { ...PRODUCT, id: 'a1', company: 'Acme Industrial' },
            { ...PRODUCT, id: 'z1', company: null },
        ], { total: 2 })));

        const view = render();
        await flushPromises();

        const headings = view.findAll('[data-testid="catalog-group-heading"]');

        expect(headings).toHaveLength(2);
        expect(headings[1]?.text()).toBe(en.catalog.group.none);
    });

    /** §7.3's two field lists differ, so the columns follow the tab. */
    it('shows the product fields on one tab and the service fields on the other', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([PRODUCT])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="catalog-column-unit"]').exists()).toBe(true);
        expect(view.find('[data-testid="catalog-column-service_type"]').exists()).toBe(false);

        await view.find('[data-testid="catalog-tab-service"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="catalog-column-service_type"]').exists()).toBe(true);
        expect(view.find('[data-testid="catalog-column-unit"]').exists()).toBe(false);
    });

    /**
     * §7.3's first clause, and Module 4's acceptance criterion. `D-21` puts
     * price, cost and margin on the supplier quotation, and the payload carries
     * none of them — so nothing on this screen may name one either.
     */
    it('shows no price, cost or margin anywhere', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([PRODUCT, SERVICE])));

        const view = render();
        await flushPromises();

        expect(view.text()).not.toMatch(/price|cost|margin/i);
    });
});

describe('CatalogView — the declared filters and sorts', () => {
    it('sends the search term as q rather than filtering in the browser', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="catalog-search"]').setValue('cable');
        await view.find('[data-testid="catalog-search-form"]').trigger('submit');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('q=cable');
    });

    it('sends the category filter under the name the server declared', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="catalog-filter-category"]').setValue('wiring');
        await view.find('[data-testid="catalog-search-form"]').trigger('submit');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('filter%5Bcategory%5D=wiring');
    });

    /**
     * Three positions, not two. §10.4 hides a deactivated item from **selection
     * lists** — Modules 6 and 7 — and not from this management screen, so the
     * default asks nothing about the column.
     */
    it('asks nothing about is_active by default, and asks for false when told to', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(urlOf(fetchMock)).not.toContain('is_active');

        await view.find('[data-testid="catalog-filter-active"]').setValue('inactive');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('filter%5Bis_active%5D=false');
    });

    it('asks for the documented default sort on first load and flips it on a second click', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(urlOf(fetchMock)).toContain('sort=name');

        await view.find('[data-testid="catalog-sort-name"]').trigger('click');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('sort=-name');
        expect(view.find('[data-testid="catalog-column-name"]').attributes('aria-sort')).toBe('descending');
    });

    it('goes back to page one when the question changes, because page 2 is a position in an order', async () => {
        const fetchMock = vi.fn(async () => page([PRODUCT], { total: 60, total_pages: 3, has_next_page: true }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="catalog-next"]').trigger('click');
        await flushPromises();
        expect(urlOf(fetchMock, 1)).toContain('page=2');

        await view.find('[data-testid="catalog-filter-active"]').setValue('active');
        await flushPromises();
        expect(urlOf(fetchMock, 2)).not.toContain('page=2');
    });
});

describe('CatalogView — both languages', () => {
    it('renders its own title in Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([PRODUCT])));

        const view = render('ar');
        await flushPromises();

        expect(/[؀-ۿ]/.test(view.text())).toBe(true);
    });
});
