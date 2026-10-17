// Lesson 15: a screen the role may not open → the 403 page.
module.exports = async (page, h) => {
    for (const l of ['ar', 'en']) {
        await h.go('/customers'); await h.lang(l);
        await h.go('/users'); await h.sleep(800);
        console.log(page.url().replace(h.BASE, ''), (await page.$eval('main', (m) => m.innerText)).slice(0, 200).replace(/\s+/g, ' '));
        const link = await page.$('main a, main button');
        await h.shot('16-forbidden', l, link || 'main h1');
    }
};
