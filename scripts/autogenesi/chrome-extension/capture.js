(async () => {
  const url = new URL(location.href);
  if (url.protocol !== 'https:' || !['es.aliexpress.com', 'www.aliexpress.com'].includes(url.hostname) || !/^\/item\/\d+\.html$/.test(url.pathname)) throw new Error('Apri una scheda prodotto su es.aliexpress.com o www.aliexpress.com.');
  const sourceId = url.pathname.match(/\d+/)[0];
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  const visible = el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  const check = () => {
    if (new URL(location.href).pathname !== url.pathname) throw new Error('La pagina è cambiata: acquisizione interrotta.');
    if (/unusual traffic|tr[aá]fico (?:inusual|inusitado|an[oó]malo)|verify you are human|verifica que eres humano/i.test(document.body.innerText)
      || [...document.querySelectorAll('iframe[src*="punish"],iframe[src*="captcha"],iframe[src*="x5sec"]')].some(visible)) throw new Error('AliExpress richiede una verifica o segnala traffico insolito. Acquisizione fermata.');
  };
  const normalize = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const eligible = label => {
    const s = normalize(label);
    if (!s.trim() || /360|solo marco|solo cable|sin radio|no radio|only frame|frame only|cable only|canbus only|fascia only/.test(s)) return false;
    return !/\b(?:marco|frame|carcasa)\b/.test(s) || /\d+\s*(?:gb|g)\b|\b[248]\s*(?:core|nucle)/.test(s);
  };
  check();
  const sourceTitle = document.querySelector('h1')?.innerText?.trim();
  if (!sourceTitle) throw new Error('Titolo prodotto non disponibile. Attendi il caricamento.');
  const gallery = [...new Set([...document.querySelectorAll('[class*="slider--img--"] img')].map(i => i.currentSrc || i.src).filter(Boolean))].slice(0, 12);
  if (gallery.length < 3) throw new Error('Servono almeno tre immagini della galleria prodotto.');
  const rows = [...document.querySelectorAll('[data-sku-row]')].filter(visible);
  const groups = rows.map(row => ({
    name: row.closest('[class*="sku-item--property--"]')?.querySelector('[class*="sku-item--title--"]')?.innerText.split(':')[0] || 'Opción',
    options: [...row.querySelectorAll('[data-sku-col]')].filter(el => el.getAttribute('aria-disabled') !== 'true' && !/disabled/.test(el.className)).map(el => ({
      key: el.getAttribute('data-sku-col'), sole: row.querySelectorAll('[data-sku-col]').length === 1,
      label: el.getAttribute('title') || el.innerText.trim() || el.getAttribute('aria-label') || el.querySelector('img')?.alt || '',
    })).filter(o => eligible(o.label)),
  }));
  const combinations = groups.reduce((rows, group) => rows.flatMap(row => group.options.map(o => [...row, { name: group.name, ...o } ])), [[]]);
  if ((!groups.length && ([...document.querySelectorAll('[class*="sku-item--property--"], [data-sku-col]')].some(visible) || !eligible(sourceTitle)))
    || !combinations.length || combinations.length > 20) throw new Error('Acquisizione supportata per prodotti con 1–20 combinazioni di varianti leggibili.');
  const original = [...document.querySelectorAll('[data-sku-col]')].filter(el => /selected/.test(el.className)).map(el => el.getAttribute('data-sku-col'));
  const variants = [];
  try {
    for (const options of combinations) {
      check();
      for (const option of options) {
        const el = [...document.querySelectorAll('[data-sku-col]')].find(el => el.getAttribute('data-sku-col') === option.key);
        if (!el || el.getAttribute('aria-disabled') === 'true' || /disabled/.test(el.className)) throw new Error('Variante non disponibile.');
        if (option.sole) continue;
        el.click();
        for (let i = 0; i < 30 && !/selected/.test(el.className); i++) await sleep(100);
        if (!/selected/.test(el.className)) throw new Error('Selezione variante non confermata.');
      }
      await sleep(1600);
      check();
      if (!groups.length && [...document.querySelectorAll('[data-sku-row], [data-sku-col], [class*="sku-item--property--"]')].some(visible)) throw new Error('Le varianti sono apparse durante il caricamento. Attendi e riprova.');
      let previous = '';
      let stable = false;
      let text = '';
      for (let i = 0; i < 20; i++) {
        text = document.querySelector('[class*="price-default--current--"]')?.innerText?.trim() || '';
        if (text && text === previous) { stable = true; break; }
        previous = text;
        await sleep(400);
      }
      const raw = text.replace(/[^\d,.]/g, '');
      const price = raw.includes(',') && raw.lastIndexOf(',') > raw.lastIndexOf('.') ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');
      if (!stable || !text.includes('€') || !/^(?:€)?\d[\d.,]*(?:€)?$/.test(text.replace(/\s/g, '')) || !/^\d+(?:\.\d{1,2})?$/.test(price) || Number(price) <= 0) throw new Error('Prezzo esatto in EUR non leggibile: verifica la valuta e la variante AliExpress.');
      variants.push({ label: options.length ? options.map(o => o.label).join(' / ') : 'Versión única', options, supplier_price: price, currency: 'EUR', observed_at: new Date().toISOString() });
    }
  } finally {
    let canRestore = true;
    try { check(); } catch { canRestore = false; }
    for (const key of canRestore ? original : []) {
      const el = [...document.querySelectorAll('[data-sku-col]')].find(el => el.getAttribute('data-sku-col') === key);
      if (el && !/selected/.test(el.className)) el.click();
    }
  }
  const section = document.querySelector('#nav-description');
  if (!section) throw new Error('Apri la descrizione del prodotto prima di acquisire.');
  section.scrollIntoView({ block: 'start' });
  for (const selector of ['#nav-specification button', '#nav-description button']) {
    const button = [...document.querySelectorAll(selector)].find(el => visible(el) && /ver m[aá]s|view more|show more/i.test(el.innerText));
    button?.click();
  }
  await sleep(1000);
  check();
  const host = document.querySelector('#product-description');
  if (!host) throw new Error('Descrizione originale non caricata.');
  const shadow = [host, ...host.querySelectorAll('*')].find(el => el.shadowRoot)?.shadowRoot;
  const root = shadow?.querySelector('#product-description') || shadow || host;
  const clone = root.cloneNode(true);
  clone.querySelectorAll('script,style,iframe').forEach(el => el.remove());
  const text = root.innerText?.trim() || clone.textContent.trim();
  const imageUrls = [...new Set([...root.querySelectorAll('img')].map(el => el.getAttribute('data-src') || el.src).filter(Boolean))];
  const specifications = [...document.querySelectorAll('#nav-specification [class*="specification--prop--"]')].map(el => ({
    name: el.querySelector('[class*="specification--title--"]')?.innerText?.trim(), value: el.querySelector('[class*="specification--desc--"]')?.innerText?.trim(),
  })).filter(s => s.name && s.value);
  if ((!text && !imageUrls.length) || !specifications.length || text.length > 120000 || imageUrls.length > 60 || clone.innerHTML.length > 250000) throw new Error('Descrizione o specifiche incomplete o troppo grandi. Espandi le sezioni e riprova.');
  return { source_id: sourceId, source_url: url.origin + url.pathname, source_title: sourceTitle, captured_at: new Date().toISOString(),
    variants, gallery, description: { text, html: clone.innerHTML, specifications, image_urls: imageUrls, complete: true },
    source_text: sourceTitle + '\n' + text, warnings: ['Acquisito dalla scheda aperta in Chrome. Compatibilità e dotazione da verificare.'] };
})();
