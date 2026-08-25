import { createApp } from 'vue';
import { i18n } from '@/i18n';
import App from '@/App.vue';
import { createAppRouter } from '@/router';
import { installAuthTransport } from '@/stores/auth';

const router = createAppRouter();

// D-29 / SEC-05: the server is the authority on whether a session still exists,
// and a 401 is how it says otherwise — an expired idle session, a device
// revoked from another machine, or an account deactivated mid-shift (§10.1).
// The store clears itself and this puts the person on the login screen instead
// of leaving them looking at a page whose every request now fails.
//
// `replace`, not `push`: the screen they were on is gone, and Back should not
// return to it. `catch` because a navigation the guard redirects again is a
// rejected promise, and an unhandled rejection here would be noise in the
// console on every expiry.
installAuthTransport(() => {
    void router.replace({ name: 'login' }).catch(() => {});
});

createApp(App).use(router).use(i18n).mount('#app');
