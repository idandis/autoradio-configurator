const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('scripts/autogenesi/chrome-extension/dsers.js', 'utf8');

test('DSers acquisition pairs an exact Shopify product with its supplier and rejects ambiguous cards', () => {
  const run = ids => {
    const a = { href: 'https://www.aliexpress.com/item/123.html' };
    const box = { parentElement: null, querySelectorAll: selector => selector === 'a[href]' ? [a, ...ids.map(id => ({ href: `https://admin.shopify.com/store/demo/products/${id}` }))] : [] };
    a.parentElement = box;
    return vm.runInNewContext(code, { URL, location: { protocol: 'https:', hostname: 'www.dsers.com', origin: 'https://www.dsers.com', pathname: '/application/my-products' }, document: { body: {}, querySelectorAll: () => [a] } });
  };
  const result = run(['456']);
  assert.equal(result.rows[0].shopify_product_id, '456');
  assert.equal(result.rows[0].supplier_url, 'https://es.aliexpress.com/item/123.html');
  assert.equal(run(['456', '789']).rows.length, 0);
});

test('DSers acquisition rejects unrelated sites', () => {
  assert.throws(() => vm.runInNewContext(code, { location: { protocol: 'https:', hostname: 'dsers.com.evil.test' } }));
});
