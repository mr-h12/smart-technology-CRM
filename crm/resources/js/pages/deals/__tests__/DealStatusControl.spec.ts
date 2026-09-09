import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import type { Deal } from '@/services/deals';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.5 — §4.4's transition at the screen.
 *
 * ── The graph is not tested here, because it is not here ───────────────────
 *
 * `DealStatusTransition` is built from §4.4's table and lives on the server;
 * `DealStatusEndpointTest` proves every documented and undocumented edge
 * against it. What is proved here is that this control **offers the
 * vocabulary** rather than a reachable set, sends `reason` only for `lost`, and
 * renders the server's refusal instead of pre-empting it — `D-67` and Design
 * System §7.1, "the server decides authorization and allowed transition".
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL: Deal = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps',
    source: 'employee_entry', service_type: 'product', status: 'lead', owner_id: 'u9',
    approval_status: null, rejection_reason: null, lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00', updated_at: '2026-09-08T09:00:00+00:00',
};

const CHANGER: AuthenticatedUser = {
    id: 'u1', name: 'Test Manager', email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.view.all', 'deal.change_status.all'],
    is_active: true, unconditional_access: false,
};

/** §3.4's documented negative: the CEO holds `view` and has no `change status` cell. */
const READER: AuthenticatedUser = {
    ...CHANGER, id: 'u2', role: { id: 'r2', slug: 'ceo', name: 'CEO' }, permissions: ['deal.view.all'],
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

async function render(profile: AuthenticatedUser, fetchMock?: ReturnType<typeof vi.fn>, deal: Deal = DEAL) {
    await signIn(profile);

    if (fetchMock) {
        vi.stubGlobal('fetch', fetchMock);
    }

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(DealStatusControl, { props: { deal }, global: { plugins: [i18n] } });
}

function bodyOf(fetchMock: ReturnType<typeof vi.fn>): unknown {
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

    return JSON.parse(String(init.body));
}

describe('the deal status control', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('is drawn only for a role §3.4 grants change_status', async () => {
        expect((await render(CHANGER)).find('[data-testid="deal-status-start"]').exists()).toBe(true);
        expect((await render(READER)).find('[data-testid="deal-status-start"]').exists()).toBe(false);
    });

    it('offers §4.4’s whole vocabulary, not a reachable set', async () => {
        const wrapper = await render(CHANGER);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');

        const options = wrapper.find('[data-testid="deal-status-target"]').findAll('option');

        // Twelve statuses plus the empty prompt. Narrowing this list here would
        // put §4.4's graph in a second place, and the copy is the one that rots.
        expect(options).toHaveLength(13);
        expect(options.map((o) => o.attributes('value'))).toContain('delivery_complete');
    });

    it('offers delivery_complete even though a second permission gates it', async () => {
        const wrapper = await render(CHANGER);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');

        // `D-14`'s `mark_delivery_complete` is a different set of four roles,
        // and which permission applies depends on the body — so the server
        // decides. Hiding the option would be the SPA guessing at that.
        expect(wrapper.find('[data-testid="deal-status-target"]').findAll('option')
            .map((o) => o.attributes('value'))).toContain('delivery_complete');
    });

    it('sends the target and no reason on an ordinary status', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'contacted' })));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('contacted');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        // `reason` is `prohibited` on every status but `lost`, so absent and
        // not null: a null would 422.
        expect(bodyOf(fetchMock)).toEqual({ status: 'contacted' });
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('asks for a reason only when the target is lost', async () => {
        const wrapper = await render(CHANGER);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        expect(wrapper.find('[data-testid="deal-status-reason"]').exists()).toBe(false);

        await wrapper.find('[data-testid="deal-status-target"]').setValue('lost');
        expect(wrapper.find('[data-testid="deal-status-reason"]').exists()).toBe(true);

        await wrapper.find('[data-testid="deal-status-target"]').setValue('won');
        expect(wrapper.find('[data-testid="deal-status-reason"]').exists()).toBe(false);
    });

    it('sends the reason with a loss', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'lost' })));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('Lost on price');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        expect(bodyOf(fetchMock)).toEqual({ status: 'lost', reason: 'Lost on price' });
    });

    it('refuses a blank loss reason without asking the server', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('   ');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        // §4.4's table: "Lost | Sales (mandatory reason)". The server applies
        // `regex:/\S/`; this is the same check where it can still be fixed.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-status-reason-error"]').exists()).toBe(true);
    });

    it('refuses to submit with no target chosen', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-status-error"]').exists()).toBe(true);
    });

    it('renders an undocumented edge as the server’s refusal, not as a hidden option', async () => {
        const fetchMock = vi.fn(async () => json(409, {
            error: { code: 'state_transition_invalid' }, meta: { request_id: 'r1' },
        }));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('delivery_complete');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        // The edge was offered, tried and refused — which is what §7.1 asks
        // for, and is honest about where the rule lives.
        expect(wrapper.find('[data-testid="deal-status-error"]').text()).toContain('not allowed');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });

    it('says a 403 is a permission problem', async () => {
        const fetchMock = vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('delivery_complete');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        // The one edge needing `mark_delivery_complete` answers 403 for a role
        // holding only `change_status` — a different answer from the 409 above.
        expect(wrapper.find('[data-testid="deal-status-error"]').text()).toContain('do not have permission');
    });

    it('renders the server’s own sentence when it refuses the reason', async () => {
        const fetchMock = vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                details: [{ field: 'reason', message: 'A reason is only accepted when a deal is lost.' }],
            },
            meta: { request_id: 'r1' },
        }));
        const wrapper = await render(CHANGER, fetchMock);

        await wrapper.find('[data-testid="deal-status-start"]').trigger('click');
        await wrapper.find('[data-testid="deal-status-target"]').setValue('lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('Lost on price');
        await wrapper.find('[data-testid="deal-status-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-status-reason-error"]').text())
            .toBe('A reason is only accepted when a deal is lost.');
    });
});
