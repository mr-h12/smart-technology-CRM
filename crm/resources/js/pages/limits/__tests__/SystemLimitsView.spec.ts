import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import SystemLimitsView from '@/pages/limits/SystemLimitsView.vue';

/**
 * Module 2, Point 5.3 — §13 screen 6, *Limits & SLAs*.
 *
 * ── Six, and the sixth is `D-75`'s ─────────────────────────────────────────
 *
 * §13 names five — stale-deal threshold · daily report deadline · quotation
 * approval SLA · weekly review window · maximum file size — and `SystemLimit`
 * adds `identity.lockout_minutes`, which is the **only limit the documentation
 * values** and the only one with a live reader. A screen that drew §13's five
 * and omitted it would leave the one working limit uneditable.
 *
 * ── The server owns the list, the order and the units ──────────────────────
 *
 * `DatabaseSystemLimitRepository::all()` iterates `SystemLimit::cases()`, so
 * the response is the whole enum in §13's order with each limit's `unit` and
 * `value_type` beside it. Nothing here restates that list: a second copy in the
 * client is the copy that goes stale, and §13 screen 6 mixes days, hours and
 * megabytes on one form — which is why `system_limits` has a `unit` column at
 * all.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 · `SEC-09`. `SystemLimitEndpointTest` proves the refusals
 * against the server, including that the **Manager** is refused: §3.11 lists
 * *system limits (SLAs, thresholds)* as its own row, one line below *system
 * settings*. What is proved here is what the screen draws and sends.
 */

const LIMITS = {
    'limits.stale_deal_days': { value: null, unit: 'days', value_type: 'integer' },
    'limits.daily_report_deadline': { value: null, unit: null, value_type: 'string' },
    'limits.quotation_approval_sla_hours': { value: null, unit: 'hours', value_type: 'integer' },
    'limits.weekly_review_window_hours': { value: null, unit: 'hours', value_type: 'integer' },
    'limits.max_file_size_mb': { value: null, unit: 'megabytes', value_type: 'integer' },
    'identity.lockout_minutes': { value: '30', unit: 'minutes', value_type: 'integer' },
};

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function envelope(limits: Record<string, unknown>): Response {
    return json(200, { data: { limits }, meta: { request_id: 'req_test' } });
}

