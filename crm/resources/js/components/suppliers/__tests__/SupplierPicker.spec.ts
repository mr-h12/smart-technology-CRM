import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SupplierPicker from '@/components/suppliers/SupplierPicker.vue';
import SearchCombobox from '@/components/SearchCombobox.vue';

/**
 * F-10 · 1.8 (`D-86`) — the catalog item's suppliers, chosen by searching the
 * server. The owner's review of the first draft (2026-09-22): one checkbox per
 * supplier does not survive hundreds of suppliers, and two suppliers with one
 * name could not be told apart. `CustomerPicker`'s search, many-valued: each
 * choice becomes a removable chip.
 *
 * Every request assertion reads the **URL**: the server answers the search
 * (`OpenAPI §6.2`'s `q` through `SearchService`), never a page narrowed here.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function supplier(id: string, name: string, extra: Record<string, unknown> = {}): Record<string, unknown> {
    return { id, name, contact_person: null, phone: null, is_active: true, ...extra };
}

function page(items: unknown[], total = items.length): Response {
    return json(200, {
        data: items,
        meta: {
            request_id: 'r1',
            pagination: { page: 1, per_page: 20, total, total_pages: 1, has_next_page: false, has_previous_page: false },
        },
    });
}

const CAIRO_A = supplier('s1', 'Cairo Valves Co', { contact_person: 'Hany', phone: '0100' });
const CAIRO_B = supplier('s2', 'Cairo Valves Co', { is_active: false });

function server(answer: (url: URL) => Response = () => page([CAIRO_A, CAIRO_B])): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => answer(new URL(String(input), 'http://localhost')));
}

function supplierCalls(fetchMock: ReturnType<typeof vi.fn>): URL[] {
    return fetchMock.mock.calls
        .map((call) => new URL(String(call[0]), 'http://localhost'))
        .filter((url) => url.pathname.endsWith('/suppliers'));
}

function render(fetchMock: ReturnType<typeof vi.fn>, modelValue: { id: string; name: string }[] = [], locale = 'en') {
    vi.stubGlobal('fetch', fetchMock);
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });

    return mount(SupplierPicker, {
        props: { modelValue, testId: 'picker', 'onUpdate:modelValue': () => undefined },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

async function open(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.find('[data-testid="picker"]').trigger('focus');
    await flushPromises();
}

function lastModel(wrapper: ReturnType<typeof render>): { id: string; name: string }[] {
    const emitted = wrapper.emitted('update:modelValue') ?? [];

    return emitted[emitted.length - 1]![0] as { id: string; name: string }[];
}

describe('SupplierPicker', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    // F-18 · 1.1: one shared picker, so a third one is a wrapper and not a copy.
    it('is built on the shared search combobox', () => {
        expect(render(server()).findComponent(SearchCombobox).exists()).toBe(true);
    });

    it('asks nothing until opened, then asks the server for the first 20', async () => {
        const fetchMock = server();
        const view = render(fetchMock);
        await flushPromises();

        expect(supplierCalls(fetchMock)).toHaveLength(0);

        await open(view);

        const [url] = supplierCalls(fetchMock);
        expect(url!.searchParams.get('per_page')).toBe('20');
        expect(url!.searchParams.has('q')).toBe(false);
        // Deactivated suppliers are offered (ruling A2), so no is_active filter.
        expect(url!.searchParams.has('filter[is_active]')).toBe(false);
    });

    it('sends what was typed as q after the pause, not on every key', async () => {
        const fetchMock = server();
        const view = render(fetchMock);
        await open(view);

        await view.find('[data-testid="picker"]').setValue('cai');
        vi.advanceTimersByTime(299);
        await flushPromises();
        expect(supplierCalls(fetchMock)).toHaveLength(1);

        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(supplierCalls(fetchMock).at(-1)!.searchParams.get('q')).toBe('cai');
    });

    it('tells two suppliers with one name apart by their details and the inactive word', async () => {
        const view = render(server(), [], 'ar');
        await open(view);

        const options = view.findAll('[data-testid="picker-option"]');
        expect(options).toHaveLength(2);
        expect(options[0]!.find('[data-testid="picker-option-detail"]').text()).toContain('Hany');
        expect(options[0]!.find('[data-testid="picker-option-detail"]').text()).toContain('0100');
        expect(options[1]!.find('[data-testid="picker-option-detail"]').text()).toContain(ar.suppliers.status.inactive);
    });

    it('adds a chosen supplier as a chip and keeps the list open for the next one', async () => {
        const view = render(server());
        await open(view);

        await view.findAll('[data-testid="picker-option"]')[0]!.trigger('mousedown');

        expect(lastModel(view)).toEqual([{ id: 's1', name: 'Cairo Valves Co' }]);
        expect(view.find('[role="listbox"]').isVisible()).toBe(true);
    });

    it('marks what is already chosen, and choosing it again takes it out', async () => {
        const view = render(server(), [{ id: 's1', name: 'Cairo Valves Co' }]);
        await open(view);

        const first = view.findAll('[data-testid="picker-option"]')[0]!;
        expect(first.attributes('aria-selected')).toBe('true');

        await first.trigger('mousedown');
        expect(lastModel(view)).toEqual([]);
    });

    it('draws each chosen supplier as a chip with a named remove button', async () => {
        const view = render(server(), [{ id: 's1', name: 'Cairo Valves Co' }, { id: 's9', name: 'Nile Pumps Co' }]);

        const chips = view.findAll('[data-testid="picker-chip"]');
        expect(chips.map((chip) => chip.text())).toEqual([expect.stringContaining('Cairo Valves Co'), expect.stringContaining('Nile Pumps Co')]);

        const remove = view.findAll('[data-testid="picker-chip-remove"]')[1]!;
        expect(remove.attributes('aria-label')).toBe(en.suppliers.picker.remove.replace('{name}', 'Nile Pumps Co'));

        await remove.trigger('click');
        expect(lastModel(view)).toEqual([{ id: 's1', name: 'Cairo Valves Co' }]);
    });

    it('disables the search and every remove button while the form saves', async () => {
        vi.stubGlobal('fetch', server());
        const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
        const view = mount(SupplierPicker, {
            props: { modelValue: [{ id: 's1', name: 'Cairo Valves Co' }], testId: 'picker', disabled: true },
            global: { plugins: [i18n] },
        });

        expect(view.find('[data-testid="picker"]').attributes('disabled')).toBeDefined();
        expect(view.find('[data-testid="picker-chip-remove"]').attributes('disabled')).toBeDefined();
    });

    it('says more match when the server holds more than the page', async () => {
        const view = render(server(() => page([CAIRO_A], 140)));
        await open(view);

        expect(view.find('[data-testid="picker-more"]').text()).toBe(en.suppliers.picker.more);
    });

    it('names a refusal, an outage with a retry, and an empty search apart', async () => {
        const refused = render(server(() => json(403, { error: { code: 'permission_denied' }, meta: { request_id: 'r' } })));
        await open(refused);
        expect(refused.find('[data-testid="picker-state"]').text()).toBe(en.suppliers.picker.forbidden);
        document.body.innerHTML = '';

        let calls = 0;
        const failing = render(server(() => (++calls === 1 ? json(500, { error: { code: 'server_error' }, meta: { request_id: 'r' } }) : page([CAIRO_A]))));
        await open(failing);
        expect(failing.find('[data-testid="picker-state"]').text()).toBe(en.suppliers.picker.failed);
        await failing.find('[data-testid="picker-retry"]').trigger('click');
        await flushPromises();
        expect(failing.findAll('[data-testid="picker-option"]')).toHaveLength(1);
        document.body.innerHTML = '';

        const none = render(server(() => page([])));
        await open(none);
        await none.find('[data-testid="picker"]').setValue('zz');
        vi.advanceTimersByTime(300);
        await flushPromises();
        expect(none.find('[data-testid="picker-state"]').text()).toBe(en.suppliers.picker.noMatch.replace('{query}', 'zz'));
    });

    it('chooses with the keyboard: arrows move, Enter picks, Escape closes', async () => {
        const view = render(server());
        const input = view.find('[data-testid="picker"]');
        await open(view);

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(input.attributes('aria-activedescendant')).toMatch(/-option-1$/);

        await input.trigger('keydown', { key: 'Enter' });
        expect(lastModel(view)).toEqual([{ id: 's2', name: 'Cairo Valves Co' }]);

        await input.trigger('keydown', { key: 'Escape' });
        expect(input.attributes('aria-expanded')).toBe('false');
    });
});
