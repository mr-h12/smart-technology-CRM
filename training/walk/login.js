// Signs one persona in ONCE. Reads the password from .env; never prints it.
// Also captures the sign-in screen (password empty) and one failed sign-in for a non-existent demo address.
const fs = require('fs');
module.exports = async (page, h, email, capture) => {
    const env = fs.readFileSync('/var/www/html/.env', 'utf8');
    const m = env.match(/^SEED_TEST_USER_PASSWORD=(.*)$/m);
    if (!m) throw new Error('password key missing');
    const pw = m[1].trim().replace(/^["']|["']$/g, '');
    await h.go('/login');
    if (capture) {
        for (const l of ['ar', 'en']) {
            await h.lang(l);
            await page.type(h.tid('login-email'), 'demo.nobody@example.test');
            await page.type(h.tid('login-password'), 'Demo1234x');
            await page.click(h.tid('login-submit'));
            await page.waitForSelector(h.tid('login-error'));
            await h.shot('02-login-error', l, h.tid('login-error'));
            await h.go('/login');
            await page.type(h.tid('login-email'), email);
            await h.shot('01-login', l, h.tid('login-submit'));
            await h.go('/login');
        }
        await h.lang('ar');
    }
    await page.type(h.tid('login-email'), email);
    await page.type(h.tid('login-password'), pw);
    await page.click(h.tid('login-submit'));
    await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
    const url = page.url();
    if (url.includes('/login')) { console.log('SIGN-IN FAILED — stopping, no retry'); return; }
    console.log('signed in, landed on', url.replace(h.BASE, ''));
};
