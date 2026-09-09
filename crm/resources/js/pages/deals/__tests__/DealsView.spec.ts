import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealsView from '@/pages/deals/DealsView.vue';
import { NAVIGATION } from '@/navigation';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.2 — §8's *Requests / Deals* screen, Design System §5.2's
 * Table/List.
 *
 * ── The list is the server's, and the test reads the URL to prove it ───────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated". A client-side `filter()` narrows the 25 rows in hand and
 * silently claims to have narrowed all of them, so every assertion about a
 * filter, a sort or the search below reads the **query string**, never the
 * rendered rows.
 *
 * The declared surface is `DealListCriteria`'s: four filters, three sorts,
 * `-last_activity_at` by default, and — unlike Module 6's list — a **real
 * search**, because `SearchIndex::Deals` indexes §4.3's `title`.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put enforcement at the API, where
 * `DealListEndpointTest` proves it against the seeded matrix. What is proved
 * here is what the screen draws — including that a 403 is drawn as a refusal
 * and not as an empty list.
 *
 * ── The empty state cannot say "there are no deals" ────────────────────────
 *
 * §3.4 is scoped and `Team`, `Out` and `Asgn` resolve to **zero rows** (Point
 * 2.1), so a Team Leader is answered with an authenticated 200 and an empty
 * page. "None are visible to you" is true whether the table is empty or the
 * scope is unbacked; "there are no deals" would be a lie in the second case.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 1,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

const DEAL = {
    id: 'd1',
    code: 'DL-2026-0001',
    customer_id: 'c1',
    title: 'Twelve pumps',
    source: 'employee_entry',
    service_type: 'product',
    status: 'lead',
    owner_id: 'u9',
    approval_status: 'pending',
    rejection_reason: null,
    lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00',
    updated_at: '2026-09-08T09:00:00+00:00',
};

/** §4.5's own criterion: one customer, two independent deals, separate statuses. */
const SECOND_DEAL_SAME_CUSTOMER = {
    ...DEAL,
    id: 'd2',
    code: 'DL-2026-0002',
    title: 'Spare seals',
    status: 'negotiations',
    approval_status: null,
};

const CUSTOMER = {
    id: 'c1',
    name: 'Acme Industrial',
    customer_status: 'prospect',
    sector: null,
    region: null,
    contact_person: null,
    phone: null,
    phone2: null,
    whatsapp: null,
    email: null,
    sales_owner_id: null,
    start_date: null,
    notes: null,
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-08-01T00:00:00+00:00',
    updated_at: '2026-08-01T00:00:00+00:00',
};

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.view.all', 'customer.view.all'],
    is_active: true,
    unconditional_access: false,
};

/**
 * §3.4 grants the CEO `deal.view` as `All` while §8 lists no Deals screen for
 * them — the conflict the owner ruled on for Suppliers, Catalog and Supplier
 * Quotations on 2026-08-31. The matrix wins, so this profile must reach the
 * screen exactly as the Manager does.
 */
const CEO: AuthenticatedUser = {
    ...USER,
    id: 'u2',
    name: 'Test CEO',
    email: 'ceo@example.test',
    role: { id: 'r2', slug: 'ceo', name: 'CEO' },
    permissions: ['deal.view.all'],
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(deals: unknown = [DEAL], status = 200, pagination = PAGINATION): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/customers')) {
            return json(200, envelope([CUSTOMER], { pagination: { ...PAGINATION, per_page: 100 } }));
        }

        return json(
            status,
            status === 200
                ? envelope(deals, { pagination })
                : { error: { code: 'forbidden' }, meta: { request_id: 'r1' } },
        );
    });
}

