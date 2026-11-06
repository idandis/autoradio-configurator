const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { chromium } = require('playwright');

test('generic capture reads exact EUR variants, descriptions and CDN images, rejecting guesses', { timeout: 30000 }, async () => {
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage();
    const script = fs.readFileSync('scripts/autogenesi/chrome-extension/generic.js', 'utf8');
    const product = { '@type': 'ProductGroup', name: 'Radio Kona', description: 'Radio originale con caratteristiche documentate.', image: [1, 2, 3].map(n => `https://cdn.shopify.com/${n}.jpg?width=1200`),
      hasVariant: [{ '@type': 'Product', name: '4GB 64GB', offers: { '@type': 'Offer', price: '129.99', priceCurrency: 'EUR' } }, { '@type': 'Product', name: '8GB 128GB', offers: { '@type': 'Offer', price: '219.50', priceCurrency: 'EUR' } }],
      additionalProperty: [{ name: 'DIN', value: '1 DIN' }] };
    await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: `<h1>Radio</h1><script type="application/ld+json">${JSON.stringify(product)}</script><div class="product-description"><p>Descrizione completa del prodotto.</p><img src="https://cdn.shopify.com/accessories.jpg?size=1000"></div>` }));
    const url = 'https://www.example.com/products/kona?variant=128';
    await page.goto(url);
    const data = await page.evaluate(script);
    assert.equal(data.source_url, url);
    assert.equal(data.source_id, '9' + BigInt('0x' + crypto.createHash('sha256').update(url).digest('hex').slice(0, 15)).toString());
    assert.deepEqual(data.variants.map(v => [v.label, v.supplier_price]), [['4GB 64GB', '129.99'], ['8GB 128GB', '219.50']]);
    assert.equal(data.gallery.length, 3);
    assert.equal(data.gallery[0], 'https://cdn.shopify.com/1.jpg?width=1200');
    assert.match(data.description.text, /Descrizione completa/);
    assert.equal(data.description.image_urls.length, 1);
    assert.deepEqual(data.description.specifications, [{ name: 'DIN', value: '1 DIN' }]);
    const original = product.hasVariant;
    product.hasVariant = Array.from({ length: 20 }, (_, i) => ({ ...original[0], name: `Variant ${i + 1}` }));
    await page.setContent(`<script type="application/ld+json">${JSON.stringify(product)}</script>`);
    assert.equal((await page.evaluate(script)).variants.length, 20);
    product.hasVariant.push({ ...original[0], name: 'Variant 21' });
    await page.setContent(`<script type="application/ld+json">${JSON.stringify(product)}</script>`);
    await assert.rejects(page.evaluate(script), /20 varianti/);
    product.hasVariant = original;
    product.hasVariant[0].offers.priceCurrency = 'USD';
    await page.setContent(`<script type="application/ld+json">${JSON.stringify(product)}</script>`);
    await assert.rejects(page.evaluate(script), /EUR/);
    product.hasVariant = undefined;
    product.offers = { '@type': 'AggregateOffer', lowPrice: '99', highPrice: '299', priceCurrency: 'EUR' };
    await page.setContent(`<script type="application/ld+json">${JSON.stringify(product)}</script>`);
    await assert.rejects(page.evaluate(script), /aggregati/);
    await page.setContent('<h1>Pagina senza prodotto</h1>');
    await assert.rejects(page.evaluate(script), /non riconosciuta/);
  } finally { await browser.close(); }
});
