import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationsView from '@/pages/quotations/QuotationsView.vue';
import { NAVIGATION } from '@/navigation';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 7, Point 6.3 — §6.6's list, flat. The toggle and the split are 6.4.
 *
 * Every control changes a parameter and asks the server again (`Design System
 * §5.2` "server-side filters/sort", `§6.5` "Every list is server-paginated"),
 * so the assertions read the URL rather than the rows. The declared surface is
 * `QuotationListCriteria`'s; the amount pair is sent only with a currency
 * (Step 5 Q3 — the server answers the pair alone with a 400).
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

const QUOTATION = {
    id: 'q1',
    code: 'QT-2026-0001',
    status: 'draft',
    customer_id: 'c1',
    deal_id: 'd1',
    currency_id: 'cur-egp',
    currency: 'EGP',
    final_total: '1235.000000',
    quotation_date: '2026-09-13',
    valid_until: null,
    submitted_at: null,
    version: 1,
    parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00',
    updated_at: '2026-09-13T09:00:00+00:00',
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
    permissions: ['quotation.view.all', 'customer.view.all'],
    is_active: true,
    unconditional_access: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(quotations: unknown = [QUOTATION], status = 200, pagination = PAGINATION): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/customers')) {
            return json(200, envelope([CUSTOMER], { pagination: { ...PAGINATION, per_page: 100 } }));
        }

        return json(
            status,
            status === 200
                ? envelope(quotations, { pagination })
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

async function render(fetchMock: ReturnType<typeof vi.fn>, locale: 'en' | 'ar' = 'en') {
    await signIn(USER, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(QuotationsView, { global: { plugins: [i18n, createAppRouter()] } });

    await flushPromises();

    return wrapper;
}

/** The last URL the *quotations* list was asked for — not the customers call. */
function listUrl(fetchMock: ReturnType<typeof vi.fn>): string {
    const call = [...fetchMock.mock.calls]
        .reverse()
        .find((c) => String(c[0]).includes('/quotations'));

    return String(call?.[0] ?? '');
}

function listReads(fetchMock: ReturnType<typeof vi.fn>): number {
    return fetchMock.mock.calls.filter((call) => String(call[0]).includes('/quotations')).length;
}

describe('the quotations screen', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ─────────────────────────────────────────────────────────────── on arrival

    it('asks the server for the first page with the documented default sort', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        const url = listUrl(fetchMock);

        expect(url).toContain('/quotations');
        // `QuotationListCriteria::DEFAULT_SORT` — `-updated_at` (Step 5 Q6).
        expect(url).toContain('sort=-updated_at');
        expect(url).not.toContain('group_by');
        expect(wrapper.find('[data-testid="quotations-table"]').exists()).toBe(true);
        // The count is the server's `meta.pagination.total`, never the rows in hand.
        expect(wrapper.find('[data-testid="quotations-count"]').text()).toBe('1 quotations');
    });

    it('shows the code, the version and the total paired with its currency', async () => {
        const wrapper = await render(respond());

        // §4.7's `QT-2026-0001`, as allocated; `SaveQuotationRequest` prohibits `code`.
        expect(wrapper.find('[data-testid="quotations-code"]').text()).toBe('QT-2026-0001');
        expect(wrapper.find('[data-testid="quotations-version"]').text()).toBe('v1');
        // `Design System §6.3`: "Keep amount and currency visibly paired". The
        // figure is the server's string, digit for digit — never a `Number`.
        expect(wrapper.find('[data-testid="quotations-total"]').text()).toBe('1235.000000 EGP');
    });

    it('resolves the customer to a name, and falls back to the identifier', async () => {
        const named = await render(respond());
        expect(named.find('[data-testid="quotations-customer"]').text()).toBe('Acme Industrial');

        const unnamed = await render(respond([{ ...QUOTATION, customer_id: 'c-not-in-first-100' }]));
        expect(unnamed.find('[data-testid="quotations-customer"]').text()).toBe('c-not-in-first-100');
    });

    it('draws the status as a word with an icon beside it, through the dictionary', async () => {
        const wrapper = await render(respond([{ ...QUOTATION, status: 'pending' }]));

        const chip = wrapper.find('[data-testid="quotation-status"]');

        // `Design System §6.4`: "never color alone" — the word is the meaning,
        // the icon is the glance; the class names the tone the token paints.
        expect(chip.text()).toBe('Pending approval');
        expect(chip.classes()).toContain('status-chip--warning');
        expect(chip.find('[data-testid="quotation-status-dot"]').exists()).toBe(true);
    });

    it('maps the nine statuses onto §6.4’s four tones', async () => {
        const wrapper = await render(
            respond(
                ['draft', 'pending', 'approved', 'sent', 'accepted', 'partial', 'counter', 'rejected', 'expired'].map(
                    (status, index) => ({ ...QUOTATION, id: `q${index}`, status }),
                ),
            ),
        );

        const tones = wrapper
            .findAll('[data-testid="quotation-status"]')
            .map((chip) => chip.classes().find((name) => name.startsWith('status-chip--')));

        expect(tones).toEqual([
            'status-chip--info', // draft
            'status-chip--warning', // pending
            'status-chip--success', // approved
            'status-chip--info', // sent
            'status-chip--success', // accepted
            'status-chip--info', // partial
            'status-chip--info', // counter
            'status-chip--danger', // rejected
            'status-chip--warning', // expired
        ]);
    });

    it('reads in Arabic through the same keys', async () => {
        const wrapper = await render(respond(), 'ar');

        expect(wrapper.find('h1').text()).toBe('عروض الأسعار');
        expect(wrapper.find('[data-testid="quotation-status"]').text()).toBe('مسودة');
    });

    // ─────────────────────────────────────────────────────────── the four states

    it('draws a refusal for a 403 rather than an empty list', async () => {
        const wrapper = await render(respond([], 403));

        expect(wrapper.find('[data-testid="quotations-table"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);
        expect(wrapper.html()).toContain('permission-denied');
    });

    it('draws a fault for a 500, which is not a boundary', async () => {
        const wrapper = await render(respond([], 500));

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(wrapper.html()).not.toContain('permission-denied');
    });

    it('says none are visible rather than that none exist', async () => {
        // A Team Leader holds `quotation.view` as `Team` and reaches no row
        // (Step 5's fail-closed scope); the screen cannot tell that from an
        // empty table and does not pretend to.
        const wrapper = await render(respond([]));

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No quotations are visible to you');
    });

    it('says something different when a filter is the reason the page is empty', async () => {
        const wrapper = await render(respond([]));

        await wrapper.find('[data-testid="quotations-filter-status"]').setValue('approved');
        await flushPromises();

        expect(wrapper.text()).toContain('Nothing matches this filter');
    });

    // ───────────────────────────────────────────────────── the declared surface

    it('sends each filter under the server’s own name, and the amount pair only with a currency', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        // Without a currency the amount inputs are disabled: the server
        // answers `amount_min` alone with a 400 (Step 5 Q3), and a control
        // that can only produce an error is not a control.
        expect(wrapper.find('[data-testid="quotations-filter-amount-min"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="quotations-amount-hint"]').exists()).toBe(true);

        await wrapper.find('[data-testid="quotations-filter-status"]').setValue('pending');
        await wrapper.find('[data-testid="quotations-filter-customer"]').setValue('c1');
        await wrapper.find('[data-testid="quotations-filter-from"]').setValue('2026-09-01');
        await wrapper.find('[data-testid="quotations-filter-to"]').setValue('2026-09-30');
        await wrapper.find('[data-testid="quotations-filter-currency"]').setValue('egp');
        await flushPromises();

        expect(wrapper.find('[data-testid="quotations-filter-amount-min"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-testid="quotations-amount-hint"]').exists()).toBe(false);

        await wrapper.find('[data-testid="quotations-filter-amount-min"]').setValue('100');
        await wrapper.find('[data-testid="quotations-filter-amount-max"]').setValue('5000');
        await wrapper.find('[data-testid="quotations-filters"]').trigger('submit');
        await flushPromises();

        const url = listUrl(fetchMock);

        expect(url).toContain('filter%5Bstatus%5D=pending');
        expect(url).toContain('filter%5Bcustomer_id%5D=c1');
        expect(url).toContain('filter%5Bfrom%5D=2026-09-01');
        expect(url).toContain('filter%5Bto%5D=2026-09-30');
        // The code goes up as the server stores it (`CurrencyCode`), however it was typed.
        expect(url).toContain('filter%5Bcurrency%5D=EGP');
        expect(url).toContain('filter%5Bamount_min%5D=100');
        expect(url).toContain('filter%5Bamount_max%5D=5000');
    });

    it('drops the amount pair when the currency is cleared', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="quotations-filter-currency"]').setValue('EGP');
        await wrapper.find('[data-testid="quotations-filter-amount-min"]').setValue('100');
        await wrapper.find('[data-testid="quotations-filters"]').trigger('submit');
        await flushPromises();
        expect(listUrl(fetchMock)).toContain('amount_min');

        await wrapper.find('[data-testid="quotations-filter-currency"]').setValue('');
        await wrapper.find('[data-testid="quotations-filters"]').trigger('submit');
        await flushPromises();

        expect(listUrl(fetchMock)).not.toContain('amount_min');
        expect(listUrl(fetchMock)).not.toContain('currency');
    });

    it('asks again rather than narrowing the rows in hand', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);
        const before = listReads(fetchMock);

        await wrapper.find('[data-testid="quotations-filter-status"]').setValue('sent');
        await flushPromises();

        expect(listReads(fetchMock)).toBe(before + 1);
    });

    it('returns to the first page whenever the question changes', async () => {
        const fetchMock = respond([QUOTATION], 200, { ...PAGINATION, total: 60, total_pages: 3, has_next_page: true });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="quotations-next"]').trigger('click');
        await flushPromises();
        expect(listUrl(fetchMock)).toContain('page=2');

        await wrapper.find('[data-testid="quotations-filter-status"]').setValue('sent');
        await flushPromises();

        expect(listUrl(fetchMock)).not.toContain('page=2');
    });

    it('sorts by a declared field and flips direction on a second click', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="quotations-sort-code"]').trigger('click');
        await flushPromises();
        expect(listUrl(fetchMock)).toContain('sort=-code');

        await wrapper.find('[data-testid="quotations-sort-code"]').trigger('click');
        await flushPromises();
        expect(listUrl(fetchMock)).toContain('sort=code');

        expect(wrapper.find('[data-testid="quotations-column-code"]').attributes('aria-sort')).toBe('ascending');
        expect(wrapper.find('[data-testid="quotations-column-updated_at"]').attributes('aria-sort')).toBe('none');
    });

    it('sorts by the total only when a currency narrows the page to one', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        // `final_total` across currencies ranks EGP against USD, so the server
        // refuses the sort without `filter[currency]` (Step 5 Q3). The header
        // is a button only once a currency is set.
        expect(wrapper.find('[data-testid="quotations-sort-final_total"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="quotations-filter-currency"]').setValue('EGP');
        await wrapper.find('[data-testid="quotations-filters"]').trigger('submit');
        await flushPromises();

        await wrapper.find('[data-testid="quotations-sort-final_total"]').trigger('click');
        await flushPromises();

        expect(listUrl(fetchMock)).toContain('sort=-final_total');
        expect(listUrl(fetchMock)).toContain('filter%5Bcurrency%5D=EGP');
    });

    it('pages through the server’s own numbers', async () => {
        const fetchMock = respond([QUOTATION], 200, { ...PAGINATION, total: 60, total_pages: 3, has_next_page: true });
        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="quotations-previous"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="quotations-pagination"]').text()).toContain('Page 1 of 3');

        await wrapper.find('[data-testid="quotations-next"]').trigger('click');
        await flushPromises();

        expect(listUrl(fetchMock)).toContain('page=2');
    });

    // ──────────────────────────────────────────────────────────── the way in

    it('is reachable from the sidebar on the permission the route requires', () => {
        const item = NAVIGATION.flatMap((group) => group.items).find((entry) => entry.name === 'quotations');

        expect(item).toBeDefined();
        expect(item?.permission).toBe('quotation.view');
        // §5.1 permits a badge on My Quotations; nothing counts anything until Module 8.
        expect(item?.badge).toBeUndefined();
    });
});