async function signIn(profile: AuthenticatedUser, delegate: typeof globalThis.fetch): Promise<void> {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = USER) {
    await signIn(profile, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(DealsView, { global: { plugins: [i18n, createAppRouter()] } });

    await flushPromises();

    return wrapper;
}

/** The last URL the *deals* list was asked for — not the customers call. */
function dealsUrl(fetchMock: ReturnType<typeof vi.fn>): string {
    const call = [...fetchMock.mock.calls]
        .reverse()
        .find((c) => String(c[0]).includes('/deals'));

    return String(call?.[0] ?? '');
}

function listReads(fetchMock: ReturnType<typeof vi.fn>): number {
    return fetchMock.mock.calls.filter((call) => String(call[0]).includes('/deals')).length;
}

describe('the deals screen', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ─────────────────────────────────────────────────────────────── on arrival

    it('asks the server for the first page with the documented default sort', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        const url = dealsUrl(fetchMock);

        expect(url).toContain('/deals');
        // `DealListCriteria::DEFAULT_SORT` is `last_activity_at`, descending —
        // "what needs attention" is the newest activity first.
        expect(url).toContain('sort=-last_activity_at');
        expect(wrapper.find('[data-testid="deals-table"]').exists()).toBe(true);
    });

    it('shows the code the server allocated, digit for digit', async () => {
        const wrapper = await render(respond());

        // §4.7's acceptance criterion: `DL-2026-0001`. The SPA never builds a
        // code — `SaveDealRequest` prohibits the field outright.
        expect(wrapper.find('[data-testid="deals-code"]').text()).toBe('DL-2026-0001');
    });

    it('draws two deals for one customer as two rows with separate statuses', async () => {
        // §4.5's criterion, at the screen: "customer with an active deal + new
        // request → two independent deals, separate statuses".
        const wrapper = await render(respond([DEAL, SECOND_DEAL_SAME_CUSTOMER]));

        const rows = wrapper.findAll('[data-testid="deals-row"]');
        const statuses = wrapper.findAll('[data-testid="deals-status"]').map((s) => s.text());
        const customers = wrapper.findAll('[data-testid="deals-customer"]').map((c) => c.text());

        expect(rows).toHaveLength(2);
        expect(statuses).toEqual(['Lead', 'Negotiations']);
        // One customer, named once per row — the rows are independent of each
        // other and not grouped or merged.
        expect(customers).toEqual(['Acme Industrial', 'Acme Industrial']);
    });

    it('resolves the customer to a name and leaves the owner as an identifier', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deals-customer"]').text()).toBe('Acme Industrial');
        // ⚠️ Identity publishes no list this module may resolve a name against,
        // so the owner is an id. A bare identifier is honest; a made-up name
        // would not be.
        expect(wrapper.find('[data-testid="deals-owner"]').text()).toBe('u9');
    });

    it('falls back to the identifier for a customer past the hundredth', async () => {
        // The names come from one `listCustomers({ perPage: 100 })` —
        // `MAX_PER_PAGE`, the same measured ceiling Module 6 recorded.
        const wrapper = await render(respond([{ ...DEAL, customer_id: 'c-not-in-first-100' }]));

        expect(wrapper.find('[data-testid="deals-customer"]').text()).toBe('c-not-in-first-100');
    });

    it('renders a stored code through the dictionary, never a server label', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deals-status"]').text()).toBe('Lead');
        expect(wrapper.find('[data-testid="deals-approval"]').text()).toBe('Pending approval');
    });

    it('draws a never-submitted deal as neither pending nor approved', async () => {
        // Flow 1: `approval_status` null is "never submitted", which is a
        // different fact from "pending" and must not be drawn as one.
        const wrapper = await render(respond([{ ...DEAL, approval_status: null }]));

        expect(wrapper.find('[data-testid="deals-approval"]').text()).toBe('—');
    });

    // ─────────────────────────────────────────────────────────── the four states

    it('draws a refusal for a 403 rather than an empty list', async () => {
        // `SEC-09`: an empty list would read as "there are no deals", which is
        // a different answer from "you may not ask".
        const wrapper = await render(respond([], 403));

        expect(wrapper.find('[data-testid="deals-table"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);
        expect(wrapper.html()).toContain('permission-denied');
    });

    it('draws a fault for a 500, which is not a boundary', async () => {
        const wrapper = await render(respond([], 500));

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(wrapper.html()).not.toContain('permission-denied');
    });

    it('says none are visible rather than that none exist', async () => {
        // A Team Leader holding `Team` reaches no row at all (Point 2.1), and
        // the screen cannot tell that case from an empty table.
        const wrapper = await render(respond([]));

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No deals are visible to you');
        expect(wrapper.text()).not.toContain('There are no deals');
    });

    it('says something different when a filter is the reason the page is empty', async () => {
        const fetchMock = respond([]);
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="deals-filter-status"]').setValue('won');
        await flushPromises();

        expect(wrapper.text()).toContain('Nothing matches this filter');
    });

    // ───────────────────────────────────────────────────── the declared surface

    it('sends each of the four filters under the server’s own name', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="deals-filter-status"]').setValue('negotiations');
        await flushPromises();
        await wrapper.find('[data-testid="deals-filter-approval"]').setValue('pending');
        await flushPromises();
        await wrapper.find('[data-testid="deals-filter-service-type"]').setValue('product');
        await flushPromises();
        await wrapper.find('[data-testid="deals-filter-source"]').setValue('outdoor_visit');
        await flushPromises();

        const url = dealsUrl(fetchMock);

        expect(url).toContain('filter%5Bstatus%5D=negotiations');
        expect(url).toContain('filter%5Bapproval_status%5D=pending');
        expect(url).toContain('filter%5Bservice_type%5D=product');
        expect(url).toContain('filter%5Bsource%5D=outdoor_visit');
    });

    it('does offer a search, and sends it as q', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        const search = wrapper.find('[data-testid="deals-filter-search"]');
        expect(search.exists()).toBe(true);

        await search.setValue('pumps');
        await wrapper.find('[data-testid="deals-filters"]').trigger('submit');
        await flushPromises();

        // The one place this screen differs from Module 6's list: `q` has a
        // server behind it here.
        expect(dealsUrl(fetchMock)).toContain('q=pumps');
    });

    it('asks again rather than narrowing the rows in hand', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);
        const before = listReads(fetchMock);

        await wrapper.find('[data-testid="deals-filter-status"]').setValue('won');
        await flushPromises();

        expect(listReads(fetchMock)).toBe(before + 1);
    });

    it('returns to the first page whenever the question changes', async () => {
        const fetchMock = respond([DEAL], 200, { ...PAGINATION, total: 60, total_pages: 3, has_next_page: true });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="deals-next"]').trigger('click');
        await flushPromises();
        expect(dealsUrl(fetchMock)).toContain('page=2');

        await wrapper.find('[data-testid="deals-filter-status"]').setValue('won');
        await flushPromises();

        // A page number is an answer to the previous question.
        expect(dealsUrl(fetchMock)).not.toContain('page=2');
    });

    it('sorts by a declared field and flips direction on a second click', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="deals-sort-code"]').trigger('click');
        await flushPromises();
        expect(dealsUrl(fetchMock)).toContain('sort=-code');

        await wrapper.find('[data-testid="deals-sort-code"]').trigger('click');
        await flushPromises();
        expect(dealsUrl(fetchMock)).toContain('sort=code');
    });

    it('announces the sorted column to a screen reader', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="deals-column-last_activity_at"]').attributes('aria-sort')).toBe('descending');
        expect(wrapper.find('[data-testid="deals-column-code"]').attributes('aria-sort')).toBe('none');
    });

    it('pages through the server’s own numbers', async () => {
        const fetchMock = respond([DEAL], 200, { ...PAGINATION, total: 60, total_pages: 3, has_next_page: true });
        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="deals-previous"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="deals-next"]').trigger('click');
        await flushPromises();

        expect(dealsUrl(fetchMock)).toContain('page=2');
    });

    // ─────────────────────────────────────────────── the route and the nav item

    it('is reachable by the CEO, whom §8 omits and §3.4 grants', async () => {
        // The owner's 2026-08-31 ruling: route and nav follow the matrix, so a
        // screen a person may open is never left unreachable.
        const wrapper = await render(respond(), CEO);

        expect(wrapper.find('[data-testid="deals-table"]').exists()).toBe(true);
    });

    it('keys the nav item on the same permission the route requires', async () => {
        const item = NAVIGATION.flatMap((group) => group.items).find((i) => i.name === 'deals');
        const route = createAppRouter().getRoutes().find((r) => r.name === 'deals');

        expect(item?.permission).toBe('deal.view');
        // `navigation.spec.ts` pins this pair across every route; asserted here
        // too because a mismatch is what leaves a screen unreachable.
        expect(route?.meta.requiredPermission).toBe('deal.view');
    });

    it('carries no badge, because nothing counts anything yet', async () => {
        const item = NAVIGATION.flatMap((group) => group.items).find((i) => i.name === 'deals');

        // §5.1 permits a badge on Requests — and `navigation.ts` says nothing
        // counts anything. A counter here would be a number this application
        // cannot produce.
        expect(item?.badge).toBeUndefined();
    });
});
