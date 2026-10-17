import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ReportStatusChip from '../ReportStatusChip.vue';

/**
 * Module 13, slice 7 · 7.1 — one status chip for a report, replacing the four
 * inline spans in ReportsView, ReceivedReportsView and ReportDetailView.
 */

function render(status: string, locale = 'ar') {
    return mount(ReportStatusChip, {
        props: { status },
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

describe('ReportStatusChip', () => {
    it('renders the status word from reports.status.* in Arabic and English', () => {
        expect(render('draft', 'ar').text()).toBe(ar.reports.status.draft);
        expect(render('draft', 'en').text()).toBe(en.reports.status.draft);
    });

    it('marks only missed in the warning tone', () => {
        const missed = render('missed').classes();
        const submitted = render('submitted').classes();

        expect(missed).toContain('text-[var(--color-warning)]');
        expect(submitted).not.toContain('text-[var(--color-warning)]');
        expect(missed).toContain('rounded-full');
        expect(submitted).toContain('rounded-full');
    });
});
