const { connect } = require('./browser-session.cjs');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const readDescription = require('./read-description.cjs');
const { eligibleOption } = require('./product-filter.cjs');
const assertAccessible = require('./assert-accessible.cjs');
const log = require('./import-log.cjs');

const [source, out] = process.argv.slice(2);
const allowed = host => ['alicdn.com', 'aliexpress-media.com'].some(d => host === d || host.endsWith('.' + d));
const imageUrl = value => {
  const u = new URL(value);
  if (u.protocol === 'http:' && allowed(u.hostname)) u.protocol = 'https:';
  if (u.protocol !== 'https:' || !allowed(u.hostname)) throw new Error('Image host not allowed');
  u.pathname = u.pathname.replace(/\.(jpg|jpeg|png|webp)(?:_.*)$/i, '.$1');
  u.search = '';
  return u.href;
};

(async () => {
  require('./run-control.cjs').watch();
  const url = new URL(source);
  if (url.protocol !== 'https:' || !/^(?:es|www)\.aliexpress\.com$/.test(url.hostname) || !/^\/item\/\d+\.html$/.test(url.pathname)) throw new Error('Invalid AliExpress product URL');
  fs.mkdirSync(out, { recursive: true });
  log('connessione_browser');
  const { context, disconnect } = await connect();
  const opened = [];
  let complete = false;
  try {
    const page = await context.newPage();
    opened.push(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.bringToFront();
    log('apertura_pagina', { url: url.origin + url.pathname });
    const response = await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 45000 });
    log('risposta_pagina', { status: response?.status() });
    if (!response?.ok()) throw new Error('Product page unavailable');
    await assertAccessible(page);
    log('attesa_varianti');
    await page.locator('[data-sku-col]').first().waitFor({ timeout: 20000 });
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
    for (const text of ['Rechazar cookies', 'No permitir']) {
      const button = page.getByText(text, { exact: true });
      if (await button.count() === 1 && await button.isVisible()) await button.click({ timeout: 3000 }).catch(() => {});
    }
    const initial = await page.evaluate(() => ({
      title: document.querySelector('h1')?.innerText || document.title.replace(/ - AliExpress.*$/, ''),
      text: document.body.innerText,
      gallery: [...document.querySelectorAll('[class*="slider--img--"] img')].map(i => i.currentSrc || i.src),
      groups: [...document.querySelectorAll('[data-sku-row]')].map(row => ({
        name: row.closest('[class*="sku-item--property--"]')?.querySelector('[class*="sku-item--title--"]')?.innerText.split(':')[0] || 'Opción',
        options: [...row.querySelectorAll('[data-sku-col]')].map(n => ({ key: n.getAttribute('data-sku-col'), label: n.getAttribute('title') || n.innerText?.trim() || n.getAttribute('aria-label') || n.querySelector('img')?.getAttribute('alt') || '', disabled: n.getAttribute('aria-disabled') === 'true' || /disabled/.test(n.className) })),
      })),
    }));
    const combinations = initial.groups.reduce((list, group) => list.flatMap(row => group.options.filter(o => !o.disabled && eligibleOption(o.label)).map(option => [...row, { name: group.name, ...option }])), [[]]);
    log('varianti_rilevate', { groups: initial.groups.length, combinations: combinations.length, gallery: initial.gallery.length });
    if (!combinations.length || combinations.length > 20) throw new Error('This prototype supports up to 20 variant combinations');
    const variants = [];
    for (const options of combinations) {
      for (const option of options) {
        await assertAccessible(page);
        log('selezione_variante', { key: option.key, label: option.label });
        const el = page.locator(`[data-sku-col="${option.key}"]`);
        await el.click({ timeout: 8000 });
        await page.waitForFunction(key => document.querySelector(`[data-sku-col="${key}"]`)?.className.includes('selected'), option.key, { timeout: 8000 });
      }
      await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
      log('lettura_prezzo', { variant: options.map(o => o.label).join(' / ') });
      const priceText = await page.locator('[class*="price-default--current--"]').first().innerText();
      const raw = priceText.replace(/[^\d,.]/g, '');
      const value = raw.includes(',') && raw.lastIndexOf(',') > raw.lastIndexOf('.') ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');
      if (!priceText.includes('€') || !/^\d+(?:\.\d{1,2})?$/.test(value)) throw new Error('Variant price or currency not readable');
      variants.push({ label: options.map(o => o.label).join(' / '), options, supplier_price: value, currency: 'EUR', selling_price: null, observed_at: new Date().toISOString() });
    }
    log('lettura_descrizione');
    const description = await readDescription(page);
    log('descrizione_letta', { characters: description.text.length, specifications: description.specifications.length, images: description.image_urls.length });
    await assertAccessible(page);
    log('download_immagini');
    const images = [];
    const hashes = new Set();
    for (const src of [...new Set(initial.gallery.map(imageUrl))].slice(0, 12)) {
      const response = await page.request.get(src, { timeout: 20000, maxRedirects: 0 });
      if (!response.ok()) { log('immagine_non_disponibile', { status: response.status(), url: src }); continue; }
      const bytes = await response.body();
      if (!bytes.length || bytes.length > 8 * 1024 * 1024 || !response.headers()['content-type']?.startsWith('image/')) continue;
      const hash = crypto.createHash('sha256').update(bytes).digest('hex');
      if (hashes.has(hash)) continue;
      hashes.add(hash);
      const file = `image-${images.length + 1}.bin`;
      fs.writeFileSync(path.join(out, file), bytes);
      images.push({ file, source_url: src, role: 'da_verificare', variant: null, sha256: hash });
    }
    if (images.length < 3) throw new Error('Fewer than three product gallery images could be downloaded');
    const descriptionImages = [];
    const unavailableImages = [];
    for (const src of [...new Set(description.image_urls.map(imageUrl))]) {
      try {
        const response = await page.request.get(src, { timeout: 20000, maxRedirects: 0 });
        if (!response.ok() || !response.headers()['content-type']?.startsWith('image/')) throw new Error('HTTP ' + response.status());
        const bytes = await response.body();
        if (!bytes.length || bytes.length > 8 * 1024 * 1024) throw new Error('Invalid image size');
        const file = `description-${descriptionImages.length + 1}.bin`;
        fs.writeFileSync(path.join(out, file), bytes);
        descriptionImages.push({ file, source_url: src });
      } catch (e) { unavailableImages.push({ source_url: src, error: e.message }); }
    }
    const seller = initial.text.match(/Vendido por ([^\n]+?)(?:, tramitado|\n)/)?.[1] || null;
    const manifest = {
      source_id: url.pathname.match(/\d+/)[0], source_url: url.origin + url.pathname,
      source_title: initial.title, seller, captured_at: new Date().toISOString(), variants, images,
      description: { text: description.text, html: description.html, specifications: description.specifications, images: descriptionImages, unavailable_images: unavailableImages, complete: true },
      source_text: initial.text.slice(0, 40000),
      warnings: ['Compatibilità, cablaggi e accessori da verificare.', 'Foto del cruscotto originale non identificata.', 'Origine e consegna della variante alla destinazione non verificate.', 'Prezzi di acquisto osservati; prezzo di vendita calcolato secondo le regole AutoGenesi.', 'Riepilogo AI AliExpress escluso dalla descrizione generata.'],
    };
    fs.writeFileSync(path.join(out, 'manifest.json'), JSON.stringify(manifest, null, 2));
    await page.screenshot({ path: path.join(out, 'source.png') });
    console.log(JSON.stringify({ source_id: manifest.source_id, variants: variants.length, images: images.length, manifest: path.join(out, 'manifest.json') }));
    log('acquisizione_completata', { variants: variants.length, images: images.length, description_images: descriptionImages.length });
    complete = true;
  } finally {
    if (complete) await Promise.all(opened.map(p => p.close().catch(() => {})));
    await disconnect();
  }
})().catch(error => { log('errore_browser', { message: error.message }); console.error(error.message); process.exitCode = 1; });
