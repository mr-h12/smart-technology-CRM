// Debug helper: goto path (optional), optionally click a testid, screenshot to shots/_peek.png, list visible testids.
module.exports = async (page, h, path, click, lang) => {
    if (lang) await page.evaluate((l) => localStorage.setItem('crm.locale', l), lang);
    if (path && path !== '-') await h.go(path);
    if (click && click !== '-') { await page.click(click.startsWith('[') ? click : h.tid(click)); await h.sleep(800); }
    await page.screenshot({ path: '/tmp/training/shots/_peek.png' });
    const ids = await page.$$eval('[data-testid]', (els) => els.filter((e) => e.offsetParent !== null).map((e) => e.dataset.testid));
    console.log([...new Set(ids)].join(' '));
};
