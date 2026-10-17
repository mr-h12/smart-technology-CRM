// Lesson 5: new deal from the customer page.
const C = { ar: '01a147e2-2e39-72e2-ac28-30b572d0442b', en: '01a147e2-5276-70f5-ac7b-a5f37c9e9024' };
const T = { ar: ['توريد أجهزة كمبيوتر — تجريبي', 'طلب تجريبي: 10 أجهزة لاب توب للمكتب الجديد'], en: ['Demo laptop supply', 'Demo request: 10 laptops for the new office'] };
module.exports = async (page, h, l) => {
    await h.go('/customers/' + C[l]);
    await h.lang(l);
    await h.shot('06-deal-new', l, h.tid('customer-new-deal'));
    await page.click(h.tid('customer-new-deal'));
    await page.waitForSelector(h.tid('deal-form-title'));
    await page.type(h.tid('deal-form-title'), T[l][0]);
    await page.type(h.tid('deal-form-details'), T[l][1]);
    await h.shot('06b-deal-form', l, h.tid('deal-form-submit'));
    await page.click(h.tid('deal-form-submit'));
    await page.waitForNetworkIdle();
    await h.sleep(1000);
    console.log('url', page.url());
};
