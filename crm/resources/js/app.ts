import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from '@/App.vue';
import Ping from '@/pages/Ping.vue';

// History mode, not hash: nginx already sends every unmatched path to Laravel,
// which returns the SPA shell, so deep links work without a fragment.
const router = createRouter({
    history: createWebHistory(),
    routes: [{ path: '/', name: 'home', component: Ping }],
});

createApp(App).use(router).mount('#app');
