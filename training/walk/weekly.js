// Lesson 14: the weekly report (created by the system). View only — nothing edited.
module.exports = async (page, h) => {
    for (const l of ['ar', 'en']) {
        await h.go('/reports'); await h.lang(l);
        const row = (await page.$$(h.tid('report-row'))).find(async () => true);
        const rows = await page.$$(h.tid('report-row'));
        let wk = null;
        for (const r of rows) if ((await r.evaluate((e) => e.innerText)).includes('RPT-WK')) { wk = r; break; }
        await h.shot('15-weekly-list', l, wk);
        await (await wk.$('a')).click();
        await page.waitForSelector(h.tid('report-section-weekly-daily'));
        await page.waitForNetworkIdle(); await h.sleep(600);
        await h.shot('15b-weekly-detail', l, h.tid('report-section-weekly-daily'));
    }
};
