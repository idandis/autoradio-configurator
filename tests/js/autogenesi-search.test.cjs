const { test, after } = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
process.env.AUTOGENESI_BROWSER_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'autogenesi-browser-test-'));
after(() => fs.rmSync(process.env.AUTOGENESI_BROWSER_DIR, { recursive: true, force: true }));
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { relevant, eligibleOption } = require('../../scripts/autogenesi/product-filter.cjs');
const inspectCandidate = require('../../scripts/autogenesi/inspect-candidate.cjs');
const assertAccessible = require('../../scripts/autogenesi/assert-accessible.cjs');
const vehicle = { brand: 'Hyundai', model: 'KONA', year_from: 2017, year_to: 2020 };

test('rejects universal radios and accessory SKUs but accepts specific radio kits with a camera', () => {
  assert.equal(relevant('Radio universal Hyundai Kona 2017-2020', vehicle), false);
  assert.equal(relevant('Marco para radio Hyundai Kona 2017-2020', vehicle), false);
  assert.equal(relevant('Radio Hyundai Kona 2018-2020', vehicle), false);
  assert.equal(relevant('Radio Hyundai Kona 2017-2020 con cámara AHD', vehicle), true);
  assert.equal(eligibleOption('Only frame'), false);
  assert.equal(eligibleOption('PX4 (6GB 128GB)360 White'), false);
  assert.equal(eligibleOption('PX4 (6GB 128GB)'), true);
  assert.equal(eligibleOption(''), false);
  assert.equal(eligibleOption('4GB 64GB con frame'), true);
});

test('opens product, skips frame-only option and reads actual radio price and hardware', async () => {
  const browser = await chromium.launch({ executablePath: process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    let title = 'Radio Hyundai KONA 2017-2020 con cámara AHD';
    await page.route('**/*', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<h1>${title}</h1>
      <div data-sku-row><button data-sku-col="frame" title="Only frame" class="selected">Frame</button>
      <button data-sku-col="radio" style="width:120px;height:30px" onclick="document.querySelector('[data-sku-col=frame]').className='';this.className='selected';document.querySelector('.price-default--current--test').innerText='124,53€'"><img alt="4GB 64GB"></button></div>
      <div class="price-default--current--test">9,99€</div>
      <div class="slider--img--test"><img src="https://ae01.alicdn.com/a.jpg"><img src="https://ae01.alicdn.com/b.jpg"><img src="https://ae01.alicdn.com/c.jpg"></div>
      <div id="nav-specification"><div class="specification--prop--test"><span class="specification--title--test">Sistema operativo</span><span class="specification--desc--test">Android 14</span></div></div>
      <div id="nav-description"><div id="product-description"><div class="detail-desc-decorate-richtext">Radio específica para Hyundai KONA 2017-2020.</div></div></div>` }));
    const candidate = { source_url: 'https://es.aliexpress.com/item/123.html' };
    const result = await inspectCandidate(page, candidate, vehicle);
    assert.equal(result.verified, true);
    assert.equal(result.price_text, '124,53€');
    assert.deepEqual(result.selected_options, ['4GB 64GB']);
    assert.equal(result.specifications_count, 1);
    title = 'Radio universal Hyundai KONA 2017-2020';
    assert.equal(await inspectCandidate(page, candidate, vehicle), null);
    await page.setContent('<iframe src="https://example.com/punish" style="width:100px;height:100px"></iframe>');
    await assert.rejects(assertAccessible(page), /Verification required/);
    await page.setContent('<iframe srcdoc="<p>We have detected unusual traffic from your network, please try again later.</p>" style="width:400px;height:300px"></iframe>');
    await assert.rejects(assertAccessible(page), /Network traffic blocked/);
    await page.setContent('<p>Radio Hyundai Kona</p><iframe style="display:none" srcdoc="unusual traffic"></iframe>');
    await assert.doesNotReject(assertAccessible(page));
  } finally { await browser.close(); }
});
