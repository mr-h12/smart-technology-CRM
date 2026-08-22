import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

// No font plugin, on purpose. The skeleton shipped one that fetches a typeface
// from bunny.net at build time, which is an outside dependency for a system §1
// puts on company premises. Design System §4.1's three families are self-hosted
// instead: the woff2 files live in resources/fonts and are declared face by face
// in resources/css/fonts.css, so Vite fingerprints and emits them like any other
// asset and nothing reaches a network at build time or at page load.
//
// The OS fonts installed by docker/php/Dockerfile are a different path, for the
// headless Chrome that renders PDFs. Neither substitutes for the other.
export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        // The browser reaches Vite through the published port, not through the
        // container network, so HMR has to be told where to connect back to.
        hmr: { host: 'localhost' },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
