import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ErrorState from '@/components/states/ErrorState.vue';

/** F-42 · 5.17 (A-43, the owner's Option B): the cause in a translated sentence, the next step as a button under it. */
function render(locale: 'en' | 'ar') {
    return mount(ErrorState, {
        props: { messageKey: 'state.error.network' },
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

describe('ErrorState', () => {
    it.each([['en' as const, en], ['ar' as const, ar]])('says a lost connection in %s and offers a button to try again', (locale, messages) => {
        const view = render(locale);

        expect(messages.state.error.network).toBeTruthy();
        expect(view.text()).toContain(messages.state.error.network);
        expect(view.find('button[data-testid="error-retry"]').text()).toBe(messages.state.retry);
        expect(view.text()).not.toContain('Failed to fetch');
    });
});
