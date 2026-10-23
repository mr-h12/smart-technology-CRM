import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import SystemLimitsView from '@/pages/limits/SystemLimitsView.vue';

/**
 * Module 2, Point 5.3 — §13 screen 6, *Limits & SLAs*.
 *
 * ── Seven: §13's five, `D-75`'s sixth, `OD-08`'s seventh ───────────────────
 *
 * §13 names five — stale-deal threshold · daily report deadline · quotation
 * approval SLA · weekly review window · maximum file size — and `SystemLimit`
 * adds `identity.lockout_minutes`, which is the **only limit the documentation
 * values** and the only one with a live reader. A screen that drew §13's five
 * and omitted it would leave the one working limit uneditable. The seventh
 * is `OD-08`'s similarity threshold (Module 3 Point 3.3).
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
    'limits.quotation_approval_sla_hours': { value: null, unit: 'hours', value_type: 'integer' },
    'limits.weekly_review_window_hours': { value: null, unit: 'hours', value_type: 'integer' },
    // Module 13 · 7.4: `J-08`'s archive age (`D-127`), in whole months.
    'limits.report_archive_months': { value: null, unit: 'months', value_type: 'integer' },
    // Module 16 · 2: `J-16`'s window (`D-153`), in days.
    'quotations.expiry_alert_days': { value: null, unit: 'days', value_type: 'integer' },
    // Module 16 · 4: `J-14`'s threshold (`D-153`), a share of the files volume.
    'limits.storage_alert_percent': { value: null, unit: 'percent', value_type: 'integer' },
    'limits.max_file_size_mb': { value: null, unit: 'megabytes', value_type: 'integer' },
    'identity.lockout_minutes': { value: '30', unit: 'minutes', value_type: 'integer' },
    // `OD-08`'s seventh (Module 3 Point 3.3), the server's own order — missing
    // here until F-23 · 1.1, which is how its untranslated label went unseen.
    'limits.customer_similarity_threshold': { value: null, unit: null, value_type: 'decimal' },
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
            'limits.quotation_approval_sla_hours',
            'limits.weekly_review_window_hours',
            'limits.report_archive_months',
            'quotations.expiry_alert_days',
            'limits.storage_alert_percent',
            'limits.max_file_size_mb',
            'identity.lockout_minutes',
            'limits.customer_similarity_threshold',
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

    /** `D-135`: a day's report is due by midnight, so the screen carries no deadline field or words for one. */
    it('has no daily report deadline field, label or hint in either language', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        expect(view.find('[data-limit-key="limits.daily_report_deadline"]').exists()).toBe(false);
        expect('limits_daily_report_deadline' in en.limits.field).toBe(false);
        expect('limits_daily_report_deadline' in en.limits.hint).toBe(false);
        expect('limits_daily_report_deadline' in ar.limits.field).toBe(false);
        expect('limits_daily_report_deadline' in ar.limits.hint).toBe(false);
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
        const threshold = view.get('[data-limit-key="limits.customer_similarity_threshold"] input');

        expect(counted.attributes('type')).toBe('text');
        expect(threshold.attributes('type')).toBe('text');
        expect(counted.attributes('inputmode')).toBe('numeric');
        expect(threshold.attributes('inputmode')).toBeUndefined();
    });

    // ── Module 16 · 2: the quotation expiry alert window (`J-16`, `D-153`) ──

    /** The browser check (2026-10-22): the intro said "five of the seven" in words, and an eighth limit made it false. It counts now. */
    it('counts the limits never configured instead of naming a number in its sentence', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render();
        await flushPromises();

        const intro = view.get('[data-testid="limits-unconfigured"]').text();
        // LIMITS: nine, of which only the lockout has a value.
        expect(intro).toContain('8');
        expect(intro).toContain('9');
        expect(en.limits.subtitle).not.toMatch(/seven|five/i);
    });

    it('has the expiry alert window label and hint in both languages, Arabic in Arabic script', () => {
        const arabic = /[\u0600-\u06FF]/;
        for (const [messages, isArabic] of [[en, false], [ar, true]] as const) {
            const field = (messages.limits.field as Record<string, string>)['quotations_expiry_alert_days'] as string;
            const hint = (messages.limits.hint as Record<string, string>)['quotations_expiry_alert_days'] as string;

            for (const word of [field, hint]) {
                expect(typeof word).toBe('string');
                expect(word.length).toBeGreaterThan(0);
                expect(arabic.test(word)).toBe(isArabic);
            }
        }
    });

    it('draws the expiry alert window with its label, hint and translated unit', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        for (const [locale, messages] of [['en', en], ['ar', ar]] as const) {
            const view = render(locale);
            await flushPromises();

            const row = view.get('[data-limit-key="quotations.expiry_alert_days"]').text();

            expect(row).toContain((messages.limits.field as Record<string, string>)['quotations_expiry_alert_days']);
            expect(row).toContain((messages.limits.hint as Record<string, string>)['quotations_expiry_alert_days']);
            expect(row).toContain((messages.limits.unit as Record<string, string>)['days']);
            expect(row).not.toContain('limits.field.');
            expect(row).not.toContain('limits.hint.');
        }
    });

    // ── Module 16 · 4: the storage threshold (`J-14`, `D-153`) ──────────────

    it('draws the storage threshold with its label, hint and a translated percent unit', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        for (const [locale, messages] of [['en', en], ['ar', ar]] as const) {
            const view = render(locale);
            await flushPromises();

            const row = view.get('[data-limit-key="limits.storage_alert_percent"]').text();
            const unit = (messages.limits.unit as Record<string, string>)['percent'];

            expect(typeof unit).toBe('string');
            expect(row).toContain((messages.limits.field as Record<string, string>)['limits_storage_alert_percent']);
            expect(row).toContain((messages.limits.hint as Record<string, string>)['limits_storage_alert_percent']);
            expect(row).toContain(unit);
            expect(row).not.toContain('limits.');
        }
    });

    // ── 7.4: the report archive age (`J-08`, `D-127`) ──────────────────────

    /** The label, the hint and the unit word are written once per language: a missing key would print its path in front of a person. */
    it('has the archive age label, hint and unit in both languages, Arabic in Arabic script', () => {
        const arabic = /[\u0600-\u06FF]/;
        for (const [messages, isArabic] of [[en, false], [ar, true]] as const) {
            const field = (messages.limits.field as Record<string, string>)['limits_report_archive_months'] as string;
            const hint = (messages.limits.hint as Record<string, string>)['limits_report_archive_months'] as string;
            const unit = (messages.limits.unit as Record<string, string>)['months'] as string;

            for (const word of [field, hint, unit]) {
                expect(typeof word).toBe('string');
                expect(word.length).toBeGreaterThan(0);
                expect(arabic.test(word)).toBe(isArabic);
            }
        }
    });

    it('draws the archive age with its label, hint and translated unit', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        for (const [locale, messages] of [['en', en], ['ar', ar]] as const) {
            const view = render(locale);
            await flushPromises();

            const row = view.get('[data-limit-key="limits.report_archive_months"]').text();

            expect(row).toContain((messages.limits.field as Record<string, string>)['limits_report_archive_months']);
            expect(row).toContain((messages.limits.hint as Record<string, string>)['limits_report_archive_months']);
            expect(row).toContain((messages.limits.unit as Record<string, string>)['months']);
            expect(row).not.toContain('limits.field.');
            expect(row).not.toContain('limits.hint.');
            expect(row).not.toContain('limits.unit.');
        }
    });

    it('translates the months unit rather than printing the word the server sent', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        const view = render('ar');
        await flushPromises();

        const unit = view.get('[data-limit-key="limits.report_archive_months"] [data-testid="limits-unit"]').text();

        expect(unit).toBe((ar.limits.unit as Record<string, string>)['months']);
        expect(unit).not.toBe('months');
    });

    it('saves the archive age as a string and adopts what the server returned', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? envelope({ ...LIMITS, 'limits.report_archive_months': { value: '9', unit: 'months', value_type: 'integer' } })
                : envelope(LIMITS),
        );
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await flushPromises();

        const input = view.get('[data-limit-key="limits.report_archive_months"] input');
        expect(input.attributes('inputmode')).toBe('numeric');
        await input.setValue('9');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');
        expect(JSON.parse(patch![1]?.body as string)).toEqual({ limits: { 'limits.report_archive_months': '9' } });
        expect(view.find('[data-testid="limits-saved"]').exists()).toBe(true);
    });

    it('reports a refusal of the archive age against its own row', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'Please correct the highlighted fields.',
                        details: [{ field: 'limits.limits.report_archive_months', code: 'invalid', message: 'The report archive age field format is invalid.' }],
                    },
                })
                : envelope(LIMITS),
        ));

        const view = render();
        await flushPromises();

        await view.get('[data-limit-key="limits.report_archive_months"] input').setValue('0');
        await view.get('[data-testid="limits-save"]').trigger('submit');
        await flushPromises();

        expect(view.get('[data-limit-key="limits.report_archive_months"]').text()).toContain('The report archive age field format is invalid.');
        expect(view.get('[data-limit-key="limits.stale_deal_days"]').text()).not.toContain('The report archive age');
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

        // 9 since Module 16 · 4's `limits.storage_alert_percent` (`D-153`).
        expect(fields).toHaveLength(9);

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

    /** F-23 · 1.1 — the label was the raw key `limits.field.limits_customer_similarity_threshold`. */
    it('labels the similarity threshold in words in both locales', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => envelope(LIMITS)));

        for (const [locale, words, messages] of [['en', 'Similar-name threshold', en], ['ar', 'حدّ تشابه الأسماء', ar]] as const) {
            const view = render(locale);
            await flushPromises();

            const row = view.get('[data-limit-key="limits.customer_similarity_threshold"]');

            expect(row.text()).toContain(words);
            expect(row.text()).not.toContain('limits.');
            // Exact, and in each locale's own words: a missing Arabic hint falls
            // back to the English one, which a prefix check cannot tell apart.
            expect(row.get('[data-testid="limit-hint"]').text())
                .toBe(messages.limits.hint.limits_customer_similarity_threshold);
            view.unmount();
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
