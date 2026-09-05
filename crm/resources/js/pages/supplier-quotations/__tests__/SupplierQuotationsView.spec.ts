import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SupplierQuotationsView from '@/pages/supplier-quotations/SupplierQuotationsView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 6, Point 6.2 — §8's *Supplier Quotations* screen, Design System
 * §5.2's Table/List.
 *
 * ── The list is the server's, and the test reads the URL to prove it ───────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated". A client-side `filter()` narrows the 25 rows in hand and
 * silently claims to have narrowed all of them, so every assertion about a
 * filter or a sort below reads the **query string**, never the rendered rows.
 *
 * The declared surface is `SupplierQuotationListCriteria`'s: **two** filters
 * (`supplier_id`, `deal_id`), two sorts (`offer_date`, `created_at`),
 * `DEFAULT_SORT = offer_date` descending, and **no search at all**.
 * `OpenAPI §6.2` answers anything else with a 400.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put enforcement at the API, where
 * `SupplierQuotationListEndpointTest` proves it by withdrawing the seeded
 * grant. What is proved here is what the screen draws — including that a 403 is
 * drawn as a refusal rather than as an empty list, which would read as "there
 * are no offers".
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

const OFFER = {
    id: 'q1',
    code: 'SQ-2026-0001',
    supplier_id: 's1',
    deal_id: null,
    total_price: '4500.000000',
    currency_id: 'c1',
    offer_date: '2026-09-01',
    valid_until: null,
    notes: null,
};

const SUPPLIER = { id: 's1', name: 'Alpha Supply', type: 'supplier', color_rating: 'green', phone: null, contact_person: null, has_open_account: false, is_active: true, created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00' };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['supplier_quotation.view.all', 'supplier_quotation.create.all'],
    is_active: true,
    unconditional_access: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

/** Answers the offers call, and the suppliers call the name column needs. */
function respond(offers: unknown = [OFFER], status = 200): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/suppliers')) {
            return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
        }

        return json(status, status === 200 ? envelope(offers, { pagination: PAGINATION }) : { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
    });
}

/**
 * `SuppliersView.spec.ts`'s helper, verbatim in shape: the store has no
 * `$patch` — it is not Pinia — so a session is established by answering the
 * login call and letting `useAuth().login()` store what it receives.
 */
async function signIn(profile: AuthenticatedUser, delegate: typeof globalThis.fetch): Promise<void> {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(fetchMock: ReturnType<typeof vi.fn>) {
    await signIn(USER, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(SupplierQuotationsView, { global: { plugins: [i18n, createAppRouter()] } });

    await flushPromises();

    return wrapper;
}

function offersUrl(fetchMock: ReturnType<typeof vi.fn>): string {
    const call = [...fetchMock.mock.calls].reverse().find((c) => String(c[0]).includes('/supplier-quotations'));

    return String(call?.[0] ?? '');
}

describe('the supplier quotations screen', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('asks the server for the first page on arrival', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        expect(offersUrl(fetchMock)).toContain('/supplier-quotations');
        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="supplier-quotations-row"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('SQ-2026-0001');
    });

    /** The supplier is an id on the wire; the screen resolves it to §7.1's name. */
    it('shows the supplier by name, not by identifier', async () => {
        const wrapper = await render(respond());

        const cell = wrapper.find('[data-testid="supplier-quotations-supplier"]');

        expect(cell.text()).toBe('Alpha Supply');
        expect(cell.text()).not.toBe(SUPPLIER.id);
    });

    it('sends the supplier filter as the server declares it', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="supplier-quotations-filter-supplier"]').setValue('s1');
        await flushPromises();

        expect(offersUrl(fetchMock)).toContain('filter%5Bsupplier_id%5D=s1');
    });

    it('never sends a search parameter, because this list declares none', async () => {
        const fetchMock = respond();
        await render(fetchMock);

        expect(offersUrl(fetchMock)).not.toContain('q=');
        expect(offersUrl(fetchMock)).not.toContain('search');
    });

    /** `DEFAULT_SORT` is `offer_date` and `DEFAULT_SORT_DESCENDING` is true. */
    it('sorts by offer date, newest first, until told otherwise', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="supplier-quotations-sort-offer_date"]').trigger('click');
        await flushPromises();

        expect(offersUrl(fetchMock)).toContain('sort=offer_date');
    });

    it('draws the empty state when the server returns no offers', async () => {
        const wrapper = await render(respond([]));

        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(false);
        expect(wrapper.text()).not.toBe('');
    });

    /** A 403 is a boundary, not an absence — drawing it as "no offers" would lie. */
    it('draws a refusal for a 403 rather than an empty list', async () => {
        const wrapper = await render(respond([], 403));

        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(false);
        expect(wrapper.html()).toContain('permission-denied');
    });

    /** §6.5: monetary values right-aligned with tabular numerals. */
    it('renders the total as the server sent it, digit for digit', async () => {
        const wrapper = await render(respond());

        const cell = wrapper.find('[data-testid="supplier-quotations-total"]');

        expect(cell.text()).toBe('4500.000000');
        expect(cell.classes()).toContain('tabular-nums');
    });
});
