import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
    // F-07 · 1.5 (`D-83`): the server's name, deliberately not the customers
    // list's `Acme Industrial` — a screen that still joins shows the wrong one.
    customer_name: 'Acme Industrial Ltd',
    title: 'Twelve pumps',
    details: 'Site visit on Tuesday.\nTwo floors, twelve pumps, one of them on the roof.',
    source: 'employee_entry',
    service_type: 'product',
    status: 'lead',
    owner_id: 'u9',
    // F-41 · 1.6b (`D-109`): the server's name for the owner.
    owner_name: 'Sara Haddad',
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
    details: null,
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
    permissions: ['deal.view.all', 'customer.view.all', 'deal.create.all', 'deal.edit.all'],
    is_active: true,
    unconditional_access: false,
};

/**
 * §3.4's documented split: Procurement holds `edit` as `Asgn` and has **no
 * `create` cell at all**. `create` and `edit` are two rows with different
 * columns, so one "may write" computed would draw a control this role cannot
 * complete.
 */
const EDITOR_WITHOUT_CREATE: AuthenticatedUser = {
    ...USER,
    id: 'u4',
    name: 'Test Procurement',
    email: 'procurement@example.test',
    role: { id: 'r4', slug: 'procurement', name: 'Procurement' },
    permissions: ['deal.view.asgn', 'deal.edit.asgn'],
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
    // §3.4 gives the CEO `view` as `All` and a dash under `create` and `edit`.
    permissions: ['deal.view.all'],
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(deals: unknown = [DEAL], status = 200, pagination = PAGINATION): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/customers')) {
            return json(200, envelope([CUSTOMER], { pagination: { ...PAGINATION, per_page: 20 } }));
        }

        // F-41 · 1.6 (`D-108`): the owner filter's choices, read beside the list.
        if (String(input).includes('/deals/owners')) {
            return json(200, envelope({ items: [] }));
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
        .find((c) => isListRead(String(c[0])));

    return String(call?.[0] ?? '');
}

function listReads(fetchMock: ReturnType<typeof vi.fn>): number {
    return fetchMock.mock.calls.filter((call) => isListRead(String(call[0]))).length;
}

/** A read of the list itself — not the owner filter's choices beside it. */
function isListRead(url: string): boolean {
    return url.includes('/deals') && !url.includes('/deals/owners');
}

