const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('extension packages public image downloads and never sends account credentials', async () => {
  let listener;
  let download;
  const requests = [];
  const source = 'https://es.aliexpress.com/item/123.html';
  const manifest = { source_id: '123', source_url: source, source_title: 'Radio', variants: [], gallery: [1,2,3].map(i => `https://ae-pic-a1.aliexpress-media.com/kf/${i}.jpg_100x100.jpg`), description: { image_urls: ['https://ae-pic-a1.aliexpress-media.com/kf/accessories.jpg'], text: 'Radio' } };
  const context = vm.createContext({ URL, Uint8Array, TextEncoder, AbortSignal, Date, Map, Set, btoa: s => Buffer.from(s, 'binary').toString('base64'),
    setTimeout: fn => setTimeout(fn, 0),
    createImageBitmap: async () => ({ width: 300, height: 300, close() {} }),
    OffscreenCanvas: class { getContext() { return { fillRect() {}, drawImage() {} }; } async convertToBlob() { return { arrayBuffer: async () => new Uint8Array([1,2,3]).buffer }; } },
    fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, blob: async () => ({ type: 'image/jpeg', size: 3 }) }; },
    chrome: { runtime: { id: 'test', onMessage: { addListener: fn => { listener = fn; } } },
      tabs: { query: async () => [{ id: 1, url: source }], get: async () => ({ url: source }) },
      scripting: { executeScript: async () => [{ result: manifest }] }, downloads: { download: async args => { download = args; } } },
  });
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../scripts/autogenesi/chrome-extension/background.js'), 'utf8'), context);
  for (const bad of ['https://alicdn.com.evil.test/image.jpg', 'https://user@alicdn.com/image.jpg', 'https://alicdn.com:8000/image.jpg', 'file:///image.jpg']) {
    assert.throws(() => vm.runInContext(`imageUrl(${JSON.stringify(bad)})`, context));
  }
  const result = await new Promise(resolve => listener({ type: 'autogenesi-capture' }, { id: 'test' }, resolve));
  assert.equal(result.ok, true);
  assert.equal(requests.length, 4);
  assert.ok(requests.every(r => r.options.credentials === 'omit' && r.options.redirect === 'error'));
  const data = JSON.parse(Buffer.from(download.url.split(',')[1], 'base64').toString('utf8'));
  assert.equal(data.format, 'autogenesi.chrome.v1');
  assert.equal(data.manifest.images.length, 3);
  assert.equal(data.manifest.description.images[0].file, 'description-1.bin');
  assert.equal(data.manifest.images[0].source_url, 'https://ae-pic-a1.aliexpress-media.com/kf/1.jpg');
});

 test('generic image URLs preserve signed CDN queries and reject private addresses', () => {
  const context = vm.createContext({ URL, chrome: { runtime: { onMessage: { addListener() {} } } } });
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../scripts/autogenesi/chrome-extension/background.js'), 'utf8'), context);
  assert.equal(vm.runInContext(`imageUrl('https://cdn.shopify.com/image.jpg?width=1200&token=abc')`, context), 'https://cdn.shopify.com/image.jpg?width=1200&token=abc');
  for (const bad of ['https://127.0.0.1/a.jpg', 'https://192.168.1.1/a.jpg', 'https://localhost/a.jpg', 'file:///a.jpg']) assert.throws(() => vm.runInContext(`imageUrl(${JSON.stringify(bad)})`, context));
});
