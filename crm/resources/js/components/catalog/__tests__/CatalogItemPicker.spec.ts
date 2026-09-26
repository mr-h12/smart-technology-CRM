import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CatalogItemPicker from '@/components/catalog/CatalogItemPicker.vue';
import SearchCombobox from '@/components/SearchCombobox.vue';

/**
 * F-18 · 1.2 (`D-93`) — a supplier offer line's product, searched on the server,
 * the offer's supplier's items first. The keyboard, the states and the 20-row
 * page are `SearchCombobox`'s and `CustomerPicker.spec.ts` covers them; this
 * covers what the catalog adds. Every request assertion reads the URL.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function item(id: string, extra: Record<string, unknown>): Record<string, unknown> {
    return { id, kind: 'product', name: null, service_type: null, suppliers: [], is_active: true, ...extra };
}

function page(items: unknown[]): Response {
    return json(200, {
        data: items,
        meta: {
            request_id: 'r1',
            pagination: { page: 1, per_page: 20, total: items.length, total_pages: 1, has_next_page: false, has_previous_page: false },
        },
    });
}

const CABLE = item('ci1', { name: 'Cable 2.5mm' });
const INSTALL = item('ci2', { kind: 'service', service_type: 'installation' });

function server(answer: (url: URL) => Response = () => page([CABLE, INSTALL])): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => answer(new URL(String(input), 'http://localhost')));
}

function catalogCalls(fetchMock: ReturnType<typeof vi.fn>): URL[] {
    return fetchMock.mock.calls
        .map((call) => new URL(String(call[0]), 'http://localhost'))
        .filter((url) => url.pathname.endsWith('/catalog-items'));
}

function render(fetchMock: ReturnType<typeof vi.fn>, props: Record<string, unknown> = {}, locale = 'en') {
    vi.stubGlobal('fetch', fetchMock);
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });

    return mount(CatalogItemPicker, {
        props: { modelValue: '', testId: 'picker', supplierId: 's1', ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

function box(wrapper: ReturnType<typeof render>): HTMLInputElement {
    return wrapper.find('[data-testid="picker"]').element as HTMLInputElement;
}

async function open(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.find('[data-testid="picker"]').trigger('focus');
    await flushPromises();
}

describe('CatalogItemPicker', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('is built on the shared search combobox', () => {
        expect(render(server()).findComponent(SearchCombobox).exists()).toBe(true);
    });

    // §10.4 / Module 4: a deactivated product is hidden from new selection lists.
    it('asks the server for active items, the supplier\'s first, then narrows with q', async () => {
        const fetchMock = server();
        const wrapper = render(fetchMock);
        await open(wrapper);

        const first = catalogCalls(fetchMock)[0];
        expect(first?.searchParams.get('filter[is_active]')).toBe('true');
        expect(first?.searchParams.get('supplier_first')).toBe('s1');
        expect(first?.searchParams.get('per_page')).toBe('20');

        await wrapper.find('[data-testid="picker"]').setValue('cab');
        vi.advanceTimersByTime(300);
        await flushPromises();

        expect(catalogCalls(fetchMock).at(-1)?.searchParams.get('q')).toBe('cab');
    });

    it('sends no supplier_first before the offer has a supplier', async () => {
        const fetchMock = server();
        await open(render(fetchMock, { supplierId: '' }));

        expect(catalogCalls(fetchMock)[0]?.searchParams.has('supplier_first')).toBe(false);
    });

    it('names a product by name and a service by its service type (§7.3)', async () => {
        const wrapper = render(server());
        await open(wrapper);

        expect(wrapper.findAll('[data-testid="picker-option"]').map((option) => option.text())).toEqual(['Cable 2.5mm', 'installation']);
    });

    it('hands back the picked id and its label', async () => {
        const wrapper = render(server());
        await open(wrapper);

        await wrapper.findAll('[data-testid="picker-option"]')[1]?.trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['ci2']);
        expect(wrapper.emitted('update:label')?.at(-1)).toEqual(['installation']);
        expect(box(wrapper).value).toBe('installation');
    });

    // An opened offer's line: the id and the name the server sent (`product_name`).
    it('shows the label it opens with, and follows it when the line changes from outside', async () => {
        const wrapper = render(server(), { modelValue: 'ci1', label: 'Cable 2.5mm' });
        await flushPromises();

        expect(box(wrapper).value).toBe('Cable 2.5mm');

        // `:key="index"` rows reuse this instance when a line above is removed.
        await wrapper.setProps({ modelValue: 'ci9', label: 'Bolt' });
        expect(box(wrapper).value).toBe('Bolt');
    });

    // `D-22`: the old select's empty option, kept — the form shows its name box.
    it('offers "Type a name instead" first, which empties the id and the label', async () => {
        const wrapper = render(server(), { modelValue: 'ci1', label: 'Cable 2.5mm' });
        await open(wrapper);

        const byName = wrapper.find('[data-testid="picker-all"]');
        expect(byName.text()).toBe('Type a name instead');

        await byName.trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['']);
        expect(wrapper.emitted('update:label')?.at(-1)).toEqual(['']);
    });

    it('speaks Arabic in Arabic, the refusal included', async () => {
        const wrapper = render(server(() => json(403, { error: { code: 'permission_denied' }, meta: { request_id: 'r1' } })), {}, 'ar');
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-all"]').text()).toBe('اكتب الاسم بدلًا من ذلك');
        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('ليست لديك صلاحية عرض الكتالوج');
        expect(box(wrapper).placeholder).toBe('ابحث في الكتالوج بالاسم…');
    });
});
