import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CustomerFollowUpsSection from '@/components/customers/CustomerFollowUpsSection.vue';

/**
 * `D-133` (Module 13, slice 3 · 3.3) — a customer's «المتابعات»: the follow-ups of
 * each submitted daily report, read-only, from `GET /customers/{id}/follow-ups`.
 * Each row shows its date, author and note, in the server's order (newest
 * first); this section never re-sorts it and writes nothing.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const NEWER = { note: 'Asked for a revised offer.', author_name: 'Mona Adel', followed_up_at: '2026-10-15T09:05:00Z' };
const OLDER = { note: 'First call; sent the catalogue.', author_name: 'Omar Said', followed_up_at: '2026-10-14T16:30:00Z' };

function stub(handler: (url: string) => Response): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string) => handler(String(url)));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function listOf(...rows: unknown[]): Response {
    return json(200, { data: rows, meta: {} });
}

function render(locale = 'en') {
    return mount(CustomerFollowUpsSection, {
        props: { customerId: 'c1' },
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CustomerFollowUpsSection — D-133', () => {
    /** `D-133`: read from its own route; date, author and note per row, newest first as the server sends them. */
    it('reads the follow-ups and shows each with its note, author and date, in the server order', async () => {
        const fetchMock = stub(() => listOf(NEWER, OLDER));

        const view = render();
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]?.[0])).toMatch(/\/customers\/c1\/follow-ups(\?|$)/);

        const rows = view.findAll('[data-testid="customer-follow-up-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain(NEWER.note);
        expect(rows[0]?.text()).toContain(NEWER.author_name);
        expect(rows[0]?.find('time').attributes('datetime')).toBe(NEWER.followed_up_at);
        expect(rows[1]?.text()).toContain(OLDER.note);
        expect(rows[1]?.text()).toContain(OLDER.author_name);
        expect(rows[1]?.find('time').attributes('datetime')).toBe(OLDER.followed_up_at);
    });

    /** `D-133`: read-only — nothing to type into and no button but Retry. */
    it('is read-only: no box and no button', async () => {
        stub(() => listOf(NEWER));

        const view = render();
        await flushPromises();

        expect(view.find('textarea').exists()).toBe(false);
        expect(view.find('input').exists()).toBe(false);
        expect(view.find('button').exists()).toBe(false);
    });

    /** `D-133`: a customer with no submitted follow-up says so. */
    it('says so when there are none', async () => {
        stub(() => listOf());

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customer-follow-ups-empty"]').exists()).toBe(true);
        expect(view.find('[data-testid="customer-follow-ups-empty"]').text()).toContain(en.customers.followUps.empty);
    });

    /** `D-133`: a failed read shows the error state, and Retry reads again. */
    it('shows the error state on a failure and reads again on Retry', async () => {
        let fail = true;
        const fetchMock = stub(() => (fail ? json(500, { error: { code: 'x', message: 'no' } }) : listOf(NEWER)));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customer-follow-ups-error"]').exists()).toBe(true);

        fail = false;
        await view.find('[data-testid="customer-follow-ups-error"] button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(view.findAll('[data-testid="customer-follow-up-row"]')).toHaveLength(1);
    });

    /** `D-133`: the section's heading. */
    it('draws its heading in English', async () => {
        stub(() => listOf());

        const view = render();
        await flushPromises();

        expect(view.text()).toContain(en.customers.followUps.title);
    });

    /** `D-133`: «المتابعات» on an Arabic page, with the rows still drawn. */
    it('draws its heading and rows in Arabic on an Arabic page', async () => {
        stub(() => listOf(NEWER));

        const view = render('ar');
        await flushPromises();

        expect(view.text()).toContain(ar.customers.followUps.title);
        expect(view.findAll('[data-testid="customer-follow-up-row"]')).toHaveLength(1);
        expect(view.text()).toContain(NEWER.note);
    });
});
