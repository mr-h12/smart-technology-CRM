import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ForbiddenView from '@/pages/ForbiddenView.vue';
import { createAppRouter } from '@/router';

/** F-42 · 5.17 (A-43): /403 names nothing about what was refused, and still offers a way onward. */
describe('ForbiddenView', () => {
    it('offers a link to the home page under the denial', () => {
        const view = mount(ForbiddenView, {
            global: { plugins: [createAppRouter(), createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })] },
        });

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        const home = view.find('a[data-testid="forbidden-home"]');
        expect(home.attributes('href')).toBe('/');
        expect(home.text()).toBe(en.state.denied.home);
    });
});