/** Open the create form, focus its customer picker and take the row the server answered. */
async function openFormAndPickCustomer(wrapper: Awaited<ReturnType<typeof render>>): Promise<void> {
    await wrapper.find('[data-testid="deals-create"]').trigger('click');
    await flushPromises();
    await wrapper.find('[data-testid="deal-form-customer-id"]').trigger('focus');
    await flushPromises();
    await wrapper.find('[data-testid="deal-form-customer-id-option"]').trigger('mousedown');
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

    it('makes the code the way into the deal’s own page', async () => {
        const wrapper = await render(respond());

        const link = wrapper.find('[data-testid="deals-row-link"]');

        // A named route and never a built path, so a renamed route fails at
        // the router rather than silently producing a dead link.
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/deals/d1');
    });

    it('shows details shortened from lg, — when none (F-41 · 1.5, D-108)', async () => {
        const wrapper = await render(respond([DEAL, SECOND_DEAL_SAME_CUSTOMER]));
        const header = wrapper.find('[data-testid="deals-details-header"]');
        const [given, none] = wrapper.findAll('[data-testid="deals-details"]');

        expect(header.text()).toBe('Details');
        // happy-dom lays nothing out: the classes are what is checked here, and
        // the browser check proves the column hides below lg and clips.
        for (const cell of [header, given!]) {
            expect(cell.classes()).toEqual(expect.arrayContaining(['hidden', 'lg:table-cell']));
        }
        const trigger = given!.find('[data-testid="deals-details-trigger"]');

        expect(trigger.element.tagName).toBe('BUTTON');
        expect(trigger.find('span').classes()).toContain('truncate');
        expect(trigger.find('span').attributes('dir')).toBe('auto');
        expect(none!.text()).toBe('—');
        expect(none!.find('[data-testid="deals-details-trigger"]').exists()).toBe(false);
    });

    /**
     * F-41 · 1.5, the owner, 2026-10-11: hovering the shortened details shows the
     * whole text in a pop-up; a click keeps it open until the user clicks
     * elsewhere. The browser's own `popover="auto"` does the "elsewhere" (and
     * Escape); happy-dom has no Popover API, so the two calls are stubbed here
     * and the light dismiss is proved in the browser.
     */
    describe('the details pop-up', () => {
        let shown: ReturnType<typeof vi.fn>;
        let hidden: ReturnType<typeof vi.fn>;
        /** The browser's open state, which `:popover-open` reads; the real calls throw on the wrong one. */
        let open: WeakSet<Element>;
        const matches = Element.prototype.matches;

        beforeEach(() => {
            open = new WeakSet();
            // Like the browser: opening an open one, or closing a closed one, throws.
            shown = vi.fn(function (this: Element) {
                if (open.has(this)) throw new DOMException('already open', 'InvalidStateError');
                open.add(this);
            });
            hidden = vi.fn(function (this: Element) {
                if (!open.has(this)) throw new DOMException('not open', 'InvalidStateError');
                open.delete(this);
            });
            Object.assign(HTMLElement.prototype, { showPopover: shown, hidePopover: hidden });
            vi.spyOn(Element.prototype, 'matches').mockImplementation(function (this: Element, selector: string) {
                return selector === ':popover-open' ? open.has(this) : matches.call(this, selector);
            });
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        /** What the browser does on a click outside or Escape: closes, then says so. */
        function lightDismiss(popover: Element): void {
            open.delete(popover);
            popover.dispatchEvent(Object.assign(new Event('toggle'), { newState: 'closed' }));
        }

        it('holds the whole text, line breaks kept, as an auto popover', async () => {
            const wrapper = await render(respond());
            const popover = wrapper.find('[data-testid="deals-details-popover"]');

            expect(popover.attributes('popover')).toBe('auto');
            expect(popover.text()).toBe(DEAL.details.trim());
            expect(popover.classes()).toContain('whitespace-pre-line');
            expect(popover.attributes('dir')).toBe('auto');
        });

        it('places it from one edge only, whatever the last language left behind', async () => {
            const wrapper = await render(respond());
            const popover = wrapper.find('[data-testid="deals-details-popover"]').element as HTMLElement;

            // What an Arabic opening left before the switch to English (rtl-ui-verifier).
            popover.style.right = '527px';
            await wrapper.find('[data-testid="deals-details-trigger"]').trigger('mouseenter');

            expect(popover.style.right).toBe('auto');
        });

        it('opens on hover and closes when the pointer leaves', async () => {
            const wrapper = await render(respond());
            const trigger = wrapper.find('[data-testid="deals-details-trigger"]');

            await trigger.trigger('mouseenter');
            expect(shown).toHaveBeenCalledTimes(1);

            await trigger.trigger('mouseleave');
            expect(hidden).toHaveBeenCalledTimes(1);
        });

        it('stays open after a click when the pointer leaves', async () => {
            const wrapper = await render(respond());
            const trigger = wrapper.find('[data-testid="deals-details-trigger"]');

            await trigger.trigger('mouseenter');
            await trigger.trigger('click');
            await trigger.trigger('mouseleave');

            expect(hidden).not.toHaveBeenCalled();
        });

        it('opens on a click alone, for a keyboard or a touch with no hover', async () => {
            const wrapper = await render(respond());

            await wrapper.find('[data-testid="deals-details-trigger"]').trigger('click');

            expect(shown).toHaveBeenCalledTimes(1);
        });

        it('leaves quietly when Escape already closed it under the pointer', async () => {
            const wrapper = await render(respond());
            const trigger = wrapper.find('[data-testid="deals-details-trigger"]');

            await trigger.trigger('mouseenter');
            lightDismiss(wrapper.find('[data-testid="deals-details-popover"]').element);
            await trigger.trigger('mouseleave');

            // The browser throws on closing a closed popover.
            expect(hidden).not.toHaveBeenCalled();
        });

        it('forgets the click once the browser has dismissed it, so hover closes again', async () => {
            const wrapper = await render(respond());
            const trigger = wrapper.find('[data-testid="deals-details-trigger"]');

            await trigger.trigger('click');
            lightDismiss(wrapper.find('[data-testid="deals-details-popover"]').element);
            await trigger.trigger('mouseenter');
            await trigger.trigger('mouseleave');

            expect(hidden).toHaveBeenCalledTimes(1);
        });
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
        expect(customers).toEqual(['Acme Industrial Ltd', 'Acme Industrial Ltd']);
    });

    it('names the owner the server sent, never the identifier (F-41 · 1.6c, D-109)', async () => {
        const wrapper = await render(respond([DEAL, { ...SECOND_DEAL_SAME_CUSTOMER, owner_name: null }]));
        const owners = wrapper.findAll('[data-testid="deals-owner"]').map((o) => o.text());

        // `null` is the hidden Super Admin, a deleted account or no owner —
        // all drawn «—», and the `owner_id` the row still carries never shows.
        expect(owners).toEqual(['Sara Haddad', '—']);
    });

    it('puts the request under the customer, in one cell (D-109)', async () => {
        const wrapper = await render(respond([DEAL, SECOND_DEAL_SAME_CUSTOMER, { ...DEAL, id: 'd3', title: null }]));
        const cells = wrapper.findAll('[data-testid="deals-customer-cell"]');

        expect(cells).toHaveLength(3);
        expect(cells[0]!.find('[data-testid="deals-customer"]').text()).toBe('Acme Industrial Ltd');
        expect(cells.map((c) => c.find('[data-testid="deals-request"]').text())).toEqual(['Twelve pumps', 'Spare seals', '—']);
        // The name comes first in the cell, the request after it.
        expect(cells[0]!.text().indexOf('Acme Industrial Ltd')).toBeLessThan(cells[0]!.text().indexOf('Twelve pumps'));
        // No separate request column is left beside it.
        expect(wrapper.findAll('[data-testid="deals-table"] thead th').map((th) => th.text())).not.toContain('Request');
    });

    it('draws the status as a chip of its §6.4 tone, carrying its word (D-109)', async () => {
        const wrapper = await render(respond([
            { ...DEAL, id: 'a', status: 'won' },
            { ...DEAL, id: 'b', status: 'delivery_complete' },
            { ...DEAL, id: 'c', status: 'lost' },
            { ...DEAL, id: 'd', status: 'lead' },
            { ...DEAL, id: 'e', status: 'negotiations' },
        ]));
        const chips = wrapper.findAll('[data-testid="deals-status-chip"]');

        expect(chips.map((c) => c.text())).toEqual(['Won', 'Delivery complete', 'Lost', 'Lead', 'Negotiations']);
        expect(chips.map((c) => ['status-chip--success', 'status-chip--danger', 'status-chip--info'].find((tone) => c.classes().includes(tone))))
            .toEqual(['status-chip--success', 'status-chip--success', 'status-chip--danger', 'status-chip--info', 'status-chip--info']);
    });

    it('names a customer past the hundredth, because the name comes with the row', async () => {
        // `D-83`: the capped `listCustomers({ perPage: 100 })` no longer names
        // the rows, so a customer it never returns is still named.
        const wrapper = await render(respond([{ ...DEAL, customer_id: 'c-not-in-first-100', customer_name: 'Delta Steel' }]));

        expect(wrapper.find('[data-testid="deals-customer"]').text()).toBe('Delta Steel');
    });

    it('reads no customers on load — the form’s picker asks for its own (F-08 · 1.3, D-84)', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('/customers'))).toBe(false);

        await openFormAndPickCustomer(wrapper);

        expect((wrapper.find('[data-testid="deal-form-customer-id"]').element as HTMLInputElement).value).toBe('Acme Industrial');
        expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('per_page=100'))).toBe(false);
    });

    it('renders a stored code through the dictionary, never a server label', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deals-status"]').text()).toBe('Lead');
        // `toContain` and not `toBe`: as of Point 6.4 the cell holds §6.4's
        // badge, which carries an icon beside the word so the state is never
        // colour alone.
        expect(wrapper.find('[data-testid="deals-approval"]').text()).toContain('Pending approval');
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
        // A-48: the bar is drawn over rows, so the page starts with one and the filter empties it.
        const rows = respond() as unknown as (input: string) => Promise<Response>;
        const none = respond([]) as unknown as (input: string) => Promise<Response>;
        const fetchMock = vi.fn(async (input: string) => (String(input).includes('filter') ? none(input) : rows(input)));
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

    // ─────────────────────────── the owner filter — D-108, F-41 · 1.6

    /** `GET /deals/owners` answered beside the list; every other call is `respond()`'s. */
    function respondWithOwners(): ReturnType<typeof vi.fn> {
        const list = respond() as unknown as (input: string, init?: RequestInit) => Promise<Response>;

        return vi.fn(async (input: string, init?: RequestInit) =>
            String(input).includes('/deals/owners')
                ? json(200, envelope({ items: [{ id: 'u9', name: 'Sara Haddad' }] }))
                : list(input, init));
    }

    it('offers the owner filter to a deal.view.all user, from the server’s owners, and sends filter[owner]', async () => {
        const fetchMock = respondWithOwners();
        const wrapper = await render(fetchMock);

        const select = wrapper.find('[data-testid="deals-filter-owner"]');
        expect(select.exists()).toBe(true);
        expect(select.text()).toContain('Sara Haddad');

        await select.setValue('u9');
        await flushPromises();

        expect(dealsUrl(fetchMock)).toContain('filter%5Bowner%5D=u9');
    });

    it('neither draws the owner filter nor asks for owners without deal.view.all (D-108)', async () => {
        const fetchMock = respondWithOwners();
        const ownScope: AuthenticatedUser = {
            ...USER,
            id: 'u5',
            name: 'Test Indoor Sales',
            email: 'indoor@example.test',
            role: { id: 'r5', slug: 'indoor_sales', name: 'Indoor Sales' },
            permissions: ['deal.view.own', 'deal.create.own', 'deal.edit.own'],
        };
        const wrapper = await render(fetchMock, ownScope);

        expect(wrapper.find('[data-testid="deals-filter-owner"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some((call) => String(call[0]).includes('/deals/owners'))).toBe(false);
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

    // ────────────────────────────────────────────────────── the write controls

    it('draws create and edit for a role §3.4 grants both to', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deals-create"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deals-row-edit"]').exists()).toBe(true);
    });

    it('draws the row’s edit as a ghost action (§6.2, D-109)', async () => {
        const wrapper = await render(respond());
        const edit = wrapper.find('[data-testid="deals-row-edit"]');

        expect(edit.classes()).toContain('ghost-action');
        expect(edit.classes()).not.toContain('row-action');
    });

    it('draws neither for the CEO, whom §3.4 grants view and nothing else', async () => {
        const wrapper = await render(respond(), CEO);

        // The screen is still theirs to read — the refusal is of the controls,
        // not of the list.
        expect(wrapper.find('[data-testid="deals-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deals-create"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deals-row-edit"]').exists()).toBe(false);
    });

    it('draws edit without create for a role that holds only edit', async () => {
        // ⚠️ The case a single "may write" computed would get wrong: §3.4 gives
        // Procurement `edit` and no `create` cell.
        const wrapper = await render(respond(), EDITOR_WITHOUT_CREATE);

        expect(wrapper.find('[data-testid="deals-create"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deals-row-edit"]').exists()).toBe(true);
    });

    it('asks the list again after a save rather than patching the row', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);
        const before = listReads(fetchMock);

        await openFormAndPickCustomer(wrapper);
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // A write refreshes `last_activity_at`, which is the default sort, so
        // the saved row may not belong where it was.
        expect(listReads(fetchMock)).toBe(before + 2);
        expect(wrapper.find('[data-testid="deal-form-modal"]').exists()).toBe(false);
    });

    it('opens the dialog on the row it was asked to edit', async () => {
        const wrapper = await render(respond());

        await wrapper.find('[data-testid="deals-row-edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-modal"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Edit deal');
        // Create-only controls stay out of an edit.
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(false);
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

describe('the deals screen after F-41 (F-42 · P6)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    /** Mounted at a real address, so the query the screen writes and reads is the router's. */
    async function renderAt(fetchMock: ReturnType<typeof vi.fn>, path: string, profile: AuthenticatedUser = USER) {
        await signIn(profile, fetchMock as unknown as typeof globalThis.fetch);
        vi.stubGlobal('fetch', fetchMock);

        const router = createAppRouter();
        await router.push(path);
        await router.isReady();

        const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
        const wrapper = mount(DealsView, { global: { plugins: [i18n, router] }, attachTo: document.body });
        await flushPromises();

        return { wrapper, router };
    }

    it('folds the filters behind one button carrying the active-filter count (A-19)', async () => {
        const wrapper = await render(respond());
        const toggle = wrapper.get('[data-testid="deals-filters-toggle"]');
        const form = wrapper.get('[data-testid="deals-filters"]');

        expect(toggle.text()).toBe('Filters (0)');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.attributes('aria-controls')).toBe(form.attributes('id'));
        // Folded below 640 only: the desktop keeps the row of filters.
        expect(form.classes()).toEqual(expect.arrayContaining(['hidden', 'sm:flex']));

        await form.find('[data-testid="deals-filter-status"]').setValue('won');
        await flushPromises();
        await wrapper.get('[data-testid="deals-filters-toggle"]').trigger('click');

        expect(wrapper.get('[data-testid="deals-filters-toggle"]').text()).toBe('Filters (1)');
        expect(wrapper.get('[data-testid="deals-filters-toggle"]').attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="deals-filters"]').classes()).not.toContain('hidden');
    });

    it('keeps the code on one line (A-19)', async () => {
        const wrapper = await render(respond());

        expect(wrapper.get('[data-testid="deals-code"]').classes()).toContain('whitespace-nowrap');
    });

    it('names the details trigger with the short label, not the details text (A-58)', async () => {
        const wrapper = await render(respond());

        expect(wrapper.get('[data-testid="deals-details-trigger"]').attributes('aria-label')).toBe('Details');
    });

    it('gives every row with a manual move left its own button, with no shared id (A-29, D-119)', async () => {
        const changer: AuthenticatedUser = { ...USER, permissions: [...USER.permissions, 'deal.change_status.all'] };
        const wrapper = await render(respond([
            { ...DEAL, status: 'won' },
            { ...SECOND_DEAL_SAME_CUSTOMER, status: 'purchasing' },
        ]), changer);

        const buttons = wrapper.findAll('[data-testid="deal-status-next"]');
        expect(buttons.map((button) => button.text())).toEqual(['Move to Purchasing', 'Move to Delivery']);

        const ids = wrapper.findAll('[id]').map((node) => node.attributes('id'));
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('restores its filters from the address and writes changes back (A-13)', async () => {
        const fetchMock = respond();
        const { wrapper, router } = await renderAt(fetchMock, '/deals?status=won&q=pumps');

        expect(dealsUrl(fetchMock)).toContain('filter%5Bstatus%5D=won');
        expect(dealsUrl(fetchMock)).toContain('q=pumps');
        expect((wrapper.get('[data-testid="deals-filter-status"]').element as HTMLSelectElement).value).toBe('won');

        await wrapper.get('[data-testid="deals-filter-source"]').setValue('outdoor_visit');
        await flushPromises();

        expect(router.currentRoute.value.query).toMatchObject({ status: 'won', q: 'pumps', source: 'outdoor_visit' });
        wrapper.unmount();
    });

    it('keeps the table on a reload, busy and dimmed, with the focus on the pager (A-14)', async () => {
        let release: (value: Response) => void = () => {};
        let reads = 0;
        const fetchMock = vi.fn(async (input: string) => {
            if (String(input).includes('/deals/owners')) {
                return json(200, envelope({ items: [] }));
            }

            return reads++ === 0
                ? json(200, envelope([DEAL], { pagination: { ...PAGINATION, total: 60, total_pages: 3, has_next_page: true } }))
                : new Promise<Response>((resolve) => { release = resolve; });
        });
        const { wrapper } = await renderAt(fetchMock, '/deals');

        const next = wrapper.get('[data-testid="deals-next"]');
        (next.element as HTMLButtonElement).focus();
        await next.trigger('click');

        expect(wrapper.find('[data-testid="deals-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="loading-state"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="deals-table-frame"]').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('[data-testid="deals-updating"]').text()).toBe('Updating the list…');
        expect(document.activeElement).toBe(next.element);

        release(json(200, envelope([DEAL], { pagination: { ...PAGINATION, page: 2, total: 60, total_pages: 3, has_previous_page: true, has_next_page: true } })));
        await flushPromises();

        expect(wrapper.get('[data-testid="deals-table-frame"]').attributes('aria-busy')).toBe('false');
        wrapper.unmount();
    });

    it('draws no filter bar to a refused role, nor over an empty list with no filter (A-48)', async () => {
        const refused = await render(respond([], 403));
        expect(refused.find('[data-testid="deals-filters"]').exists()).toBe(false);
        expect(refused.find('[data-testid="deals-filters-toggle"]').exists()).toBe(false);

        const empty = await render(respond([]));
        expect(empty.find('[data-testid="deals-filters"]').exists()).toBe(false);
    });

    it('searches 300 ms after the last key, once, with no Apply button (A-49)', async () => {
        vi.useFakeTimers();
        const fetchMock = respond();
        const wrapper = await render(fetchMock);
        const before = listReads(fetchMock);

        expect(wrapper.find('[data-testid="deals-filter-apply"]').exists()).toBe(false);

        await wrapper.get('[data-testid="deals-filter-search"]').setValue('pum');
        vi.advanceTimersByTime(200);
        await wrapper.get('[data-testid="deals-filter-search"]').setValue('pumps');
        vi.advanceTimersByTime(299);
        await flushPromises();

        expect(listReads(fetchMock)).toBe(before);

        vi.advanceTimersByTime(1);
        await flushPromises();

        expect(listReads(fetchMock)).toBe(before + 1);
        expect(dealsUrl(fetchMock)).toContain('q=pumps');
        vi.useRealTimers();
    });

    it('draws no pager when there is one page (A-67)', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deals-pagination"]').exists()).toBe(false);
    });

    it('shows the last activity with Western digits in Arabic (A-06)', async () => {
        await signIn(USER, respond() as unknown as typeof globalThis.fetch);
        vi.stubGlobal('fetch', respond());

        const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { en, ar } });
        const wrapper = mount(DealsView, { global: { plugins: [i18n, createAppRouter()] } });
        await flushPromises();

        const activity = wrapper.get('[data-testid="deals-last-activity"]').text();
        expect(activity).toMatch(/\d/);
        expect(activity).not.toMatch(/[\u0660-\u0669]/);
    });

    it('says the save in a status region present from the start (A-32)', async () => {
        const saved = { ...DEAL, id: 'd3', code: 'DL-2026-0003' };
        const list = respond() as unknown as (input: string) => Promise<Response>;
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) =>
            init?.method === 'POST' ? json(201, envelope(saved)) : list(input));
        const wrapper = await render(fetchMock);

        expect(wrapper.get('[data-testid="deals-saved"]').attributes('role')).toBe('status');
        expect(wrapper.get('[data-testid="deals-saved"]').text()).toBe('');

        await openFormAndPickCustomer(wrapper);
        await wrapper.get('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="deals-saved"]').text()).toBe('DL-2026-0003 was saved.');
    });

    it('takes no owner from the address for a role that has no owner filter to show it (A-13, D-108)', async () => {
        const fetchMock = respond();
        const { wrapper } = await renderAt(fetchMock, '/deals?owner=u9', EDITOR_WITHOUT_CREATE);

        expect(dealsUrl(fetchMock)).not.toContain('owner');
        expect(wrapper.get('[data-testid="deals-filters-toggle"]').text()).toBe('Filters (0)');
        wrapper.unmount();
    });

    it('scrolls the table sideways below 640 and clips from 640, so nothing is cut off on a phone (A-40, owner 2026-10-14)', async () => {
        const wrapper = await render(respond());

        expect(wrapper.get('[data-testid="deals-table-frame"]').classes()).toEqual(expect.arrayContaining(['overflow-x-auto', 'sm:overflow-x-clip']));
    });
});
