import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealOwnerPicker from '@/pages/deals/DealOwnerPicker.vue';

/**
 * F-42 · A-41: while the employees are read, the picker says so; the
 * identifier box appears only once the list was refused, never as a
 * placeholder before the answer.
 */
function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false };

const EMPLOYEE = {
    id: 'u9', name: 'Sara Haddad', email: 'sara@example.test', is_active: true,
    role: { id: 'r5', slug: 'indoor_sales', label: 'Indoor Sales' },
};

function render() {
    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(DealOwnerPicker, {
        props: { modelValue: '', fieldId: 'owner', testId: 'owner' },
        global: { plugins: [i18n] },
    });
}

describe('the deal owner picker (F-42 · A-41)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('says it is loading, and draws no identifier box, while the list is read', async () => {
        let release: (value: Response) => void = () => {};
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { release = resolve; })));

        const wrapper = render();
        await flushPromises();

        expect(wrapper.get('[data-testid="owner-loading"]').text()).toBe('Loading…');
        expect(wrapper.find('input').exists()).toBe(false);

        release(json(200, { data: [EMPLOYEE], meta: { request_id: 'r1', pagination: PAGINATION } }));
        await flushPromises();

        expect(wrapper.find('[data-testid="owner-loading"]').exists()).toBe(false);
        expect(wrapper.get('select').text()).toContain('Sara Haddad');
    });

    it('draws the identifier box only after a refusal', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } })));

        const wrapper = render();
        await flushPromises();

        expect(wrapper.find('[data-testid="owner-loading"]').exists()).toBe(false);
        expect(wrapper.find('input').exists()).toBe(true);
        expect(wrapper.find('[data-testid="owner-unavailable"]').exists()).toBe(true);
    });
});
