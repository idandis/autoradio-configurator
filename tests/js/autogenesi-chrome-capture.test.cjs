const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

test('extension captures selected product variants and true description without navigating to another product', { timeout: 30000 }, async () => {
  const browser = await chromium.launch({ executablePath: process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<h1>Radio Hyundai Kona 2017-2020</h1>
      <div class="slider--img--test">${[1,2,3].map(i => `<img src="https://ae-pic-a1.aliexpress-media.com/kf/main${i}.jpg">`).join('')}</div>
      <div data-sku-row>
        <button data-sku-col="a" title="PX1 (2GB 64GB)" class="selected" onclick="document.querySelectorAll('[data-sku-col]').forEach(el=>el.className='');this.className='selected';document.querySelector('.price-default--current--test').innerText='109,00€'">A</button>
        <button data-sku-col="b" title="PX9 (8GB 256GB)" onclick="document.querySelectorAll('[data-sku-col]').forEach(el=>el.className='');this.className='selected';document.querySelector('.price-default--current--test').innerText='299,00€'">B</button>
        <button data-sku-col="c" title="PX4 360 White">360</button>
        <button data-sku-col="d" title="Only frame">Frame</button>
      </div><div class="price-default--current--test">109,00€</div>
      <div id="nav-specification"><div class="specification--prop--test"><span class="specification--title--test">DIN</span><span class="specification--desc--test">1 DIN</span></div></div>
      <div id="nav-description"><div id="product-description"><div class="detail-desc-decorate-richtext">Pantalla IPS de 9 pulgadas.<img src="https://ae-pic-a1.aliexpress-media.com/kf/accessories.jpg"></div></div></div>` }));
    await page.goto('https://es.aliexpress.com/item/123.html?tracking=true');
    const script = fs.readFileSync(path.resolve(__dirname, '../../scripts/autogenesi/chrome-extension/capture.js'), 'utf8');
    const result = await page.evaluate(script);
    assert.equal(result.source_url, 'https://es.aliexpress.com/item/123.html');
    assert.deepEqual(result.variants.map(v => [v.label, v.supplier_price]), [['PX1 (2GB 64GB)', '109.00'], ['PX9 (8GB 256GB)', '299.00']]);
    assert.equal(result.gallery.length, 3);
    assert.match(result.description.text, /Pantalla IPS/);
    assert.deepEqual(result.description.specifications, [{ name: 'DIN', value: '1 DIN' }]);
    assert.equal(result.description.image_urls.length, 1);
    assert.equal(await page.locator('[data-sku-col="a"]').getAttribute('class'), 'selected');
    await page.setContent('<p>We have detected unusual traffic from your network.</p>');
    await assert.rejects(page.evaluate(script), /Acquisizione fermata/);
  } finally { await browser.close(); }
});

test('AliExpress single variants work without selectors or a selected CSS class and excluded options stay excluded', { timeout: 30000 }, async () => {
  const browser = await chromium.launch({ executablePath: process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    const script = fs.readFileSync(path.resolve(__dirname, '../../scripts/autogenesi/chrome-extension/capture.js'), 'utf8');
    const html = (sku = '', price = '129,99 €') => `<h1>Radio Hyundai Kona 2017-2020</h1>
      <div class="slider--img--test">${[1,2,3].map(i => `<img src="https://ae-pic-a1.aliexpress-media.com/kf/${i}.jpg">`).join('')}</div>
      ${sku}<div class="price-default--current--test">${price}</div>
      <div id="nav-specification"><div class="specification--prop--test"><span class="specification--title--test">DIN</span><span class="specification--desc--test">1 DIN</span></div></div>
      <div id="nav-description"><div id="product-description">Radio con pantalla IPS.</div></div>`;
    await page.route('**/*', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: html() }));
    await page.goto('https://es.aliexpress.com/item/123.html');
    let data = await page.evaluate(script);
    assert.equal(data.variants.length, 1);
    assert.equal(data.variants[0].label, 'Versión única');
    assert.equal(data.variants[0].supplier_price, '129.99');
    assert.equal(data.variants[0].options.length, 0);
    await page.setContent(html('<div data-sku-row><button data-sku-col="only" title="8GB 128GB">8GB 128GB</button></div>'));
    data = await page.evaluate(script);
    assert.equal(data.variants.length, 1);
    assert.equal(data.variants[0].label, '8GB 128GB');
    assert.equal(data.variants[0].supplier_price, '129.99');
    for (const label of ['360 8GB 128GB', 'Only frame']) {
      await page.setContent(html(`<div data-sku-row><button data-sku-col="only" title="${label}">${label}</button></div>`));
      await assert.rejects(page.evaluate(script), /combinazioni/);
    }
    await page.setContent(html('<div class="sku-item--property--test"><button>Unparsed choices</button></div>'));
    await assert.rejects(page.evaluate(script), /combinazioni/);
    await page.setContent(html('', '129,99 € - 299,00 €'));
    await assert.rejects(page.evaluate(script), /Prezzo esatto/);
  } finally { await browser.close(); }
});
