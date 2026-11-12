(() => {
  if (location.protocol !== 'https:' || !/(^|\.)dsers\.com$/.test(location.hostname)) throw new Error('Apri My Products su DSers.');
  const rows = [], seen = new Set();
  const supplier = a => {
    try {
      const u = new URL(a.href);
      return /(^|\.)aliexpress\.com$/.test(u.hostname) && /^\/item\/\d+\.html$/.test(u.pathname)
        ? `https://es.aliexpress.com${u.pathname}` : null;
    } catch { return null; }
  };
  let unpaired = 0;
  for (const a of document.querySelectorAll('a[href]')) {
    const url = supplier(a);
    if (!url) continue;
    let paired = false;
    for (let box = a.parentElement, depth = 0; box && depth < 7 && box !== document.body; box = box.parentElement, depth++) {
      const links = [...box.querySelectorAll('a[href]')];
      const ids = [...new Set(links.flatMap(x => {
        try {
          const u = new URL(x.href);
          const m = /^\/store\/[^/]+\/products\/(\d+)(?:\/|$)/.exec(u.pathname);
          return u.hostname === 'admin.shopify.com' && m ? [m[1]] : [];
        } catch { return []; }
      }))];
      const titles = [...new Set([...box.querySelectorAll('h2,h3,h4,[class*="product-title"],[class*="productTitle"],[class*="product-name"],[class*="productName"]')]
        .filter(x => !x.closest('a[href*="aliexpress"]'))
        .map(x => (x.getAttribute('title') || x.textContent || '').trim()).filter(x => x.length >= 8 && x.length <= 1000))];
      if (ids.length > 1 || titles.length > 1) break;
      if (ids.length === 1 || titles.length === 1) {
        const row = { shopify_product_id: ids[0] || null, title_es: titles[0] || '', supplier_url: url };
        const key = JSON.stringify(row);
        if (!seen.has(key)) { seen.add(key); rows.push(row); }
        paired = true; break;
      }
    }
    if (!paired) unpaired++;
  }
  return { format: 'autogenesi.dsers.v1', captured_at: new Date().toISOString(), page_url: location.origin + location.pathname, rows, unpaired,
    warning: 'Solo associazioni presenti nella pagina caricata. Nessuna ricerca di nuovi fornitori.' };
})();
