import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

// The skeleton's `bunny('Instrument Sans')` font plugin is removed on purpose.
// It fetches a typeface from bunny.net at build time, which is an outside
// dependency for a system §1 puts on company premises, and Design System §4.1
// names Inter, Noto Sans Arabic and Noto Sans Mono rather than Instrument Sans.
// Those three are already installed in the container image (point 0.4).
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
