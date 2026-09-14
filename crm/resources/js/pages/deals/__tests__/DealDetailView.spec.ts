import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealDetailView from '@/pages/deals/DealDetailView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.6 — Design System §5.2's Detail view.
 *
 * ── The criterion this closes ──────────────────────────────────────────────
 *
 * "Status change → timeline entry with old status, new status, who, when."
 * Point 1.1 declined a `deal_status_history` table because `audit_log` already
 * holds those four fields; 5.1 gave Audit a read port and 5.2 a route. This is
 * where a person finally sees them.
 *
 * ── Two permissions, two refusals, and they are not the same refusal ───────
 *
 * §3.4 gives `view` and `view timeline` separate rows, and the timeline has its
 * own route. So a caller who may read the deal and not its history sees the
 * summary with a refusal **inside the timeline section** — not an error page
 * over a deal that loaded perfectly well.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps',
    source: 'employee_entry', service_type: 'product', status: 'contacted', owner_id: 'u9',
    approval_status: 'pending', rejection_reason: null, lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00', updated_at: '2026-09-08T09:00:00+00:00',
};

const CUSTOMER = {
    id: 'c1', name: 'Acme Industrial', customer_status: 'prospect', sector: null, region: null,
    contact_person: null, phone: null, phone2: null, whatsapp: null, email: null,
    sales_owner_id: null, start_date: null, notes: null, is_archived: false, is_incomplete: false,
    created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00',
};

const STATUS_ENTRY = {
    id: 'a1', event: 'DEAL_STATUS_CHANGED', old_status: 'lead', new_status: 'contacted',
    actor_id: 'u9', impersonated_user_id: null, occurred_at: '2026-09-08T10:00:00+00:00',
};

const CREATED_ENTRY = {
    id: 'a2', event: 'DEAL_CREATED', old_status: null, new_status: 'lead',
    actor_id: 'u9', impersonated_user_id: null, occurred_at: '2026-09-08T09:00:00+00:00',
};

/** A row that changed no status at all — still part of the history. */
const UPDATED_ENTRY = {
    id: 'a3', event: 'DEAL_UPDATED', old_status: null, new_status: null,
    actor_id: 'u9', impersonated_user_id: null, occurred_at: '2026-09-08T11:00:00+00:00',
};

const PAGINATION = {
    page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false,
};

