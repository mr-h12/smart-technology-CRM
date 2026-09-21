import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ImportModal from '@/components/imports/ImportModal.vue';
import SuppliersView from '@/pages/suppliers/SuppliersView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

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
    is_incomplete: false,
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

/**
 * Point 4.2 — §3.7's write controls, and the one permission that draws them.
 *
 * `SEC-09` is the whole point of these assertions: the button appearing is a
 * *menu*, and `SupplierWriteEndpointTest` is the *gate*. So each test asserts
 * both halves — drawn for the holder, absent for the one without — because a
 * one-sided assertion passes against a screen that draws nothing at all.
 *
 * The negative case is the CEO on purpose. §3.7 grants them `catalog.view` and
 * annotates the write column "read-only", which makes them the role the
 * documentation itself nominates for "reaches the screen, writes nothing".
 */

/** §3.7's write column: `catalog.manage`, `Scope::All`. */
const PROCUREMENT: AuthenticatedUser = {
    id: '01a0-proc',
    name: 'Procurement',
    email: 'procurement@example.test',
    is_active: true,
    role: { id: '01a0-role-proc', slug: 'procurement', name: 'Procurement' },
    permissions: ['catalog.view.all', 'catalog.manage.all'],
    unconditional_access: false,
};

/** §3.7's read-only ✅: the CEO reaches the screen and writes nothing on it. */
const CEO: AuthenticatedUser = {
    ...PROCUREMENT,
    id: '01a0-ceo',
    name: 'CEO',
    email: 'ceo@example.test',
    role: { id: '01a0-role-ceo', slug: 'ceo', name: 'CEO' },
    permissions: ['catalog.view.all'],
};

async function signIn(profile: AuthenticatedUser): Promise<void> {
    const delegate = globalThis.fetch as typeof globalThis.fetch;

    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

describe('SuppliersView — §3.7 write controls', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers New supplier to a holder of catalog.manage and to nobody else', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(PROCUREMENT);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="suppliers-create"]').exists()).toBe(true);

        vi.restoreAllMocks();
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(CEO);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="suppliers-create"]').exists()).toBe(false);
    });

    it('offers Edit on the row under the same permission, and no second one', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(PROCUREMENT);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="suppliers-row-edit"]').exists()).toBe(true);

        vi.restoreAllMocks();
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(CEO);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="suppliers-row-edit"]').exists()).toBe(false);
    });

    it('opens the dialog empty for a create and filled for an edit', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(PROCUREMENT);
        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="supplier-form-modal"]').exists()).toBe(false);

        await view.find('[data-testid="suppliers-create"]').trigger('click');
        expect((view.find('[data-testid="supplier-form-name"]').element as HTMLInputElement).value).toBe('');

        await view.find('[data-testid="supplier-form-cancel"]').trigger('click');
        await view.find('[data-testid="suppliers-row-edit"]').trigger('click');
        expect((view.find('[data-testid="supplier-form-name"]').element as HTMLInputElement).value).toBe(SUPPLIER.name);
    });

    /**
     * A rename moves the row under `sort=name` and a deactivation drops it out
     * of `filter[is_active]`, so the saved row is not patched in place — the
     * list is asked again (§5.2, §6.5).
     */
    it('closes the dialog and asks the server again once a supplier is saved', async () => {
        const fetchMock = vi.fn(async (input: string) => (/\/suppliers\/s1$/.test(String(input))
            ? json(200, { data: { ...SUPPLIER, name: 'Renamed' } })
            : page([SUPPLIER])));
        vi.stubGlobal('fetch', fetchMock);
        await signIn(PROCUREMENT);
        const view = render();
        await flushPromises();

        const before = fetchMock.mock.calls.length;

        await view.find('[data-testid="suppliers-row-edit"]').trigger('click');
        await view.find('[data-testid="supplier-form-name"]').setValue('Renamed');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="supplier-form-modal"]').exists()).toBe(false);
        // The PATCH, and then the list again.
        expect(fetchMock.mock.calls.length).toBe(before + 2);
    });
});

/**
 * F-09 · 1.5 (`D-85`) — the suppliers' import, on the customers' terms.
 *
 * `catalog.import` is the Manager's alone (§3.7, `D-85`), although
 * `catalog.manage` is Procurement's too — so Procurement is the negative case:
 * the role that can write a supplier by hand and still gets no import button.
 * `SupplierImportEndpointTest` is the gate; this is the menu.
 */
