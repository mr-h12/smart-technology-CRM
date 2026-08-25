import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import EmailChallengeModal from '@/components/profile/EmailChallengeModal.vue';

/**
 * Point 5.4 — `SEC-04` step two, §9 Flow 0's "verification code by email".
 *
 * §6.1 · §6.4 · §9.5 · `AP-08`. The dialog collects a code and reports a
 * deadline; it makes no request and decides no rule. What is tested is that it
 * cannot submit something the endpoint would refuse on shape, and that the
 * clock it shows is the one it was given.
 */

const FIFTEEN_MINUTES = 15 * 60 * 1000;

function mountModal(props: Record<string, unknown> = {}, locale: 'ar' | 'en' = 'en') {
    return mount(EmailChallengeModal, {
        props: {
            open: true,
            email: 'person@example.test',
            expiresAt: Date.now() + FIFTEEN_MINUTES,
            busy: false,
            resending: false,
            errorKey: null,
            ...props,
        },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });
}

async function type(wrapper: ReturnType<typeof mountModal>, value: string): Promise<void> {
    const input = wrapper.find('[data-testid="challenge-code"]');
    (input.element as HTMLInputElement).value = value;
    await input.trigger('input');
}

beforeEach(() => {
    vi.useFakeTimers();
    // A fixed instant, so the countdown is arithmetic rather than a race with
    // the test runner.
    vi.setSystemTime(new Date('2026-08-25T10:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('the dialog', () => {
    it('renders nothing at all while closed', () => {
        expect(mountModal({ open: false }).find('[data-testid="email-challenge-modal"]').exists()).toBe(false);
    });

    it('names the inbox the code went to', () => {
        expect(mountModal().text()).toContain('person@example.test');
    });

    it('shows the server\'s own deadline as mm:ss', () => {
        // AP-08: the TTL is configuration (`identity.password_challenge.ttl_minutes`)
        // and arrives on the 202. A hard-coded fifteen here would keep counting
        // after an administrator changed it.
        expect(mountModal().find('[data-testid="challenge-countdown"]').text()).toContain('15:00');
    });

    it('counts down as time passes', async () => {
        const wrapper = mountModal();

        await vi.advanceTimersByTimeAsync(61_000);

        expect(wrapper.find('[data-testid="challenge-countdown"]').text()).toContain('13:59');
    });

    it('says the code expired and refuses to send it', async () => {
        const wrapper = mountModal({ expiresAt: Date.now() + 1000 });

        await type(wrapper, '123456');
        await vi.advanceTimersByTimeAsync(2000);

        expect(wrapper.find('[data-testid="challenge-countdown"]').text()).toContain(en.account.challenge.expired);
        expect(wrapper.find('[data-testid="challenge-submit"]').attributes('disabled')).toBeDefined();
    });
});

describe('the code field', () => {
    it('will not submit fewer than six digits', async () => {
        const wrapper = mountModal();

        await type(wrapper, '12345');

        expect(wrapper.find('[data-testid="challenge-submit"]').attributes('disabled')).toBeDefined();
    });

    it('strips anything that is not a digit and stops at six', async () => {
        const wrapper = mountModal();

        await type(wrapper, '12a34b56789');

        // The endpoint's rule is `digits:6`; a field that let "12a345" through
        // would spend one of SEC-04's five attempts on a typo.
        expect((wrapper.find('[data-testid="challenge-code"]').element as HTMLInputElement).value).toBe('123456');
        expect(wrapper.find('[data-testid="challenge-submit"]').attributes('disabled')).toBeUndefined();
    });

    it('emits the code rather than sending it', async () => {
        const wrapper = mountModal();

        await type(wrapper, '654321');
        await wrapper.find('[data-testid="challenge-submit"]').trigger('submit');

        expect(wrapper.emitted('submit')).toEqual([['654321']]);
    });
});

describe('the controls', () => {
    it('emits resend and cancel', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-testid="challenge-resend"]').trigger('click');
        await wrapper.find('[data-testid="challenge-cancel"]').trigger('click');

        expect(wrapper.emitted('resend')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('disables everything while the verification is in flight', async () => {
        const wrapper = mountModal({ busy: true });

        await type(wrapper, '123456');

        expect(wrapper.find('[data-testid="challenge-submit"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="challenge-cancel"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="challenge-resend"]').attributes('disabled')).toBeDefined();
    });

    it('cancels on Escape (§6.1), and not while a request is in flight', async () => {
        const wrapper = mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        await wrapper.setProps({ busy: true });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('stops listening for Escape once unmounted', () => {
        const wrapper = mountModal();
        wrapper.unmount();

        // A document listener that outlives its component answers for a dialog
        // that is no longer on the page.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('shows the refusal it was handed', () => {
        const wrapper = mountModal({ errorKey: 'account.error.codeInvalid' });

        expect(wrapper.find('[data-testid="challenge-error"]').text()).toBe(en.account.error.codeInvalid);
    });
});

describe('Arabic', () => {
    it('renders the Arabic dialog, not a raw key', () => {
        const wrapper = mountModal({}, 'ar');

        expect(wrapper.find('[data-testid="challenge-submit"]').text()).toBe(ar.account.challenge.confirm);
        expect(wrapper.text()).not.toContain('account.challenge');
    });
});
