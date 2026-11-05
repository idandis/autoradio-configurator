const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const readDescription = require('../../scripts/autogenesi/read-description.cjs');

test('reads expanded seller text in shadow DOM and all specs, excluding surrounding AI summary', async () => {
  const browser = await chromium.launch({ executablePath: process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(`<p>AI summary: kit 360 included</p>
      <div id="nav-specification"><div class="specification--prop--a"><span class="specification--title--a">DIN</span><span class="specification--desc--a">Un DIN</span></div>
      <button onclick="this.insertAdjacentHTML('beforebegin', '<div class=specification--prop--b><span class=specification--title--b>Voltaje</span><span class=specification--desc--b>12 V</span></div>');this.remove()">Ver más</button></div>
      <div id="nav-description"><div id="product-description"><div id="host"></div></div><button onclick="this.remove()">Ver más</button></div>
      <script>document.querySelector('#host').attachShadow({mode:'open'}).innerHTML = '<style>p{color:red}</style><div id="product-description"><div class="detail-desc-decorate-richtext"><p>WiFi integrado y GPS.</p></div><img src="https://ae01.alicdn.com/spec.jpg"></div>';</script>`);
    const result = await readDescription(page);
    assert.match(result.text, /WiFi integrado y GPS/);
    assert.doesNotMatch(result.text, /AI summary/);
    assert.deepEqual(result.specifications, [{ name: 'DIN', value: 'Un DIN' }, { name: 'Voltaje', value: '12 V' }]);
    assert.deepEqual(result.image_urls, ['https://ae01.alicdn.com/spec.jpg']);
    assert.doesNotMatch(result.html, /<style|<script/);
  } finally { await browser.close(); }
});
