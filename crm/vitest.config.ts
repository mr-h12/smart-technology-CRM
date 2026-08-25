import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

/**
 * The SPA's unit tests.
 *
 * ── Why a second config and not vite.config.ts ─────────────────────────────
 *
 * `vite.config.ts` loads `laravel-vite-plugin`, which reads `.env`, resolves a
 * dev-server URL and writes a hot file. None of that belongs in a test run, and
 * the plugin is also what supplies the `@/` alias in the application build — so
 * the alias is declared here explicitly rather than inherited from a plugin
 * this config does not load.
 *
 * ── happy-dom, not jsdom ───────────────────────────────────────────────────
 *
 * These tests need `localStorage`, `document.documentElement.lang` and enough
 * DOM for `@vue/test-utils` to mount a form. happy-dom covers all three and
 * starts faster; nothing here needs jsdom's wider surface.
 *
 * ── DEV-07 ─────────────────────────────────────────────────────────────────
 *
 * "Testing: unit for calculations · integration for workflows · E2E for
 * critical paths." A router guard is not a calculation, but it is logic with
 * branches, and until this file existed the SPA had no way to test a branch at
 * all — `vue-tsc` proves the types and says nothing about behaviour. The CI
 * workflow runs `npm run test:unit`; a check CI does not run is a check nobody
 * reads.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.spec.ts'],
        restoreMocks: true,
    },
});
