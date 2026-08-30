import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import CustomersView from '@/pages/customers/CustomersView.vue';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';
import { createAppRouter } from '@/router';

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

function render(locale = 'en', options: { attachTo?: HTMLElement } = {}) {
    // The router is not decoration: Point 4.4 made the name cell a `RouterLink`,
    // and without a router it resolves to nothing while every assertion here
    // still passes. That is a test covering an invisible feature.
    //
    // `attachTo` is Point 4.5's: §6.6's "return focus to the invoking control"
    // cannot be observed through `document.activeElement` unless the component
    // is really in the document.
    return mount(CustomersView, {
        // Spread rather than assigned: `exactOptionalPropertyTypes` refuses an
        // explicit `undefined` where the property is optional.
        ...(options.attachTo === undefined ? {} : { attachTo: options.attachTo }),
        global: {
            plugins: [createAppRouter(), createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
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

/**
 * The URLs the component asked for, in order — the only honest record of a
 * server-side sort. Read them through `customerCalls()`: Point 4.2 added a
 * second request on mount (the sector options), so a bare index addresses
 * whichever of the two landed first.
 */
function stubList(bodies: { data: unknown[]; meta: { pagination: typeof PAGINATION } }[]): string[] {
    const asked: string[] = [];
    let call = 0;

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            asked.push(url);

            if (url.includes('/managed-lists/')) {
                return json(200, { data: [], meta: { pagination: PAGINATION } });
            }

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

        expect(customerCalls(asked)[0]).toContain('sort=name');
    });

    it('asks the server for the reversed order when the active column is clicked', async () => {
        const asked = stubList([page([ROW])]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-sort-name"]').trigger('click');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('sort=-name');
    });

    it('asks the server, not the browser, when a different column is chosen', async () => {
        const asked = stubList([page([ROW])]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-sort-start_date"]').trigger('click');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('sort=start_date');
    });

    /** A sort changes what "page 2" contains, so staying on it shows a page of a different list. */
    it('returns to the first page when the sort changes', async () => {
        const asked = stubList([page([ROW], { total: 40, total_pages: 2, has_next_page: true })]);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-next"]').trigger('click');
        await flushPromises();
        expect(customerCalls(asked)[1]).toContain('page=2');

        await view.find('[data-testid="customers-sort-name"]').trigger('click');
        await flushPromises();

        expect(customerCalls(asked)[2]).toContain('page=1');
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

/**
 * Point 4.2 — §5.2's server-side filters and search.
 *
 * `ALLOWED_FILTERS` is a closed set of five and `OpenAPI §6.2` answers an
 * undeclared one with a 400, so every assertion here is again about the query
 * string: a filter that never reaches the server is a control that lies.
 *
 * §10.1 **requires** the "Customers of deactivated employees" filter on this
 * screen. `filter[is_archived]` is deliberately absent — it belongs to Point
 * 4.5's archive screen, per the decomposition the owner approved on 2026-08-30.
 *
 * The URLs are decoded before they are matched. `filter[sector]` is sent as
 * `filter%5Bsector%5D`, and an assertion written against the escaped form reads
 * as a puzzle rather than as the contract.
 */

const SECTOR = { code: 'medical', label_en: 'Medical', label_ar: 'طبي', position: 2 };

function stubScreen(options: { sectors?: unknown[]; sectorsStatus?: number; pages?: unknown[] } = {}): string[] {
    const asked: string[] = [];
    let call = 0;

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            asked.push(url);

            if (url.includes('/managed-lists/')) {
                return options.sectorsStatus === undefined
                    ? json(200, { data: options.sectors ?? [SECTOR], meta: { pagination: PAGINATION } })
                    : json(options.sectorsStatus, { error: { code: 'permission_denied', message: 'no' } });
            }

            const bodies = (options.pages ?? [page([ROW])]) as unknown[];

            return json(200, bodies[Math.min(call++, bodies.length - 1)]);
        }),
    );

    return asked;
}

/** Only the customer requests, decoded — the managed list is asked for once and is not the subject. */
function customerCalls(asked: string[]): string[] {
    return asked.filter((url) => url.startsWith('/api/v1/customers')).map((url) => decodeURIComponent(url));
}

describe('CustomersView — §5.2 filters and search', () => {
    it('offers the sectors the administrator configured, in the caller’s language', async () => {
        stubScreen();

        const view = render('ar');
        await flushPromises();

        expect(view.find('[data-testid="customers-filter-sector"]').text()).toContain('طبي');
    });

    it('sends the words a person typed as `q`, and drops them again when the box is cleared', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-search"]').setValue('أحمد');
        await view.find('[data-testid="customers-search-form"]').trigger('submit');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('q=أحمد');

        await view.find('[data-testid="customers-search"]').setValue('');
        await view.find('[data-testid="customers-search-form"]').trigger('submit');
        await flushPromises();

        expect(customerCalls(asked)[2]).not.toContain('q=');
    });

    it('sends the chosen status as a declared filter, and keeps the sort', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-filter-status"]').setValue('customer');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('filter[customer_status]=customer');
        expect(customerCalls(asked)[1]).toContain('sort=name');
    });

    it('sends the chosen sector as a declared filter', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-filter-sector"]').setValue('medical');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('filter[sector]=medical');
    });

    /**
     * The rule `services/customers.ts` was written around: `false` is a filter
     * and the absence of one is not `false`. An unchecked box asks nothing
     * about `is_incomplete`; sending `false` would ask for the complete records
     * only, which is a different question and would hide `D-31`'s rows.
     */
    it('asks about incomplete records only when the box is checked', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-filter-incomplete"]').setValue(true);
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('filter[is_incomplete]=true');

        await view.find('[data-testid="customers-filter-incomplete"]').setValue(false);
        await flushPromises();

        expect(customerCalls(asked)[2]).not.toContain('is_incomplete');
    });

    /** §10.1, required in as many words: a "Customers of deactivated employees" filter on this screen. */
    it('asks for the customers of deactivated employees', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-filter-owner-inactive"]').setValue(true);
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('filter[owner_inactive]=true');
    });

    it('returns to the first page when a filter changes', async () => {
        const asked = stubScreen({ pages: [page([ROW], { total: 40, total_pages: 2, has_next_page: true })] });

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-next"]').trigger('click');
        await flushPromises();
        expect(customerCalls(asked)[1]).toContain('page=2');

        await view.find('[data-testid="customers-filter-status"]').setValue('customer');
        await flushPromises();

        expect(customerCalls(asked)[2]).toContain('page=1');
    });

    /** The options are a convenience. A screen that dies because a dropdown could not be filled is worse than one filter short. */
    it('still lists customers when the sector options cannot be loaded', async () => {
        // The refused request is asserted too. Without it this test passes
        // against a screen that never asks for the options at all — which is
        // exactly what it did in this point's RED run.
        const asked = stubScreen({ sectorsStatus: 403 });

        const view = render();
        await flushPromises();

        expect(asked.some((url) => url.includes('/managed-lists/sectors'))).toBe(true);
        expect(view.find('[data-testid="customers-table"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    /** "You have no customers" and "nothing matched" are different sentences, and only one of them is true. */
    it('says nothing matched, rather than nothing exists, when a filter empties the list', async () => {
        stubScreen({ pages: [page([ROW]), page([], { total: 0 })] });

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-filter-status"]').setValue('customer');
        await flushPromises();

        expect(view.find('[data-testid="empty-state"]').text()).toContain('filter');
    });
});

/**
 * Point 4.3 — the two write controls, and the permissions that draw them.
 *
 * `SEC-09` is the whole point of these four assertions: the button appearing is
 * a *menu*, and `CustomerWriteEndpointTest` is the *gate*. So each test asserts
 * both halves — drawn for the holder, absent for the one without — because a
 * one-sided assertion passes against a screen that draws nothing at all.
 */

/** §3.3's Indoor Sales row: `create` yes, `edit` yes, both scoped `own`. */
const SALES: AuthenticatedUser = {
    id: '01a0-sales',
    name: 'Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role-ind', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own', 'customer.create.own', 'customer.edit.own'],
    unconditional_access: false,
};

/** A caller who may read the list and write nothing on it. */
const READER: AuthenticatedUser = { ...SALES, permissions: ['customer.view.own'] };

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

describe('CustomersView — §3.3 write controls', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers New customer to a holder of customer.create and to nobody else', async () => {
        stubScreen();
        await signIn(SALES);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="customers-create"]').exists()).toBe(true);

        vi.restoreAllMocks();
        stubScreen();
        await signIn(READER);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="customers-create"]').exists()).toBe(false);
    });

    it('offers a row Edit to a holder of customer.edit and to nobody else', async () => {
        stubScreen();
        await signIn(SALES);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="customers-row-edit"]').exists()).toBe(true);

        vi.restoreAllMocks();
        stubScreen();
        await signIn(READER);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="customers-row-edit"]').exists()).toBe(false);
    });

    it('opens the form empty for a create and filled for an edit', async () => {
        stubScreen();
        await signIn(SALES);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-row-edit"]').trigger('click');
        expect((view.find('[data-testid="customer-form-name"]').element as HTMLInputElement).value)
            .toBe('Alpha Trading');

        await view.find('[data-testid="customer-form-cancel"]').trigger('click');
        await view.find('[data-testid="customers-create"]').trigger('click');
        expect((view.find('[data-testid="customer-form-name"]').element as HTMLInputElement).value).toBe('');
    });

    /**
     * §10.2 · `D-35`: the warning must be *seen*. A clean save closes the
     * dialog; one the server flagged keeps it open so the names can be read.
     */
    it('closes after a clean save and stays open when the server named a duplicate', async () => {
        const asked = stubScreen();
        await signIn(SALES);

        const view = render();
        await flushPromises();

        const listCallsBefore = asked.filter((url) => url.includes('/customers?')).length;

        await view.find('[data-testid="customers-create"]').trigger('click');
        await view.find('[data-testid="customer-form-name"]').setValue('Zeta Industrial');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-form"]').exists()).toBe(false);
        // The list was asked again, so the new row can appear.
        expect(asked.filter((url) => url.includes('/customers?')).length).toBeGreaterThan(listCallsBefore);
    });
});

