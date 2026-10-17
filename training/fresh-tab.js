// Replaces every tab of the Chromium on <port> with one blank tab (unsticks a pending beforeunload).
(async () => {
    const b = `http://127.0.0.1:${process.argv[2]}`;
    const list = await (await fetch(b + '/json/list')).json();
    await fetch(b + '/json/new?about:blank', { method: 'PUT' });
    for (const t of list) if (t.type === 'page') { await fetch(b + '/json/close/' + t.id); console.log('closed', t.url); }
})();
