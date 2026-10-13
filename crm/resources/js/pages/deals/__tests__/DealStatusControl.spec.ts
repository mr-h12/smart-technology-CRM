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
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps', details: null,
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

    /** F-42 · P7 (owner, 2026-10-14): the list is the control — one pick, then the confirm. */
    async function pick(wrapper: Awaited<ReturnType<typeof render>>, code: string): Promise<void> {
        await wrapper.find('[data-testid="deal-status-target"]').setValue(code);
        await flushPromises();
    }

    async function accept(wrapper: Awaited<ReturnType<typeof render>>): Promise<void> {
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();
    }

    it('is drawn only for a role §3.4 grants change_status', async () => {
        expect((await render(CHANGER)).find('[data-testid="deal-status-target"]').exists()).toBe(true);
        expect((await render(READER)).find('[data-testid="deal-status-target"]').exists()).toBe(false);
    });

    it('is the list itself: no button to open it first, and named for what it does (F-42 · P7)', async () => {
        const wrapper = await render(CHANGER);
        const select = wrapper.get('[data-testid="deal-status-target"]');

        expect(select.element.tagName).toBe('SELECT');
        expect(select.attributes('aria-label')).toBe('Change status');
        expect(wrapper.find('[data-testid="deal-status-start"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-status-form"]').exists()).toBe(false);
    });

    it('offers §4.4’s whole vocabulary, not a reachable set', async () => {
        const wrapper = await render(CHANGER);
        const options = wrapper.find('[data-testid="deal-status-target"]').findAll('option');

        // Twelve statuses plus the prompt. Narrowing this list here would put
        // §4.4's graph in a second place, and the copy is the one that rots.
        expect(options).toHaveLength(13);
        expect(options.map((o) => o.attributes('value'))).toContain('delivery_complete');
    });

    it('greys out only the status the deal already has (F-42 · P7)', async () => {
        const wrapper = await render(CHANGER);
        const disabled = wrapper.find('[data-testid="deal-status-target"]').findAll('option')
            .filter((o) => (o.element as HTMLOptionElement).disabled)
            .map((o) => o.attributes('value'));

        // The prompt cannot be picked back, and `lead` is where DEAL already is.
        expect(disabled).toEqual(['', 'lead']);
    });

    it('asks before it changes anything: a pick calls no API until the confirm (F-42 · P7)', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'contacted' })));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'contacted');

        expect(fetchMock).not.toHaveBeenCalled();
        const dialog = wrapper.get('[data-testid="confirm-dialog"]');
        expect(dialog.text()).toContain('Contacted');
    });

    it('sends the target and no reason on an ordinary status, after the confirm', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'contacted' })));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'contacted');
        await accept(wrapper);

        // `reason` is `prohibited` on every status but `lost`, so absent and
        // not null: a null would 422.
        expect(bodyOf(fetchMock)).toEqual({ status: 'contacted' });
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
    });

    it('puts the list back and calls nothing on Cancel or Escape (F-42 · P7)', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(CHANGER, fetchMock);
        const select = wrapper.get('[data-testid="deal-status-target"]');

        await pick(wrapper, 'won');
        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
        expect((select.element as HTMLSelectElement).value).toBe('');

        await pick(wrapper, 'won');
        // happy-dom does not turn Escape into the dialog's cancel; the browser does.
        wrapper.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
        expect((select.element as HTMLSelectElement).value).toBe('');

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('asks for a reason, inside the confirm, only when the target is lost', async () => {
        const wrapper = await render(CHANGER);

        await pick(wrapper, 'won');
        expect(wrapper.find('[data-testid="deal-status-reason"]').exists()).toBe(false);
        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');

        await pick(wrapper, 'lost');
        expect(wrapper.get('[data-testid="confirm-dialog"]').find('[data-testid="deal-status-reason"]').exists()).toBe(true);
    });

    it('sends the reason with a loss', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'lost' })));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('Lost on price');
        await accept(wrapper);

        expect(bodyOf(fetchMock)).toEqual({ status: 'lost', reason: 'Lost on price' });
    });

    it('refuses a blank loss reason without asking the server, and keeps the confirm open', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('   ');
        await accept(wrapper);

        // §4.4's table: "Lost | Sales (mandatory reason)". The server applies
        // `regex:/\S/`; this is the same check where it can still be fixed.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-status-reason-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true);
    });

    it('renders an undocumented edge as the server’s refusal, inside the confirm', async () => {
        const fetchMock = vi.fn(async () => json(409, {
            error: { code: 'state_transition_invalid' }, meta: { request_id: 'r1' },
        }));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'delivery_complete');
        await accept(wrapper);

        // The edge was offered, tried and refused — which is what §7.1 asks
        // for, and is honest about where the rule lives.
        expect(wrapper.get('[data-testid="confirm-dialog"]').find('[data-testid="deal-status-error"]').text())
            .toContain('not allowed');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });

    it('says a 403 is a permission problem', async () => {
        const fetchMock = vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));
        const wrapper = await render(CHANGER, fetchMock);

        await pick(wrapper, 'delivery_complete');
        await accept(wrapper);

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

        await pick(wrapper, 'lost');
        await wrapper.find('[data-testid="deal-status-reason"]').setValue('Lost on price');
        await accept(wrapper);

        expect(wrapper.find('[data-testid="deal-status-reason-error"]').text())
            .toBe('A reason is only accepted when a deal is lost.');
    });
});

describe('the deal status control after F-41 (F-42 · P6, P7)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    async function attached() {
        await signIn(CHANGER);
        const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

        return mount(DealStatusControl, { props: { deal: DEAL }, global: { plugins: [i18n] }, attachTo: document.body });
    }

    it('gives focus back to the list when the confirm is cancelled (A-22)', async () => {
        const wrapper = await attached();
        const select = wrapper.get('[data-testid="deal-status-target"]');

        (select.element as HTMLSelectElement).focus();
        await select.setValue('won');
        await flushPromises();
        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(select.element);
        wrapper.unmount();
    });

    it('puts focus in the reason field when the confirm asks for one', async () => {
        const wrapper = await attached();

        await wrapper.get('[data-testid="deal-status-target"]').setValue('lost');
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.activeElement).toBe(wrapper.get('[data-testid="deal-status-reason"]').element);
        wrapper.unmount();
    });

    it('ties the reason’s label to its own field, not a fixed id (A-29)', async () => {
        const wrapper = await attached();

        await wrapper.get('[data-testid="deal-status-target"]').setValue('lost');
        await flushPromises();

        const reason = wrapper.get('[data-testid="deal-status-reason"]');
        expect(reason.attributes('id')).not.toBe('deal-status-reason');
        expect(wrapper.find(`label[for="${reason.attributes('id')}"]`).exists()).toBe(true);
        wrapper.unmount();
    });
});
