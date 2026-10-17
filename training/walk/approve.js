// Manager: approve the two demo deals and quotations. No screenshots (the queue shows other people's records).
const CODES = process.env.CODES ? process.env.CODES.split(',') : ['QT-2026-0131', 'QT-2026-0132'];
const DEALS = ['01a147e3-62d5-71e2-95ee-2cfccf90627a', '01a147e3-7adb-70fb-a841-e1808f5c2311'];
module.exports = async (page, h) => {
    for (const d of DEALS) {
        await h.go('/deals/' + d);
        if (await page.$(h.tid('deal-approve'))) {
            await page.click(h.tid('deal-approve')); await h.sleep(600);
            if (await page.$(h.tid('confirm-accept'))) await page.click(h.tid('confirm-accept'));
            await page.waitForNetworkIdle(); console.log('deal approved', d);
        } else console.log('no deal-approve on', d);
    }
    await h.go('/approvals');
    for (const code of CODES) {
        for (const r of await page.$$(h.tid('approvals-row'))) {
            if (!(await r.evaluate((e) => e.innerText)).includes(code)) continue;
            await (await r.$(h.tid('approvals-approve'))).click(); await h.sleep(600);
            if (await page.$(h.tid('confirm-accept'))) await page.click(h.tid('confirm-accept'));
            await page.waitForNetworkIdle(); await h.sleep(800);
            console.log('approved', code);
            break;
        }
    }
};
