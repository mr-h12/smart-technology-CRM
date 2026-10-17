// Lessons 3–4: sidebar, empty search, new customer, similar-customer warning.
const NAMES = { ar: ['شركة النور التجريبية', 'شركة النور التجريبيه'], en: ['Demo Al-Nour Co.', 'Demo Al Nour Co'] };
const SEARCH = { ar: 'التجريبي', en: 'Demo Al' };
module.exports = async (page, h) => {
    for (const l of ['ar', 'en']) {
        await h.go('/customers');
        await h.lang(l);
        // search first so only demo rows (or none) are on screen
        await page.type(h.tid('customers-search'), 'zz-demo-nothing');
        await page.keyboard.press('Enter');
        await page.waitForNetworkIdle();
        await h.shot('03-sidebar', l, '[data-testid="sidebar"] a[href="/customers"]');
        await h.shot('14-empty', l, h.tid('customers-search'));
        // new customer
        await page.click(h.tid('customers-create'));
        await page.waitForSelector(h.tid('customer-form-name'));
        await page.type(h.tid('customer-form-name'), NAMES[l][0]);
        await h.shot('04-customer-form', l, h.tid('customer-form-name'), { modal: true });
        await page.click(h.tid('customer-form-save'));
        await page.waitForNetworkIdle();
        await h.sleep(800);
        console.log('after save url', page.url());
        await h.shot('04b-customer-saved', l, null);
        // similar name
        await h.go('/customers');
        await page.click(h.tid('customers-create'));
        await page.waitForSelector(h.tid('customer-form-name'));
        await page.type(h.tid('customer-form-name'), NAMES[l][1]);
        await page.click(h.tid('customer-form-save'));
        await page.waitForNetworkIdle();
        await h.sleep(800);
        const sim = await page.$(h.tid('customer-form-similar'));
        console.log('similar shown:', !!sim, page.url());
        if (sim) await h.shot('05-customer-similar', l, h.tid('customer-form-similar'));
        else await h.shot('05-customer-similar', l, null);
    }
};