describe('CustomersView — Point 4.4 row link', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    /**
     * `navigation.ts`'s rule is why the name was plain text through 4.1–4.3:
     * "a dead link is not a permission problem, it is a lie". 4.4 registered
     * `customer-detail`, so it resolves — and the `href` proves it resolved
     * rather than rendering an anchor to nowhere.
     */
    it('links each row to that customer, by id', async () => {
        stubScreen();

        const view = render();
        await flushPromises();

        const link = view.find('[data-testid="customers-row-link"]');

        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/customers/c1');
        expect(link.text()).toContain('Alpha Trading');
    });
});

/**
 * Point 4.5 — the archive half of the list, and the select-all Flow 7 names.
 *
 * ── Why this is the list screen and not a new one ──────────────────────────
 *
 * §8 does name an **Archive** item, for the Manager and the Team Leader — but
 * Flow 7's first row puts a rejected *quotation* in "the quotation archive"
 * while "the customer stays in the list", so that item spans modules and its
 * other half is Module 7. Module 3 can honestly fill one half, and it fills it
 * where the rows already are: `filter[is_archived]`, which Point 4.2 left out
 * on purpose. A dedicated Archive route holding customers alone would promise
 * a screen §8 describes and this module cannot yet draw — `navigation.ts`'s
 * rule in a third costume.
 *
 * ── The toggle has two positions because the server has two ────────────────
 *
 * `CustomerListCriteria` reads `$filters['is_archived'] ?? false`, so absence
 * *is* `false` and there is no "both" to ask for. A three-way control would
 * have a position the API cannot answer.
 *
 * ── Select-all is a loop over the singular route (owner, 2026-08-30) ───────
 *
 * `API-07` and `OpenAPI §7.3` describe a bulk endpoint and none exists. The
 * owner chose the loop: each call carries the same `customer.archive`
 * middleware and the same row-scoped lookup, so §7.3's "authorize and audit
 * each affected record" and "do not allow a bulk request to bypass row scope"
 * hold by construction rather than by a new server promise. The narrowing is
 * recorded in `CHECKLIST.md` awaiting a `D-xx`.
 */
