import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SuppliersView from '@/pages/suppliers/SuppliersView.vue';
import { createAppRouter } from '@/router';

/**
 * Module 4, Point 4.1 — §8's *Suppliers* screen.
 *
 * ── The list is the server's, and the test reads the URL to prove it ───────
 *
 * Design System §5.2 requires "server-side filters/sort/search" and §6.5 that
 * "Every list is server-paginated. Do not create a UI that requires loading all
 * records." A client-side `filter()` orders the 25 rows in hand and silently
 * claims to have ordered all of them, so every assertion about a filter or a
 * sort below reads the **query string**, never the rendered rows.
 *
 * The declared surface is `SupplierListCriteria`'s: four filters, two sorts,
 * `DEFAULT_SORT = 'name'`. `OpenAPI §6.2` answers an undeclared filter or sort
 * with a 400, so a control this screen invents is a control that breaks it.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put enforcement at the API, where
 * `SupplierListEndpointTest` proves it by withdrawing the seeded grant. What is
 * proved here is what the screen draws — including that a 403 is drawn as a
 * refusal rather than as an empty list, which would read as "you have no
 * suppliers".
 *
 * ── The chip is the acceptance criterion ───────────────────────────────────
 *
 * §7.1: the colour "appears as a chip beside the supplier name on **every**
 * screen", and Design System §6.4 requires it to carry a word. This is the
 * first screen, so it is the first place that criterion can be checked at all.
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

const SUPPLIER = {
    id: 's1',
    name: 'Alpha Supply',
    type: 'supplier',
    color_rating: 'red',
    phone: '0100',
    contact_person: 'Sara',
    has_open_account: false,
    is_active: true,
    created_at: '2026-08-30T00:00:00+00:00',
    updated_at: '2026-08-30T00:00:00+00:00',
};

function render(locale = 'en') {
    return mount(SuppliersView, {
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

describe('SuppliersView — the four states', () => {
    it('shows the loading state before the first page arrives', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        expect(render().find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    it('reports how many suppliers the caller can reach', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER, { ...SUPPLIER, id: 's2' }])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="suppliers-total"]').text()).toContain('2');
        expect(view.findAll('[data-testid="suppliers-row"]')).toHaveLength(2);
    });

    it('shows the empty state when nothing comes back', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([], { total: 0 })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="suppliers-table"]').exists()).toBe(false);
    });

    it('shows the error state when the request fails, and retries on demand', async () => {
        const fetchMock = vi.fn(async () => json(500, { error: { code: 'server_error', message: 'no' } }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);

        fetchMock.mockImplementation(async () => page([SUPPLIER]));
        await view.find('[data-testid="error-retry"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    /** `SEC-09`: a 403 means the screen and the API disagree, and saying "empty" would hide that. */
    it('draws a refusal as a refusal, never as an empty list', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'forbidden', message: 'no' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="empty-state"]').exists()).toBe(false);
    });
});

describe('SuppliersView — §7.1\'s chip', () => {
    it('puts a rating chip beside every supplier name, carrying a word and not only a colour', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));

        const view = render();
        await flushPromises();

        const chip = view.find('[data-testid="supplier-rating"]');

        expect(chip.exists()).toBe(true);
        expect(chip.text().trim()).not.toBe('');
        expect(chip.text()).not.toBe('red');
    });

    it('renders the chip for a supplier that has never been rated', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([{ ...SUPPLIER, color_rating: 'white' }])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="supplier-rating"]').text().trim()).not.toBe('');
    });
});

describe('SuppliersView — the query is the server\'s', () => {
    it('asks for the documented default sort on first load', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        render();
        await flushPromises();

        expect(urlOf(fetchMock)).toContain('sort=name');
    });

    it('sends the search term as q rather than filtering in the browser', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-search"]').setValue('alpha');
        await view.find('[data-testid="suppliers-search-form"]').trigger('submit');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('q=alpha');
    });

    it('sends the colour filter under the name the server declared', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-filter-rating"]').setValue('red');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain(encodeURIComponent('filter[color_rating]'));
        expect(urlOf(fetchMock, 1)).toContain('red');
    });

    it('sends the type filter under the name the server declared', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-filter-type"]').setValue('distributor');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain(encodeURIComponent('filter[type]'));
        expect(urlOf(fetchMock, 1)).toContain('distributor');
    });

    /**
     * §10.4 hides a deactivated supplier from **selection lists**, not from this
     * management screen, so the default asks nothing about the column at all —
     * and a screen that hid them by default is one nobody could reactivate from.
     */
    it('asks nothing about is_active by default, and asks for false when told to', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        expect(urlOf(fetchMock)).not.toContain(encodeURIComponent('filter[is_active]'));

        await view.find('[data-testid="suppliers-filter-active"]').setValue('inactive');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain(encodeURIComponent('filter[is_active]'));
        expect(urlOf(fetchMock, 1)).toContain('false');
    });

    it('sorts on the server and flips direction on a second click', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-sort-name"]').trigger('click');
        await flushPromises();

        expect(urlOf(fetchMock, 1)).toContain('sort=-name');
    });

    it('announces the sort state on the column header', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="suppliers-column-name"]').attributes('aria-sort')).toBe('ascending');
    });

    it('goes back to page one when the question changes, because page 2 is a position in an order', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER], { total: 60, total_pages: 3, has_next_page: true }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-next"]').trigger('click');
        await flushPromises();
        expect(urlOf(fetchMock, 1)).toContain('page=2');

        await view.find('[data-testid="suppliers-filter-rating"]').setValue('green');
        await flushPromises();
        expect(urlOf(fetchMock, 2)).not.toContain('page=2');
    });
});

describe('SuppliersView — both languages', () => {
    it('renders its own title in Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));

        const view = render('ar');
        await flushPromises();

        expect(/[؀-ۿ]/.test(view.text())).toBe(true);
    });
});
