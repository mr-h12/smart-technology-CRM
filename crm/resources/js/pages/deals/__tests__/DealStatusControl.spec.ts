import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import type { Deal } from '@/services/deals';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.5 — §4.4's transition at the screen; F-49 · 1.3 (`D-119`).
 *
 * ── By hand, only the three ────────────────────────────────────────────────
 *
 * `D-119`: every status before `won` (and `won` and `lost` themselves) is
 * reached on its own — creation, a quotation, the customer's answer — and by
 * hand only `purchasing`, `delivery` and `delivery_complete` are chosen. Each
 * has exactly one way in (§4.4: `won → purchasing → delivery →
 * delivery_complete`), so the control is **one button** naming the next one,
 * drawn only on those three edges. The server still decides: the edge (409),
 * `D-14`'s second permission (403), and the narrowed list itself (422).
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL: Deal = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Twelve pumps', details: null,
    source: 'employee_entry', service_type: 'product', status: 'won', owner_id: 'u9',
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

    function next(wrapper: Awaited<ReturnType<typeof render>>) {
        return wrapper.find('[data-testid="deal-status-next"]');
    }

    async function open(wrapper: Awaited<ReturnType<typeof render>>): Promise<void> {
        await next(wrapper).trigger('click');
        await flushPromises();
    }

    async function accept(wrapper: Awaited<ReturnType<typeof render>>): Promise<void> {
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();
    }

    it('is drawn only for a role §3.4 grants change_status', async () => {
        expect(next(await render(CHANGER)).exists()).toBe(true);
        expect(next(await render(READER)).exists()).toBe(false);
    });

    it.each([
        ['won', 'Purchasing'],
        ['purchasing', 'Delivery'],
        ['delivery', 'Delivery Complete'],
    ])('from %s it is one button naming the next status, %s (D-119)', async (status, label) => {
        const wrapper = await render(CHANGER, undefined, { ...DEAL, status });

        expect(next(wrapper).element.tagName).toBe('BUTTON');
        expect(next(wrapper).text()).toContain(label);
        // No list any more: one way in per manual status, so nothing to pick.
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it.each(['lead', 'contacted', 'waiting_customer_request', 'supplier_rfq', 'supplier_quotation',
        'quotation_sent', 'negotiations', 'lost', 'delivery_complete'])(
        'is not drawn at %s, where no manual move is left (D-119)',
        async (status) => {
            const wrapper = await render(CHANGER, undefined, { ...DEAL, status });

            expect(wrapper.find('[data-testid="deal-status-control"]').exists()).toBe(false);
        },
    );

    it('asks before it changes anything: the button calls no API until the confirm', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'purchasing' })));
        const wrapper = await render(CHANGER, fetchMock);

        await open(wrapper);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="confirm-dialog"]').text()).toContain('Purchasing');
    });

    it('sends the next status and nothing else, after the confirm', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope({ ...DEAL, status: 'purchasing' })));
        const wrapper = await render(CHANGER, fetchMock);

        await open(wrapper);
        await accept(wrapper);

        // `reason` is `prohibited` on every status (D-119 withdrew the manual `lost`).
        expect(bodyOf(fetchMock)).toEqual({ status: 'purchasing' });
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
    });

    it('asks for no reason', async () => {
        const wrapper = await render(CHANGER);

        await open(wrapper);

        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('calls nothing on Cancel or Escape', async () => {
        const fetchMock = vi.fn(async () => json(200, envelope(DEAL)));
        const wrapper = await render(CHANGER, fetchMock);

        await open(wrapper);
        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);

        await open(wrapper);
        // happy-dom does not turn Escape into the dialog's cancel; the browser does.
        wrapper.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('renders a refused edge as the server’s refusal, inside the confirm', async () => {
        const fetchMock = vi.fn(async () => json(409, {
            error: { code: 'state_transition_invalid' }, meta: { request_id: 'r1' },
        }));
        const wrapper = await render(CHANGER, fetchMock);

        await open(wrapper);
        await accept(wrapper);

        // A stale page (someone moved the deal meanwhile) is still the server's to refuse.
        expect(wrapper.get('[data-testid="confirm-dialog"]').find('[data-testid="deal-status-error"]').text())
            .toContain('not allowed');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });

    it('says a 403 is a permission problem', async () => {
        const fetchMock = vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));
        const wrapper = await render(CHANGER, fetchMock, { ...DEAL, status: 'delivery' });

        await open(wrapper);
        await accept(wrapper);

        // `delivery_complete` needs D-14's `mark_delivery_complete` as well; the
        // button is drawn on `change_status` alone and the server refuses the rest.
        expect(wrapper.find('[data-testid="deal-status-error"]').text()).toContain('do not have permission');
    });
});

describe('the deal status control after F-41 (F-42 · P6, P7)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('gives focus back to the button when the confirm is cancelled (A-22)', async () => {
        await signIn(CHANGER);
        const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
        const wrapper = mount(DealStatusControl, { props: { deal: DEAL }, global: { plugins: [i18n] }, attachTo: document.body });
        const button = wrapper.get('[data-testid="deal-status-next"]');

        (button.element as HTMLButtonElement).focus();
        await button.trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(button.element);
        wrapper.unmount();
    });
});
