const { connect } = require('./browser-session.cjs');
const fs = require('node:fs');
const path = require('node:path');
const [query, out, raw, mode = 'links'] = process.argv.slice(2);
const vehicle = JSON.parse(raw);
const { relevant } = require('./product-filter.cjs');
const inspectCandidate = require('./inspect-candidate.cjs');
const assertAccessible = require('./assert-accessible.cjs');
(async () => {
  require('./run-control.cjs').watch();
  fs.mkdirSync(out, { recursive: true });
  const { context, disconnect } = await connect();
  const opened = [];
  let complete = false;
  try {
    const page = await context.newPage();
    opened.push(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.bringToFront();
    let result;
    let inspected = 0;
    let scanned = 0;
    const deadline = Date.now() + 320000;
    const details = mode === 'best_price' ? await context.newPage() : null;
    if (details) { opened.push(details); await details.setViewportSize({ width: 1440, height: 1000 }); }
    for (const origin of ['EU', 'Todos']) {
      let originInspected = 0;
      const originSeen = new Set();
      const candidates = [];
      for (let number = 1; number <= (mode === 'best_price' ? 5 : 1); number++) {
        if (Date.now() >= deadline) break;
        const url = new URL('https://es.aliexpress.com/w/wholesale-' + encodeURIComponent(query.replace(/\s+/g, '-')) + '.html');
        url.searchParams.set('SearchText', query);
        url.searchParams.set('g', 'y');
        if (mode === 'best_price') url.searchParams.set('sortType', 'price_asc');
        if (number > 1) url.searchParams.set('page', String(number));
        if (origin === 'EU') url.searchParams.set('shpf_co', 'EU');
        const response = await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 45000 });
        if (!response?.ok()) throw new Error('Search page unavailable');
        await assertAccessible(page);
        await page.locator('a[href*="/item/"]').first().waitFor({ timeout: 15000 }).catch(async error => {
          if (!/no hemos encontrado|no se han encontrado|ning[uú]n resultado|no results|no items/i.test(await page.locator('body').innerText())) throw error;
        });
        scanned++;
        for (let i = 0; i < 3; i++) {
          await page.mouse.wheel(0, 850);
          await page.waitForTimeout(500);
        }
        const cards = await page.evaluate(() => [...document.querySelectorAll('a[href*="/item/"]')].map(a => ({
          url: a.href, title: a.querySelector('h1,h2,h3')?.getAttribute('title') || a.querySelector('h1,h2,h3')?.innerText || a.innerText,
        })));
        fs.writeFileSync(path.join(out, origin + '-' + number + '-cards.json'), JSON.stringify(cards));
        const fresh = cards.flatMap(card => {
          const u = new URL(card.url);
          const id = u.pathname.match(/^\/item\/(\d+)\.html$/)?.[1];
          if (u.protocol !== 'https:' || !/^(es|www)\.aliexpress\.com$/.test(u.hostname) || !id || originSeen.has(id) || !relevant(card.title, vehicle, mode !== 'best_price')) return [];
          originSeen.add(id);
          return [{ title: card.title.slice(0, 1000), source_url: 'https://es.aliexpress.com/item/' + id + '.html' }];
        }).slice(0, mode === 'best_price' ? Infinity : 20);
        candidates.push(...fresh);
        result = { mode, origin, url: url.href, candidates, selected: null, inspected_count: inspected, pages_scanned: scanned };
        if (mode === 'best_price') {
          for (const candidate of fresh) {
            if (originInspected >= 12 || Date.now() >= deadline) break;
            inspected++;
            originInspected++;
            try {
              const selected = await inspectCandidate(details, candidate, vehicle);
              if (selected) { result.selected = selected; break; }
            } catch (error) {
              if (/Network traffic blocked|Verification required/.test(error.message)) throw error;
              await assertAccessible(details);
              const body = await details.locator('body').innerText().catch(() => '');
              if (/captcha|verify you are human|verifica que eres humano/i.test(body)) throw new Error('Verification required; search stopped');
            }
          }
        }
        result.inspected_count = inspected;
        if (result.selected || mode !== 'best_price' || !cards.length || originInspected >= 12) break;
      }
      if (result?.selected || (mode !== 'best_price' && result?.candidates.length) || Date.now() >= deadline || inspected >= 24) break;
    }
    if (!result) throw new Error('No search page could be inspected');
    fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify(result));
    complete = true;
  } finally {
    if (complete) await Promise.all(opened.map(p => p.close().catch(() => {})));
    await disconnect();
  }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
