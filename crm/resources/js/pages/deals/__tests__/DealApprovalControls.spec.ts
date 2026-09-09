import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealApprovalControls from '@/pages/deals/DealApprovalControls.vue';
import type { Deal } from '@/services/deals';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.4 — Flow 3's decision at the screen.
 *
 * ⚠️ **The role the criterion names cannot demonstrate it today.** §3.4 grants
 * `deal.approve` to the Manager (`All`) and the Team Leader (`Team`) only, and
 * `Team` resolves to no rows at all (Point 2.1) — so a Team Leader holds the
 * permission, is drawn the buttons, and reaches no deal to press them on. That
 * is recorded backend debt, not a defect of this component, and the module's
 * manual test list says so in as many words.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PENDING: Deal = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps',
    source: 'employee_entry', service_type: 'product', status: 'lead', owner_id: 'u9',
    approval_status: 'pending', rejection_reason: null, lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00', updated_at: '2026-09-08T09:00:00+00:00',
};

const DECIDER: AuthenticatedUser = {
    id: 'u1', name: 'Test Manager', email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.view.all', 'deal.approve.all'],
    is_active: true, unconditional_access: false,
};

/** §3.4's documented negative: Indoor Sales holds `view` and `edit`, never `approve`. */
const NON_DECIDER: AuthenticatedUser = {
    ...DECIDER, id: 'u2', permissions: ['deal.view.own', 'deal.edit.own'],
};

function envelope(data: unknown): unknown {
    return { data, meta: { request_id: 'r1' } };
}

async function signIn(profile: AuthenticatedUser): Promise<void> {
    vi.stubGlobal('fetch', () => Promise.resolve(json(201, {
        data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
    })));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(deal: Deal, profile: AuthenticatedUser, fetchMock?: ReturnType<typeof vi.fn>) {
    await signIn(profile);

    if (fetchMock) {
        vi.stubGlobal('fetch', fetchMock);
    }

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(DealApprovalControls, { props: { deal }, global: { plugins: [i18n] } });
}

describe('the deal approval controls', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ────────────────────────────────────────────────────────────── the badges

    it('draws the pending badge with an icon and a word, not colour alone', async () => {
        const wrapper = await render(PENDING, DECIDER);

        const badge = wrapper.find('[data-testid="deal-approval-badge-pending"]');

        // §6.4: warning is "amber icon + label"; a chip carrying only a colour
        // is unreadable to anyone who cannot see it.
        expect(badge.exists()).toBe(true);
        expect(badge.text()).toContain('Pending approval');
        expect(badge.text()).toContain('⏳');
    });

    it('draws the rejected badge and the reason beside it', async () => {
        const wrapper = await render(
            { ...PENDING, approval_status: 'rejected', rejection_reason: 'Budget withdrawn' },
            NON_DECIDER,
        );

        // §4.3 makes the reason mandatory on rejection precisely so the
        // employee can read it — so it is beside the badge, not behind it.
        expect(wrapper.find('[data-testid="deal-approval-badge-rejected"]').text()).toContain('Rejected');
        expect(wrapper.find('[data-testid="deal-approval-reason"]').text()).toContain('Budget withdrawn');
    });

    it('draws the approved badge as a success, not a warning', async () => {
        const wrapper = await render({ ...PENDING, approval_status: 'approved' }, DECIDER);

        expect(wrapper.find('[data-testid="deal-approval-badge-approved"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-approval-badge-pending"]').exists()).toBe(false);
    });

    // ──────────────────────────────────────────────────────── who may decide

    it('draws both actions for a role §3.4 grants deal.approve', async () => {
        const wrapper = await render(PENDING, DECIDER);

        // One permission covers both directions — there is no `deal.reject`.
        expect(wrapper.find('[data-testid="deal-approve"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-reject"]').exists()).toBe(true);
    });

    it('draws neither for a role that only edits', async () => {
        const wrapper = await render(PENDING, NON_DECIDER);

        expect(wrapper.find('[data-testid="deal-approve"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-reject"]').exists()).toBe(false);
        // The badge is a fact, not an action, and stays.
        expect(wrapper.find('[data-testid="deal-approval-badge-pending"]').exists()).toBe(true);
    });

    it('offers no decision on a deal that is not pending', async () => {
        const wrapper = await render({ ...PENDING, approval_status: 'approved' }, DECIDER);

        // `ReviewDealApproval` refuses a second decision with a 409; drawing
        // the button would be offering a control that cannot succeed.
        expect(wrapper.find('[data-testid="deal-approve"]').exists()).toBe(false);
    });

    // ──────────────────────────────────────────────────────────── the decision

    it('approves through its own route and reports the decided deal', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...PENDING, approval_status: 'approved' })));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-approve"]').trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(String(url)).toContain('/deals/d1/approve');
        expect(init.method).toBe('PATCH');
        expect(wrapper.emitted('decided')).toHaveLength(1);
    });

    it('collects the reason before submitting a rejection, never after', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...PENDING, approval_status: 'rejected' })));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-reject"]').trigger('click');
        expect(wrapper.find('[data-testid="deal-reject-form"]').exists()).toBe(true);

        await wrapper.find('[data-testid="deal-reject-reason"]').setValue('Budget withdrawn');
        await wrapper.find('[data-testid="deal-reject-form"]').trigger('submit');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(String(url)).toContain('/deals/d1/reject');
        expect(JSON.parse(String(init.body))).toEqual({ reason: 'Budget withdrawn' });
    });

    it('refuses to submit a blank reason, and never asks the server', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(PENDING)));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-reject"]').trigger('click');
        await wrapper.find('[data-testid="deal-reject-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-reject-reason-error"]').text())
            .toBe('A reason is required to reject a request.');
    });

    it('refuses whitespace as a reason, matching the server’s own rule', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(PENDING)));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-reject"]').trigger('click');
        await wrapper.find('[data-testid="deal-reject-reason"]').setValue('   ');
        await wrapper.find('[data-testid="deal-reject-form"]').trigger('submit');
        await flushPromises();

        // `RejectDealRequest` is `required` + `regex:/\S/` — the same check,
        // made where the person can still fix it.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-reject-reason-error"]').exists()).toBe(true);
    });

    // ───────────────────────────────────────────────────────── the server says no

    it('surfaces a 409 as a stale row rather than swallowing it', async () => {
        const fetchMock = vi.fn(async () => json(409, {
            error: { code: 'state_transition_invalid' }, meta: { request_id: 'r1' },
        }));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-approve"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-approval-error"]').text())
            .toContain('already been decided');
        expect(wrapper.emitted('decided')).toBeUndefined();
    });

    it('renders the server’s own sentence when it refuses the reason', async () => {
        const fetchMock = vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                details: [{ field: 'reason', message: 'The reason may not be longer than 500 characters.' }],
            },
            meta: { request_id: 'r1' },
        }));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-reject"]').trigger('click');
        await wrapper.find('[data-testid="deal-reject-reason"]').setValue('x'.repeat(600));
        await wrapper.find('[data-testid="deal-reject-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-reject-reason-error"]').text())
            .toBe('The reason may not be longer than 500 characters.');
    });

    it('says a 403 is a permission problem, not a bad reason', async () => {
        const fetchMock = vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));
        const wrapper = await render(PENDING, DECIDER, fetchMock);

        await wrapper.find('[data-testid="deal-approve"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-approval-error"]').text())
            .toContain('do not have permission');
    });
});