describe('SuppliersView — F-09 · 1.5 import and the incomplete flag', () => {
    const MANAGER: AuthenticatedUser = {
        ...PROCUREMENT,
        id: '01a0-mgr',
        name: 'Manager',
        email: 'manager@example.test',
        role: { id: '01a0-role-mgr', slug: 'manager', name: 'Manager' },
        permissions: ['catalog.view.all', 'catalog.manage.all', 'catalog.import.all'],
    };

    const BATCH = { id: 'b1', original_filename: 'suppliers.csv', row_count: 5, imported_count: 4, incomplete_count: 2 };

    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers the import to a holder of catalog.import and not to catalog.manage alone', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(MANAGER);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="suppliers-import"]').exists()).toBe(true);

        vi.restoreAllMocks();
        vi.stubGlobal('fetch', vi.fn(async () => page([SUPPLIER])));
        await signIn(PROCUREMENT);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="suppliers-import"]').exists()).toBe(false);
    });

    it('uploads to the suppliers import and asks the list again once it succeeds', async () => {
        const fetchMock = vi.fn(async (input: string) => (/\/suppliers\/import$/.test(String(input))
            ? json(201, { data: BATCH })
            : page([SUPPLIER])));
        vi.stubGlobal('fetch', fetchMock);
        await signIn(MANAGER);
        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-import"]').trigger('click');
        expect(view.find('[data-testid="import-modal"] h2').text()).toBe(en.suppliers.import.title);

        const input = view.find('[data-testid="import-file"]');
        Object.defineProperty(input.element, 'files', {
            value: [new File(['name\nAlpha Supply\n'], 'suppliers.csv', { type: 'text/csv' })],
            configurable: true,
        });
        await input.trigger('change');
        const before = fetchMock.mock.calls.length;
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(String(fetchMock.mock.calls[before]?.[0])).toBe('/api/v1/suppliers/import');
        // The upload, and then the list again.
        expect(fetchMock.mock.calls.length).toBe(before + 2);
        expect(view.find('[data-testid="import-failed"]').text()).toContain('1');
    });

    it('closes the dialog and applies the incomplete filter when the result asks for it', async () => {
        const fetchMock = vi.fn(async (input: string) => (/\/suppliers\/import$/.test(String(input))
            ? json(201, { data: BATCH })
            : page([SUPPLIER])));
        vi.stubGlobal('fetch', fetchMock);
        await signIn(MANAGER);
        const view = render();
        await flushPromises();

        await view.find('[data-testid="suppliers-import"]').trigger('click');
        view.findComponent(ImportModal).vm.$emit('showIncomplete');
        await flushPromises();

        expect(view.find('[data-testid="import-modal"]').exists()).toBe(false);
        expect(urlOf(fetchMock, fetchMock.mock.calls.length - 1)).toContain(`${encodeURIComponent('filter[is_incomplete]')}=true`);
        expect((view.find('[data-testid="suppliers-filter-incomplete"]').element as HTMLInputElement).checked).toBe(true);
    });

    it('sends filter[is_incomplete]=true when ticked, and nothing about it when not', async () => {
        const fetchMock = vi.fn(async () => page([SUPPLIER]));
        vi.stubGlobal('fetch', fetchMock);
        const view = render();
        await flushPromises();

        expect(urlOf(fetchMock, 0)).not.toContain(encodeURIComponent('filter[is_incomplete]'));

        await view.find('[data-testid="suppliers-filter-incomplete"]').setValue(true);
        await flushPromises();

        expect(urlOf(fetchMock, fetchMock.mock.calls.length - 1)).toContain(`${encodeURIComponent('filter[is_incomplete]')}=true`);
    });

    it('marks an incomplete supplier with a worded chip, and a complete one with none', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([
            { ...SUPPLIER, is_incomplete: true },
            { ...SUPPLIER, id: 's2', name: 'Beta Supply', is_incomplete: false },
        ])));
        const view = render('ar');
        await flushPromises();

        const rows = view.findAll('[data-testid="suppliers-row"]');

        expect(rows[0]!.find('[data-testid="suppliers-incomplete"]').text()).toBe(ar.suppliers.incomplete);
        expect(rows[1]!.find('[data-testid="suppliers-incomplete"]').exists()).toBe(false);
    });
});
