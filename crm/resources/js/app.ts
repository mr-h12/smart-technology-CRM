import { createApp } from 'vue';
import { i18n } from '@/i18n';
import { createRouter, createWebHistory } from 'vue-router';
import App from '@/App.vue';
import Ping from '@/pages/Ping.vue';

// History mode, not hash: nginx already sends every unmatched path to Laravel,
// which returns the SPA shell, so deep links work without a fragment.
const router = createRouter({
    history: createWebHistory(),
    // meta.titleKey is what the context bar reads (§5.1). Keeping the title
    // on the route rather than inside each page means one place decides it.
    routes: [{ path: '/', name: 'home', component: Ping, meta: { titleKey: 'nav.item.home' } }],
});

createApp(App).use(router)
    .use(i18n).mount('#app');
