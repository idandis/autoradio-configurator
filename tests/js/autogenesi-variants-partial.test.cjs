const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { readVariants, PARTIAL_WARNING } = require('../../scripts/autogenesi/read-variants.cjs');
const combinations = [1, 2, 3].map(n => [{ name: 'Version', key: `14-${n}`, label: `Version ${n}` }]);

async function fixture(run) {
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(`<style>.option {display:block;position:relative;width:200px;height:50px;margin:10px}.cover{position:absolute;inset:0;z-index:2;background:#ccc}</style>
      <div data-sku-row>${[1,2,3].map(n => `<div class="option"><button data-sku-col="14-${n}" onclick="document.querySelectorAll('[data-sku-col]').forEach(e=>e.classList.remove('selected'));this.classList.add('selected');document.querySelector('.price-default--current--x').textContent='€${n * 50}.00'">Version ${n}</button>${n === 2 ? '<div class="sku-item--viewMore--x cover">More</div>' : ''}</div>`).join('')}</div>
      <div class="price-default--current--x">€1.00</div>`);
    await run(page);
  } finally { await browser.close(); }
}

test('covered variant is skipped and later visible variants are acquired with an explicit warning', { timeout: 20000 }, () => fixture(async page => {
  const result = await readVariants(page, combinations, async () => {});
  assert.deepEqual(result.variants.map(v => [v.label, v.supplier_price]), [['Version 1', '50.00'], ['Version 3', '150.00']]);
  assert.deepEqual(result.skipped, ['Version 2']);
  assert.equal(result.warning, PARTIAL_WARNING);
  assert.equal(result.detected, 3);
}));

test('a human-verification block still aborts capture instead of importing a partial result', () => fixture(async page => {
  let calls = 0;
  await assert.rejects(readVariants(page, combinations, async () => { if (++calls > 1) throw new Error('Verification required'); }), /Verification required/);
}));

test('no accessible confirmed variant remains an error', () => fixture(async page => {
  await page.locator('[data-sku-col]').evaluateAll(elements => elements.forEach(e => e.style.display = 'none'));
  await assert.rejects(readVariants(page, combinations, async () => {}), /No visible variant/);
}));
