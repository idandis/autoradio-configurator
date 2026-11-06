const readDescription = require('./read-description.cjs');
const assertAccessible = require('./assert-accessible.cjs');
const { relevant, eligibleOption } = require('./product-filter.cjs');

module.exports = async (page, candidate, vehicle) => {
  const response = await page.goto(candidate.source_url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response?.ok()) return null;
  await assertAccessible(page);
  await page.locator('[data-sku-col]').first().waitFor({ timeout: 12000 });
  for (const text of ['Rechazar cookies', 'No permitir']) {
    const button = page.getByText(text, { exact: true });
    if (await button.count() === 1 && await button.isVisible()) await button.click({ timeout: 3000 }).catch(() => {});
  }
  const data = await page.evaluate(() => ({
    title: document.querySelector('h1')?.innerText || document.title.replace(/ - AliExpress.*$/, ''),
    gallery: [...document.querySelectorAll('[class*="slider--img--"] img')].map(i => (i.currentSrc || i.src).split('?')[0].replace(/\.(jpg|jpeg|png|webp)(?:_.*)$/i, '.$1')),
    groups: [...document.querySelectorAll('[data-sku-row]')].map(row => [...row.querySelectorAll('[data-sku-col]')].map(n => ({
      key: n.getAttribute('data-sku-col'), label: n.getAttribute('title') || n.innerText?.trim() || n.getAttribute('aria-label') || n.querySelector('img')?.getAttribute('alt') || '',
      disabled: n.getAttribute('aria-disabled') === 'true' || /disabled/.test(n.className),
    }))),
  }));
  if (!relevant(data.title, vehicle, false)) return null;
  if (new Set(data.gallery.filter(Boolean)).size < 3) return null;
  const groups = data.groups.map(group => group.filter(o => !o.disabled && eligibleOption(o.label)));
  if (!groups.length || groups.some(g => !g.length) || groups.reduce((n, g) => n * g.length, 1) > 20) return null;
  for (const group of groups) {
    await assertAccessible(page);
    const option = group[0];
    await page.locator(`[data-sku-col="${option.key}"]`).click({ timeout: 5000 });
    await page.waitForFunction(key => document.querySelector(`[data-sku-col="${key}"]`)?.className.includes('selected'), option.key, { timeout: 5000 });
  }
  await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
  const price = await page.locator('[class*="price-default--current--"]').first().innerText({ timeout: 5000 });
  if (!price.includes('€')) return null;
  const rawPrice = price.replace(/[^\d,.]/g, '');
  const amount = rawPrice.includes(',') && rawPrice.lastIndexOf(',') > rawPrice.lastIndexOf('.') ? rawPrice.replace(/\./g, '').replace(',', '.') : rawPrice.replace(/,/g, '');
  if (!/^\d+(?:\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) return null;
  const description = await readDescription(page);
  await assertAccessible(page);
  if (!relevant(data.title + ' ' + description.text + ' ' + description.specifications.map(s => s.value).join(' '), vehicle)) return null;
  const hardware = description.specifications.some(s => /ram|rom|procesador|processor|cpu|chipset|operati|operativo/i.test(s.name)
    && /android|\b[1-9]\d*\s*(?:g|gb|ghz)|\b[248]\s*(?:core|nucle)/i.test(s.value));
  if (!hardware) return null;

  return { title: data.title, source_url: candidate.source_url, price_text: price.replace(/\s+/g, ' ').trim(),
    selected_options: groups.map(g => g[0].label), verified: true, specifications_count: description.specifications.length };
};