function render(locale = 'en') {
    return mount(SystemLimitsView, {
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('SystemLimitsView', () => {
    it('shows the loading state before the limits arrive', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        expect(render().find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    /** §13's five plus `D-75`'s, in the order the server sent them. */
    it('renders every limit the API sends, in the order it sent them', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const fields = view.findAll('[data-limit-key]');

        expect(fields.map((f) => f.attributes('data-limit-key'))).toEqual([
            'limits.stale_deal_days',
            'limits.daily_report_deadline',
            'limits.quotation_approval_sla_hours',
            'limits.weekly_review_window_hours',
            'limits.max_file_size_mb',
            'identity.lockout_minutes',
        ]);
    });

    /**
     * §13 screen 6 mixes days, hours and megabytes on one form. A threshold in
     * days cannot be shown beside an SLA in hours without saying which is
     * which — and the unit is the **server's**, translated here rather than
     * printed as the English word it arrives as.
     */
    it('shows each limit its unit', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        expect(view.get('[data-limit-key="limits.stale_deal_days"]').text()).toContain(en.limits.unit.days);
        expect(view.get('[data-limit-key="identity.lockout_minutes"]').text()).toContain(en.limits.unit.minutes);
    });

    /**
     * ⚠️ **Asserted in Arabic, and that is the whole point of this case.** The
     * English labels for `days` and `minutes` are the same words the server
     * sends, so an English assertion passes just as happily on the raw payload
     * value — it cannot tell a translated unit from an untranslated one.
     * Arabic can: `megabytes` becomes ميغابايت and the English word must be gone.
     */
    it('translates the unit rather than printing the word the server sent', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render('ar');
        await flushPromises();

        const row = view.get('[data-limit-key="limits.max_file_size_mb"]').text();

        expect(row).toContain(ar.limits.unit.megabytes);
        expect(row).not.toContain('megabytes');
    });

    /** A time of day has no unit — the value *is* the reading. */
    it('shows no unit for the daily report deadline', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        expect(view.get('[data-limit-key="limits.daily_report_deadline"]')
            .find('[data-testid="limits-unit"]').exists()).toBe(false);
    });

    /** Null is "not configured yet", which Point 1.1 made a real state. */
    it('renders an unvalued limit as empty rather than as a literal', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const input = view.get('[data-limit-key="limits.max_file_size_mb"] input');

        expect((input.element as HTMLInputElement).value).toBe('');
    });

    it('fills the one limit the documentation values', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const input = view.get('[data-limit-key="identity.lockout_minutes"] input');

        expect((input.element as HTMLInputElement).value).toBe('30');
    });

    /**
     * `type="text"` on all of them. The value is stored and validated as a
     * **string** — `SystemLimit::rule()` is a `regex` on the digits, not
     * `integer` — and a number input hands back a JavaScript number.
     * `value_type` drives the keypad and nothing else.
     */
    it('binds every limit to a text control and takes the keypad from value_type', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const counted = view.get('[data-limit-key="identity.lockout_minutes"] input');
        const deadline = view.get('[data-limit-key="limits.daily_report_deadline"] input');

        expect(counted.attributes('type')).toBe('text');
        expect(deadline.attributes('type')).toBe('text');
        expect(counted.attributes('inputmode')).toBe('numeric');
        expect(deadline.attributes('inputmode')).toBeUndefined();
    });

    // ── saving ─────────────────────────────────────────────────────────────

    it('sends only the limits that were edited', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? envelope({ ...LIMITS, 'identity.lockout_minutes': { value: '45', unit: 'minutes', value_type: 'integer' } })
                : envelope(LIMITS),
        );
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="identity.lockout_minutes"] input').setValue('45');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(patch).toBeDefined();
        expect(JSON.parse(patch![1]?.body as string)).toEqual({
            limits: { 'identity.lockout_minutes': '45' },
        });
    });

    it('does not submit at all when nothing was edited', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => envelope(LIMITS));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false);
    });

    it('sends the value as a string', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? envelope({ ...LIMITS, 'limits.stale_deal_days': { value: '14', unit: 'days', value_type: 'integer' } })
                : envelope(LIMITS),
        );
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="limits.stale_deal_days"] input').setValue('14');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');
        const body = JSON.parse(patch![1]?.body as string);

        expect(body.limits['limits.stale_deal_days']).toBe('14');
        expect(typeof body.limits['limits.stale_deal_days']).toBe('string');
    });

    it('confirms the save and adopts what the server returned', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? envelope({ ...LIMITS, 'identity.lockout_minutes': { value: '60', unit: 'minutes', value_type: 'integer' } })
                : envelope(LIMITS),
        ));

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="identity.lockout_minutes"] input').setValue('45');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="limits-saved"]').exists()).toBe(true);
        expect((view.get('[data-limit-key="identity.lockout_minutes"] input').element as HTMLInputElement).value)
            .toBe('60');
    });

    /**
     * The field path is the **submitted** one, `limits.` prefix and all —
     * measured against the running server, where a refusal on
     * `limits.stale_deal_days` comes back as `limits.limits.stale_deal_days`.
     */
    it('reports a validation refusal against the limit it names', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'Please correct the highlighted fields.',
                        details: [{
                            field: 'limits.limits.stale_deal_days',
                            code: 'invalid',
                            message: 'The stale-deal threshold field format is invalid.',
                        }],
                    },
                })
                : envelope(LIMITS),
        ));

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="limits.stale_deal_days"] input').setValue('quite a while');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="limits-saved"]').exists()).toBe(false);
        expect(view.get('[data-limit-key="limits.stale_deal_days"]').text())
            .toContain('The stale-deal threshold field format is invalid.');
    });

    it('keeps the edit on screen when the save is refused', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH' ? json(500, { error: { code: 'server_error' } }) : envelope(LIMITS),
        ));

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="identity.lockout_minutes"] input').setValue('45');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        expect((view.get('[data-limit-key="identity.lockout_minutes"] input').element as HTMLInputElement).value)
            .toBe('45');
    });

    // ── the states a screen is not complete without ────────────────────────

    it('shows the permission-denied state on a 403', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'permission_denied' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
    });

    it('shows the error state and offers a retry when the reading fails', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(500, { error: { code: 'server_error' } })));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="error-retry"]').exists()).toBe(true);
    });

    it('gives every limit an explanation, associated with its control', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const fields = view.findAll('[data-limit-key]');

        expect(fields).toHaveLength(6);

        const hints: Record<string, string> = en.limits.hint;

        for (const field of fields) {
            const key = field.attributes('data-limit-key') ?? '';
            const hint = field.find('[data-testid="limit-hint"]');

            expect(hint.exists()).toBe(true);
            // The exact string, for the reason `SystemSettingsView.spec` records:
            // a key that resolves to itself passes a prefix check.
            expect(hint.text()).toBe(hints[key.replace('.', '_')]);
            expect(field.get('input').attributes('aria-describedby')).toBe(`hint-${key}`);
        }
    });

    // ── §14.2 ──────────────────────────────────────────────────────────────

    /** The page owns the `<h1>`; this is a section of it since S-02. */
    it('renders no page-level heading of its own', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        expect(view.findAll('h1')).toHaveLength(0);
        expect(view.get('[data-testid="limits-heading"]').element.tagName).toBe('H2');
    });

    it('has no hard-coded English when the locale is Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render('ar');
        await flushPromises();

        const heading = view.get('[data-testid="limits-heading"]').text();

        expect(heading).toBe(ar.limits.title);
        expect(heading).not.toBe(en.limits.title);
    });
});