describe('CustomersView — Point 4.5, the archive half', () => {
    beforeEach(() => {
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    /** §3.3's merged `archive / restore` row — one permission, both directions. */
    const ARCHIVER: AuthenticatedUser = {
        ...SALES,
        permissions: ['customer.view.own', 'customer.edit.own', 'customer.archive.own'],
    };

    const ARCHIVED_ROW = { ...ROW, id: 'c9', name: 'Dormant Co', is_archived: true };

    it('states which half it is showing, in both positions', async () => {
        const asked = stubScreen();

        const view = render();
        await flushPromises();

        // The default view is the working list, and it says so rather than
        // relying on the server's default to mean the same thing.
        expect(customerCalls(asked)[0]).toContain('filter[is_archived]=false');

        await view.find('[data-testid="customers-filter-archived"]').setValue('archived');
        await flushPromises();

        expect(customerCalls(asked)[1]).toContain('filter[is_archived]=true');
    });

    it('returns to page 1 when the half changes', async () => {
        const asked = stubScreen({ pages: [page([ROW], { total: 40, total_pages: 2, has_next_page: true })] });

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-next"]').trigger('click');
        await flushPromises();
        expect(customerCalls(asked)[1]).toContain('page=2');

        await view.find('[data-testid="customers-filter-archived"]').setValue('archived');
        await flushPromises();

        // "Page 2" addresses a position in a list. Swap the list and the number
        // points at rows nobody asked for.
        expect(customerCalls(asked)[2]).toContain('page=1');
    });

    /** `SEC-09`: the button is the menu and `CustomerArchiveEndpointTest` is the gate. */
    it('offers a row Archive to a holder of customer.archive and to nobody else', async () => {
        stubScreen();
        await signIn(ARCHIVER);
        const permitted = render();
        await flushPromises();

        expect(permitted.find('[data-testid="customers-row-archive"]').exists()).toBe(true);

        vi.restoreAllMocks();
        stubScreen();
        await signIn(READER);
        const refused = render();
        await flushPromises();

        expect(refused.find('[data-testid="customers-row-archive"]').exists()).toBe(false);
    });

    /**
     * §6.2 puts Archive in the Danger variant and §6.6 requires the action to
     * "show their consequence" before submission. Both halves are asserted:
     * nothing is sent while the question is open, and it is sent once answered.
     */
    it('asks before archiving, and only then calls the endpoint', async () => {
        const asked = stubScreen();
        await signIn(ARCHIVER);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-row-archive"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="confirm-dialog"]').exists()).toBe(true);
        expect(asked.some((url) => url.includes('/archive'))).toBe(false);

        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(asked.some((url) => url.endsWith('/customers/c1/archive'))).toBe(true);
    });

    it('sends nothing when the question is declined', async () => {
        const asked = stubScreen();
        await signIn(ARCHIVER);

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customers-row-archive"]').trigger('click');
        await view.find('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
        expect(asked.some((url) => url.includes('/archive'))).toBe(false);
    });

    /**
     * The row decides, not the filter: an archived row offers Restore and an
     * active one offers Archive, so a list holding both is still right.
     */
    it('offers Restore on an archived row and Archive on an active one', async () => {
        stubScreen({ pages: [page([ARCHIVED_ROW])] });
        await signIn(ARCHIVER);

        const archived = render();
        await flushPromises();

        expect(archived.find('[data-testid="customers-row-restore"]').exists()).toBe(true);
        expect(archived.find('[data-testid="customers-row-archive"]').exists()).toBe(false);

        vi.restoreAllMocks();
        stubScreen();
        await signIn(ARCHIVER);
        const active = render();
        await flushPromises();

        expect(active.find('[data-testid="customers-row-archive"]').exists()).toBe(true);
        expect(active.find('[data-testid="customers-row-restore"]').exists()).toBe(false);
    });

    /** Flow 7 gives select-all to *Restore*. An active list has nothing to select for. */
    it('offers selection only where there is something to restore', async () => {
        stubScreen({ pages: [page([ARCHIVED_ROW])] });
        await signIn(ARCHIVER);

        const archived = render();
        await flushPromises();

        expect(archived.find('[data-testid="customers-select-all"]').exists()).toBe(true);

        vi.restoreAllMocks();
        stubScreen();
        await signIn(ARCHIVER);
        const active = render();
        await flushPromises();

        expect(active.find('[data-testid="customers-select-all"]').exists()).toBe(false);
    });

    /**
     * §6.5 bounds this: "Every list is server-paginated. Do not create a UI
     * that requires loading all records." Select-all is therefore the page in
     * hand, and the assertion names the rows that were and were not restored.
     */
    it('restores every selected row and leaves the rest alone', async () => {
        const second = { ...ARCHIVED_ROW, id: 'c8', name: 'Sleeping Ltd' };
        const third = { ...ARCHIVED_ROW, id: 'c7', name: 'Quiet Bros' };
        const asked = stubScreen({ pages: [page([ARCHIVED_ROW, second, third], { total: 3 })] });
        await signIn(ARCHIVER);

        const view = render();
        await flushPromises();

        const boxes = view.findAll('[data-testid="customers-select-row"]');
        await boxes[0]!.setValue(true);
        await boxes[1]!.setValue(true);
        await flushPromises();

        await view.find('[data-testid="customers-bulk-restore"]').trigger('click');
        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        const restored = asked.filter((url) => url.endsWith('/restore'));

        expect(restored).toContain('/api/v1/customers/c9/restore');
        expect(restored).toContain('/api/v1/customers/c8/restore');
        expect(restored).not.toContain('/api/v1/customers/c7/restore');
    });

    /**
     * §7.3 asks a bulk operation to "return per-record result data". A loop
     * produces exactly that, and a partial failure must survive as a partial
     * failure — the alternative is one refused row silently reported as done.
     */
    it('reports a partial failure rather than claiming the whole batch worked', async () => {
        const second = { ...ARCHIVED_ROW, id: 'c8', name: 'Sleeping Ltd' };

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => {
                if (url.includes('/managed-lists/')) {
                    return json(200, { data: [SECTOR], meta: { pagination: PAGINATION } });
                }

                if (url.endsWith('/c8/restore')) {
                    return json(403, { error: { code: 'permission_denied', message: 'no' } });
                }

                if (url.endsWith('/restore')) {
                    return json(200, { data: { ...ARCHIVED_ROW, is_archived: false } });
                }

                return json(200, page([ARCHIVED_ROW, second], { total: 2 }));
            }),
        );

        await signIn(ARCHIVER);
        const view = render();
        await flushPromises();

        for (const box of view.findAll('[data-testid="customers-select-row"]')) {
            await box.setValue(true);
        }

        await view.find('[data-testid="customers-bulk-restore"]').trigger('click');
        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        // One of the two was refused, and the screen says so rather than
        // closing quietly on a batch that half happened.
        expect(view.find('[data-testid="customers-bulk-result"]').text()).toContain('1');
    });

    /** §6.6: "return focus to the invoking control". */
    it('returns focus to the control that opened the dialog', async () => {
        stubScreen();
        await signIn(ARCHIVER);

        const view = render('en', { attachTo: document.body });
        await flushPromises();

        const trigger = view.find('[data-testid="customers-row-archive"]');
        (trigger.element as HTMLButtonElement).focus();
        await trigger.trigger('click');
        await flushPromises();
        // `ConfirmDialog` moves focus inside a `setTimeout(…, 0)`, and
        // `flushPromises` drains microtasks only — so waiting on a macrotask is
        // the difference between this assertion being deterministic and being
        // a coin flip that lands differently under suite load.
        await new Promise((resolve) => setTimeout(resolve, 0));

        // Not decoration: without this half, `invoker.focus()` below would
        // "return" focus to a button that never lost it, and the test would
        // pass against a dialog that steals focus and never gives it back.
        expect(document.activeElement).not.toBe(trigger.element);

        await view.find('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(trigger.element);
    });
});
