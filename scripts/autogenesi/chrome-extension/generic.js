(async () => {
  const url = new URL(location.href);
  url.hash = '';
  if (url.protocol !== 'https:' || url.username || url.password || url.port) throw new Error('Apri una scheda prodotto HTTPS pubblica.');
  if (/unusual traffic|verify you are human|verifica que eres humano/i.test(document.body.innerText)) throw new Error('Il sito richiede una verifica. Acquisizione fermata.');
  const nodes = [];
  const visit = value => {
    if (!value || typeof value !== 'object') return;
    if (Array.isArray(value)) return value.forEach(visit);
    nodes.push(value);
    if (value['@graph']) visit(value['@graph']);
    if (value.mainEntity) visit(value.mainEntity);
  };
  for (const el of document.querySelectorAll('script[type="application/ld+json"]')) {
    try { visit(JSON.parse(el.textContent)); } catch {}
  }
  const type = (p, name) => [p['@type']].flat().includes(name);
  const candidates = nodes.filter(p => type(p, 'ProductGroup') || type(p, 'Product'));
  const matchesPage = p => {
    try { return new URL(p.url || p['@id'], url).pathname === url.pathname; } catch { return false; }
  };
  const product = candidates.find(p => type(p, 'ProductGroup') && matchesPage(p)) || candidates.find(matchesPage) || (candidates.length === 1 ? candidates[0] : null);
  if (!product) throw new Error('Scheda non riconosciuta: il sito non espone dati Product/JSON-LD leggibili.');
  const text = html => { const el = document.createElement('div'); el.innerHTML = String(html || ''); el.querySelectorAll('script,style,iframe').forEach(n => n.remove()); return el.textContent.trim(); };
  const money = value => {
    const s = String(value ?? '').trim();
    if (!/^\d+(?:[.,]\d{1,2})?$/.test(s) || Number(s.replace(',', '.')) <= 0) throw new Error('Prezzo esatto non disponibile. Non sono ammessi prezzi a partire da o intervalli.');
    return s.replace(',', '.');
  };
  const variants = [];
  const products = product.hasVariant ? [product.hasVariant].flat() : [product];
  for (const p of products) {
    if (!p || typeof p !== 'object') throw new Error('Varianti non leggibili in questa pagina.');
    const offers = [p.offers || []].flat();
    for (const offer of offers) {
      if (!offer || type(offer, 'AggregateOffer')) throw new Error('Sono disponibili solo prezzi aggregati: apri la variante specifica.');
      if (/OutOfStock|Discontinued|SoldOut/.test(offer.availability || '')) continue;
      const currency = offer.priceCurrency || offer.priceSpecification?.priceCurrency;
      if (currency !== 'EUR') throw new Error('Imposta il sito in EUR prima di acquisire.');
      if (offers.length > 1 && !offer.name && !offer.sku) throw new Error('Nomi delle varianti non leggibili: apri la variante specifica.');
      const label = text(offers.length > 1 ? `${p.name || product.name} / ${offer.name || offer.sku}` : p.name || product.name);
      if (/360|only frame|frame only|solo marco|sin radio|no radio|cable only/i.test(label)) continue;
      variants.push({ label, supplier_price: money(offer.price ?? offer.priceSpecification?.price), currency: 'EUR', observed_at: new Date().toISOString(), options: [] });
    }
  }
  if (!variants.length || variants.length > 12) throw new Error('Servono da 1 a 12 varianti con prezzi esatti leggibili.');
  const absolute = raw => {
    const value = typeof raw === 'string' ? raw : raw?.contentUrl || raw?.url;
    if (!value) return null;
    try { const u = new URL(value, url); return u.protocol === 'https:' && !u.username && !u.password && !u.port ? u.href : null; } catch { return null; }
  };
  const root = document.querySelector('[itemprop="description"],.product__description,.product-description,#tab-description,.woocommerce-Tabs-panel--description');
  const clone = root?.cloneNode(true);
  clone?.querySelectorAll('script,style,iframe,form,input,button').forEach(el => el.remove());
  const description = clone?.textContent.trim() || text(product.description);
  if (!description) throw new Error('Descrizione prodotto non disponibile. Espandi la descrizione e riprova.');
  const gallery = [...new Set([product.image || [], ...products.map(p => p.image || [])].flat(2).map(absolute).filter(Boolean))].slice(0, 12);
  if (gallery.length < 3) {
    for (const el of document.querySelectorAll('[itemprop="image"],.product__media img,.woocommerce-product-gallery img,.product-gallery img')) {
      const u = absolute(el.getAttribute('data-large_image') || el.getAttribute('data-src') || el.currentSrc || el.src || el.content);
      if (u && !gallery.includes(u)) gallery.push(u);
      if (gallery.length >= 12) break;
    }
  }
  if (gallery.length < 3) throw new Error('Servono almeno tre immagini prodotto leggibili.');
  const specifications = [product.additionalProperty || []].flat().filter(p => p?.name && p?.value != null).map(p => ({ name: text(p.name), value: text(p.value) }));
  for (const row of document.querySelectorAll('.woocommerce-product-attributes tr,.product-specifications tr,[itemprop="additionalProperty"]')) {
    const name = row.querySelector('th,[itemprop="name"]')?.textContent.trim();
    const value = row.querySelector('td,[itemprop="value"]')?.textContent.trim();
    if (name && value && !specifications.some(s => s.name === name)) specifications.push({ name, value });
  }
  const image_urls = [...new Set([...(root?.querySelectorAll('img') || [])].map(el => absolute(el.getAttribute('data-src') || el.src)).filter(Boolean))];
  const source_title = text(product.name) || document.querySelector('h1')?.innerText.trim();
  if (!source_title || description.length > 120000 || (clone?.innerHTML.length || 0) > 250000 || image_urls.length > 60 || specifications.length > 200) throw new Error('Dati prodotto mancanti o troppo grandi.');
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(url.href));
  const hex = [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
  return { source_platform: 'web', source_id: '9' + BigInt('0x' + hex.slice(0, 15)).toString(), source_url: url.href, source_title,
    variants, gallery: gallery.slice(0, 12), captured_at: new Date().toISOString(), source_text: source_title + '\n' + description,
    description: { text: description, html: clone?.innerHTML || '', specifications, image_urls, complete: true },
    warnings: ['Acquisizione generica da dati pubblicati nella pagina; verificare varianti, compatibilità e dotazione.', ...(!specifications.length ? ['Tabella specifiche non disponibile nella pagina.'] : [])] };
})();
