// Lesson 13: daily report — follow-up, note, recipient, submit. EN shots first (no edits), then AR edits + submit.
async function pick(page, h, id, text) {
    await page.click(h.tid(id)); await page.type(h.tid(id), text);
    await page.waitForSelector(h.tid(id + '-option')); await h.sleep(500);
    await page.click(h.tid(id + '-option')); await h.sleep(300);
}
module.exports = async (page, h) => {
    await h.go('/reports');
    await h.lang('en');
    await h.shot('14-report-new', 'en', h.tid('reports-new'));
    await h.lang('ar');
    await h.shot('14-report-new', 'ar', h.tid('reports-new'));
    await page.click(h.tid('reports-new'));
    await page.waitForSelector(h.tid('report-follow-up-add'));
    const reportUrl = page.url();
    console.log('report', reportUrl);
    await page.click(h.tid('report-follow-up-add')); await h.sleep(500);
    await pick(page, h, 'report-follow-up-customer', 'شركة النور التجريبية');
    await page.type(h.tid('report-follow-up-note'), 'متابعة تجريبية: العميل استلم عرض السعر — Demo follow-up');
    await page.click(h.tid('report-note-add')); await h.sleep(500);
    await page.type(h.tid('report-note-body'), 'ملاحظة تجريبية — Demo note');
    await page.click('h1'); await h.sleep(2500); // let autosave run
    for (const l of ['en', 'ar']) {
        await page.goto(reportUrl, { waitUntil: 'networkidle0' });
        await h.lang(l);
        await page.waitForSelector(h.tid('report-follow-up-row'));
        await h.shot('14b-report-follow-up', l, h.tid('report-follow-up-add'));
        await h.shot('14c-report-note', l, h.tid('report-note-add'));
        await page.click(h.tid('recipient-option') + ' input');
        await h.shot('14d-report-recipients', l, h.tid('recipient-option'));
        await h.shot('14e-report-submit', l, h.tid('report-submit'));
    }
    console.log('recipients:', await page.$$eval(h.tid('recipient-option'), (r) => r.map((x) => x.innerText)));
    await page.click(h.tid('report-submit'));
    await h.sleep(800);
    if (await page.$(h.tid('confirm-accept'))) await page.click(h.tid('confirm-accept'));
    await page.waitForNetworkIdle(); await h.sleep(1200);
    console.log('status', await h.text(h.tid('report-status')));
    for (const l of ['en', 'ar']) { await h.lang(l); await h.shot('14f-report-sent', l, h.tid('report-status')); }
};