const USER: AuthenticatedUser = {
    id: 'u1', name: 'Test Manager', email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.view.all', 'deal.view_timeline.all', 'deal.approve.all', 'deal.change_status.all', 'customer.view.all'],
    is_active: true, unconditional_access: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(options: {
    deal?: unknown; dealStatus?: number; timeline?: unknown[]; timelineStatus?: number;
} = {}): ReturnType<typeof vi.fn> {
    const { deal = DEAL, dealStatus = 200, timeline = [STATUS_ENTRY], timelineStatus = 200 } = options;

    return vi.fn(async (input: string) => {
        const url = String(input);

        if (url.includes('/timeline')) {
            return timelineStatus === 200
                ? json(200, envelope(timeline, { pagination: { ...PAGINATION, total: timeline.length } }))
                : json(timelineStatus, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
        }

        if (url.includes('/customers')) {
            return json(200, envelope(CUSTOMER));
        }

        return dealStatus === 200
            ? json(200, envelope(deal))
            : json(dealStatus, { error: { code: 'not_found' }, meta: { request_id: 'r1' } });
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
    const router = createAppRouter();

    await router.push('/deals/d1');
    await router.isReady();

    const wrapper = mount(DealDetailView, { global: { plugins: [i18n, router] } });

    await flushPromises();

    return wrapper;
}

describe('the deal detail view', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ───────────────────────────────────────────────────────── §5.2: summary first

    it('opens with §4.3’s summary', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deal-detail-title"]').text()).toBe('DL-2026-0001');
        expect(wrapper.find('[data-testid="deal-detail-status"]').text()).toBe('Contacted');
        expect(wrapper.find('[data-testid="deal-detail-customer"]').text()).toBe('Acme Industrial');
    });

    it('falls back to the customer identifier when the name cannot be read', async () => {
        const fetchMock = vi.fn(async (input: string) => {
            const url = String(input);

            if (url.includes('/timeline')) {
                return json(200, envelope([], { pagination: PAGINATION }));
            }

            if (url.includes('/customers')) {
                // §3.3 gates customers separately — a caller may read the deal
                // and not the customer.
                return json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
            }

            return json(200, envelope(DEAL));
        });

        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="deal-detail-customer"]').text()).toBe('c1');
        // The page still loaded: a name lookup is not the page.
        expect(wrapper.find('[data-testid="deal-detail-summary"]').exists()).toBe(true);
    });

    // ───────────────────────────────────────────── the criterion: §4.4's four fields

    it('shows old status, new status, who and when', async () => {
        const wrapper = await render(respond());

        const entry = wrapper.find('[data-testid="deal-detail-timeline-entry"]');

        expect(entry.exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-detail-timeline-what"]').text()).toContain('Lead');
        expect(wrapper.find('[data-testid="deal-detail-timeline-what"]').text()).toContain('Contacted');
        expect(wrapper.find('[data-testid="deal-detail-timeline-who"]').text()).toBe('u9');
        expect(wrapper.find('[data-testid="deal-detail-timeline-when"]').text()).not.toBe('');
    });

    it('keeps an entry that changed no status rather than dropping it', async () => {
        const wrapper = await render(respond({ timeline: [UPDATED_ENTRY, CREATED_ENTRY] }));

        // §4.4 describes what must be *in* the timeline, not what to filter out
        // — a history with holes in it is not a history.
        expect(wrapper.findAll('[data-testid="deal-detail-timeline-entry"]')).toHaveLength(2);
    });

    it('draws a creation as a status being set, not as a change from nothing', async () => {
        const wrapper = await render(respond({ timeline: [CREATED_ENTRY] }));

        const what = wrapper.find('[data-testid="deal-detail-timeline-what"]').text();

        expect(what).toContain('Lead');
        // There was no previous status, so no arrow from one.
        expect(what).not.toContain('—');
    });

    it('names the impersonated account when there was one', async () => {
        const wrapper = await render(respond({
            timeline: [{ ...STATUS_ENTRY, impersonated_user_id: 'u42' }],
        }));

        // SEC-10: a history naming only the actor would read identically
        // whether or not somebody else's account was used.
        expect(wrapper.find('[data-testid="deal-detail-timeline-impersonated"]').text()).toContain('u42');
    });

    it('says the history is empty rather than drawing nothing', async () => {
        const wrapper = await render(respond({ timeline: [] }));

        expect(wrapper.find('[data-testid="deal-detail-timeline-empty"]').exists()).toBe(true);
    });

    // ────────────────────────────────────────────── two permissions, two refusals

    it('refuses the timeline section without refusing the deal', async () => {
        const wrapper = await render(respond({ timelineStatus: 403 }));

        // The summary loaded; only the history was refused.
        expect(wrapper.find('[data-testid="deal-detail-summary"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-detail-timeline"]').html()).toContain('permission-denied');
        expect(wrapper.find('[data-testid="deal-detail-timeline-entry"]').exists()).toBe(false);
    });

    it('draws a 403 on the deal itself as a page refusal', async () => {
        const wrapper = await render(respond({ dealStatus: 403 }));

        expect(wrapper.html()).toContain('permission-denied');
        expect(wrapper.find('[data-testid="deal-detail-summary"]').exists()).toBe(false);
    });

    it('says a 404 could not be opened, never which of the two reasons', async () => {
        const wrapper = await render(respond({ dealStatus: 404 }));

        const text = wrapper.find('[data-testid="deal-detail-missing"]').text();

        // OpenAPI §5.1: "does not exist **or** is not visible to the caller. Do
        // not reveal which case applies."
        expect(text).toContain('could not be opened');
        expect(text).not.toContain('permission');
        expect(text).not.toContain('does not exist');
    });

    // ──────────────────────────────────────── §5.2: controls only by permission

    it('draws the approval and status controls for a role that holds them', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deal-approval"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-status-start"]').exists()).toBe(true);
    });

    it('links to the quotation builder for a role that may create one (Design System §2.1)', async () => {
        const wrapper = await render(respond(), { ...USER, permissions: [...USER.permissions, 'quotation.create.own'] });

        expect(wrapper.find('[data-testid="deal-detail-new-quotation"]').attributes('href')).toBe('/quotations/new?deal=d1');
    });

    it('draws no builder link without quotation.create', async () => {
        const wrapper = await render(respond());

        expect(wrapper.find('[data-testid="deal-detail-new-quotation"]').exists()).toBe(false);
    });

    it('draws no approval control on a deal that was never submitted', async () => {
        const wrapper = await render(respond({ deal: { ...DEAL, approval_status: null } }));

        // Flow 1: null is "never submitted", not "waiting for a decision".
        expect(wrapper.find('[data-testid="deal-approval"]').exists()).toBe(false);
    });

    it('reloads the deal and its history after a status change', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        const before = fetchMock.mock.calls.filter((c) => String(c[0]).includes('/timeline')).length;

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('negotiations');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        // A transition writes a new audit row, so the history in hand is stale
        // the moment the change succeeds.
        expect(fetchMock.mock.calls.filter((c) => String(c[0]).includes('/timeline')).length)
            .toBeGreaterThan(before);
    });
});
