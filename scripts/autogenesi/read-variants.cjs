const PARTIAL_WARNING = 'Non tutte le varianti sono state prese: introdurle manualmente.';

async function readVariants(page, combinations, assertAccessible, log = () => {}) {
  const variants = [], skipped = [], unavailable = new Set();
  const more = page.locator('[class*="sku-item--viewMore--"]');
  for (let i = 0; i < Math.min(await more.count(), 10); i++) {
    if (await more.nth(i).isVisible()) await more.nth(i).click({ timeout: 1500 }).catch(() => {});
  }
  for (const options of combinations.slice(0, 20)) {
    await assertAccessible(page);
    const label = options.map(o => o.label).join(' / ');
    if (options.some(o => unavailable.has(o.key))) { skipped.push(label); continue; }
    try {
      for (const option of options) {
        log('selezione_variante', { key: option.key, label: option.label });
        const el = page.locator(`[data-sku-col="${option.key}"]`);
        if (!await el.isVisible()) { unavailable.add(option.key); throw new Error('Variant not visible'); }
        await el.evaluate(node => node.scrollIntoView({ block: 'center', inline: 'nearest' }));
        try { await el.click({ timeout: 2500 }); }
        catch (error) { unavailable.add(option.key); throw error; }
        await page.waitForFunction(key => document.querySelector(`[data-sku-col="${key}"]`)?.className.includes('selected'), option.key, { timeout: 3000 });
      }
      await page.waitForTimeout(200);
      await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
      const selected = await page.evaluate(keys => keys.every(key => document.querySelector(`[data-sku-col="${key}"]`)?.className.includes('selected')), options.map(o => o.key));
      if (!selected) throw new Error('Variant selection not confirmed');
      log('lettura_prezzo', { variant: label });
      const priceText = await page.locator('[class*="price-default--current--"]').first().innerText({ timeout: 3000 });
      const raw = priceText.replace(/[^\d,.]/g, '');
      const value = raw.includes(',') && raw.lastIndexOf(',') > raw.lastIndexOf('.') ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');
      if (!priceText.includes('€') || /\d\s*[-–]\s*\d/.test(priceText) || !/^\d+(?:\.\d{1,2})?$/.test(value) || Number(value) <= 0) throw new Error('Variant price or currency not readable');
      variants.push({ label, options, supplier_price: value, currency: 'EUR', selling_price: null, observed_at: new Date().toISOString() });
    } catch (error) {
      await assertAccessible(page);
      skipped.push(label);
      log('variante_non_acquisita', { label, message: error.message });
    }
  }
  skipped.push(...combinations.slice(20).map(options => options.map(o => o.label).join(' / ')));
  if (!variants.length) throw new Error('No visible variant with confirmed selection and readable price');
  return { variants, skipped, warning: skipped.length ? PARTIAL_WARNING : null, detected: combinations.length };
}
module.exports = { readVariants, PARTIAL_WARNING };
