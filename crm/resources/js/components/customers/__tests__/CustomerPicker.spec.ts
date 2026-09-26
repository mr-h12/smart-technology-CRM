import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CustomerPicker from '@/components/customers/CustomerPicker.vue';
import SearchCombobox from '@/components/SearchCombobox.vue';

/**
 * F-08 · 1.2 (`D-84`) — one customer dropdown that searches the server.
 *
 * Every assertion about a request reads the **URL**, because the picker's
 * whole point is that the server answers the question (`OpenAPI §6.2`'s `q`
 * through `SearchService`), never a page of 100 narrowed in the browser.
 * The keyboard follows the WAI-ARIA APG combobox with a listbox popup.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function customer(id: string, name: string, extra: Record<string, unknown> = {}): Record<string, unknown> {
    return { id, name, region: null, contact_person: null, phone: null, ...extra };
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

function refused(status: number): Response {
    return json(status, { error: { code: status === 403 ? 'permission_denied' : 'server_error' }, meta: { request_id: 'r1' } });
}

const NISCO = customer('c1', 'Nisco', { contact_person: 'Mr.Naser Fadl', phone: '01090907185' });
const CENTRE = customer('c2', 'المركز القومي للمرأة', { region: 'القاهرة' });

function server(answer: (url: URL) => Response = () => page([NISCO, CENTRE])): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => answer(new URL(String(input), 'http://localhost')));
}

function customerCalls(fetchMock: ReturnType<typeof vi.fn>): URL[] {
    return fetchMock.mock.calls
        .map((call) => new URL(String(call[0]), 'http://localhost'))
        .filter((url) => url.pathname.endsWith('/customers'));
}

function render(fetchMock: ReturnType<typeof vi.fn>, props: Record<string, unknown> = {}) {
    vi.stubGlobal('fetch', fetchMock);
    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(CustomerPicker, {
        props: { modelValue: '', testId: 'picker', ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

async function open(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.find('[data-testid="picker"]').trigger('focus');
    await flushPromises();
}

async function type(wrapper: ReturnType<typeof render>, text: string): Promise<void> {
    await wrapper.find('[data-testid="picker"]').setValue(text);
    vi.advanceTimersByTime(300);
    await flushPromises();
}

describe('CustomerPicker', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        // Only the timers the pause uses: flushPromises needs a real setImmediate.
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

    it('asks nothing until opened, then the first 20 by name', async () => {
        const fetchMock = server();
        const wrapper = render(fetchMock);
        await flushPromises();

        expect(customerCalls(fetchMock)).toHaveLength(0);

        await open(wrapper);

        const calls = customerCalls(fetchMock);
        expect(calls).toHaveLength(1);
        expect(calls[0]?.searchParams.get('per_page')).toBe('20');
        // The server's own default order is by name (`DEFAULT_SORT`); no `q`.
        expect(calls[0]?.searchParams.has('q')).toBe(false);
    });

    it('waits 300 ms of quiet before asking with q', async () => {
        const fetchMock = server();
        const wrapper = render(fetchMock);
        await open(wrapper);

        const input = wrapper.find('[data-testid="picker"]');
        await input.setValue('ni');
        vi.advanceTimersByTime(299);
        await flushPromises();
        expect(customerCalls(fetchMock)).toHaveLength(1);

        await input.setValue('nis');
        vi.advanceTimersByTime(299);
        await flushPromises();
        expect(customerCalls(fetchMock)).toHaveLength(1);

        vi.advanceTimersByTime(1);
        await flushPromises();

        const calls = customerCalls(fetchMock);
        expect(calls).toHaveLength(2);
        expect(calls[1]?.searchParams.get('q')).toBe('nis');
        expect(calls[1]?.searchParams.get('per_page')).toBe('20');
    });

    it('keeps the newest answer when an older one arrives after it', async () => {
        // The opening request is slow; the search typed after it answers
        // first. The slow one must not overwrite the results the person asked for.
        let releaseFirst: (response: Response) => void = () => {};
        const fetchMock = vi.fn((input: string) => {
            const url = new URL(String(input), 'http://localhost');

            return url.searchParams.has('q')
                ? Promise.resolve(page([CENTRE]))
                : new Promise<Response>((resolve) => { releaseFirst = resolve; });
        });
        const wrapper = render(fetchMock);
        await open(wrapper);
        await type(wrapper, 'مرأة');

        releaseFirst(page([NISCO]));
        await flushPromises();

        expect(wrapper.findAll('[data-testid="picker-option-name"]').map((o) => o.text())).toEqual(['المركز القومي للمرأة']);
    });

    it('shows the name and the muted region · contact · phone line, leaving out what is missing', async () => {
        const wrapper = render(server());
        await open(wrapper);

        const options = wrapper.findAll('[data-testid="picker-option"]');
        expect(options).toHaveLength(2);
        expect(options[0]?.find('[data-testid="picker-option-name"]').text()).toBe('Nisco');
        expect(options[0]?.find('[data-testid="picker-option-detail"]').text()).toBe('Mr.Naser Fadl · 01090907185');
        expect(options[1]?.find('[data-testid="picker-option-detail"]').text()).toBe('القاهرة');
    });

    it('draws no muted line for a row with none of the three', async () => {
        const wrapper = render(server(() => page([customer('c9', 'ASPPC')])));
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-option-detail"]').exists()).toBe(false);
    });

    it('says there are more when the total exceeds what came back', async () => {
        const more = render(server(() => page([NISCO, CENTRE], 234)));
        await open(more);
        expect(more.find('[data-testid="picker-more"]').text()).toBe('More customers match — type more letters');

        const all = render(server(() => page([NISCO, CENTRE], 2)));
        await open(all);
        expect(all.find('[data-testid="picker-more"]').exists()).toBe(false);
    });

    it('says the caller may not view customers on a 403', async () => {
        const wrapper = render(server(() => refused(403)));
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('You do not have permission to view customers');
        expect(wrapper.find('[data-testid="picker-retry"]').exists()).toBe(false);
    });

    it('says none are in scope when an unsearched answer is empty', async () => {
        const wrapper = render(server(() => page([])));
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('No customers in your scope');
    });

    it('names the search when a searched answer is empty', async () => {
        const wrapper = render(server((url) => (url.searchParams.has('q') ? page([]) : page([NISCO]))));
        await open(wrapper);
        await type(wrapper, 'zzz');

        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('No results for “zzz”');
    });

    it('says the list failed and asks again on retry', async () => {
        let fail = true;
        const fetchMock = server(() => (fail ? refused(500) : page([NISCO])));
        const wrapper = render(fetchMock);
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('Customers could not be loaded');

        fail = false;
        await wrapper.find('[data-testid="picker-retry"]').trigger('click');
        await flushPromises();

        expect(customerCalls(fetchMock)).toHaveLength(2);
        expect(wrapper.find('[data-testid="picker-state"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="picker-option"]')).toHaveLength(1);
    });

    it('carries the combobox roles the APG pattern names', async () => {
        const wrapper = render(server());
        const input = wrapper.find('[data-testid="picker"]');

        expect(input.attributes('role')).toBe('combobox');
        expect(input.attributes('aria-autocomplete')).toBe('list');
        expect(input.attributes('aria-expanded')).toBe('false');

        await open(wrapper);

        expect(input.attributes('aria-expanded')).toBe('true');
        const listbox = wrapper.find('[role="listbox"]');
        expect(input.attributes('aria-controls')).toBe(listbox.attributes('id'));
        expect(wrapper.findAll('[role="option"]')).toHaveLength(2);
    });

    it('moves and picks with the keyboard, then shows the picked name', async () => {
        const wrapper = render(server());
        await open(wrapper);
        const input = wrapper.find('[data-testid="picker"]');

        await input.trigger('keydown', { key: 'ArrowDown' });
        const first = wrapper.findAll('[role="option"]')[0];
        expect(input.attributes('aria-activedescendant')).toBe(first?.attributes('id'));

        await input.trigger('keydown', { key: 'ArrowDown' });
        const second = wrapper.findAll('[role="option"]')[1];
        expect(input.attributes('aria-activedescendant')).toBe(second?.attributes('id'));

        await input.trigger('keydown', { key: 'ArrowUp' });
        expect(input.attributes('aria-activedescendant')).toBe(first?.attributes('id'));

        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['c1']);
        expect(input.attributes('aria-expanded')).toBe('false');
        expect((input.element as HTMLInputElement).value).toBe('Nisco');
    });

    it('closes on Escape without choosing', async () => {
        const wrapper = render(server());
        await open(wrapper);
        const input = wrapper.find('[data-testid="picker"]');

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Escape' });

        expect(input.attributes('aria-expanded')).toBe('false');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('picks with a click on the option', async () => {
        const wrapper = render(server());
        await open(wrapper);

        await wrapper.findAll('[data-testid="picker-option"]')[1]?.trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['c2']);
        expect((wrapper.find('[data-testid="picker"]').element as HTMLInputElement).value).toBe('المركز القومي للمرأة');
    });

    it('offers the all label first and a clear button, both emptying the choice', async () => {
        const wrapper = render(server(), { allLabel: 'Any customer', modelValue: '' });
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-all"]').text()).toBe('Any customer');
        await wrapper.findAll('[data-testid="picker-option"]')[0]?.trigger('mousedown');
        await wrapper.setProps({ modelValue: 'c1' });

        await wrapper.find('[data-testid="picker-clear"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['']);
        expect((wrapper.find('[data-testid="picker"]').element as HTMLInputElement).value).toBe('');

        await wrapper.setProps({ modelValue: 'c1' });
        await open(wrapper);
        await wrapper.find('[data-testid="picker-all"]').trigger('mousedown');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['']);
    });

    it('has no all option and no clear button without an all label', async () => {
        const wrapper = render(server(), { modelValue: 'c1' });
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-all"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="picker-clear"]').exists()).toBe(false);
    });

    it('shows a placeholder of its own and hands id, disabled and aria-invalid to the input (a required field)', async () => {
        // F-08 · 1.3: a form's picker has no all label; its placeholder is
        // the form's, and the surrounding `<label for>`, `saving` and the
        // 422 state reach the input the way they reached the `<select>`.
        const wrapper = render(server(), { placeholder: 'Choose the customer', id: 'f-customer', disabled: true, 'aria-invalid': 'true' });
        const input = wrapper.find('[data-testid="picker"]');

        expect(input.attributes('placeholder')).toBe('Choose the customer');
        expect(input.attributes('id')).toBe('f-customer');
        expect(input.attributes('disabled')).toBeDefined();
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('[data-testid="picker-clear"]').exists()).toBe(false);
    });

    it('empties the box when the value is emptied from outside', async () => {
        const wrapper = render(server());
        await open(wrapper);
        await wrapper.findAll('[data-testid="picker-option"]')[0]?.trigger('mousedown');
        await wrapper.setProps({ modelValue: 'c1' });

        await wrapper.setProps({ modelValue: '' });

        expect((wrapper.find('[data-testid="picker"]').element as HTMLInputElement).value).toBe('');
    });

    it('speaks Arabic in Arabic', async () => {
        vi.stubGlobal('fetch', server(() => refused(403)));
        const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages: { en, ar } });
        const wrapper = mount(CustomerPicker, {
            props: { modelValue: '', testId: 'picker' },
            global: { plugins: [i18n] },
        });
        await open(wrapper);

        expect(wrapper.find('[data-testid="picker-state"]').text()).toBe('لا تملك صلاحية عرض العملاء');
    });
});
