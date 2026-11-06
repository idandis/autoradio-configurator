const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { parse, compileScript } = require('@vue/compiler-sfc');
const ts = require('typescript');
const { chromium } = require('playwright');

test('silhouette emits original pixel coordinates without resizing the product', async () => {
  const root = path.resolve(__dirname, '../..');
  const sfc = parse(fs.readFileSync(path.join(root, 'resources/js/components/ProductSilhouette.vue'), 'utf8'), { filename: 'ProductSilhouette.vue' });
  const compiled = compileScript(sfc.descriptor, { id: 'silhouette-test', inlineTemplate: true });
  let script = ts.transpileModule(compiled.content, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
  script = script.replace(/import\s*\{([^}]+)\}\s*from\s*['"]vue['"];?/g, (_, imports) => `const {${imports.replace(/\s+as\s+/g, ': ')}} = Vue;`).replace('export default', 'window.Silhouette =');
  script = script.replace(/import.*contrastMask.*;?/, '');
  const maskCode = ts.transpileModule(fs.readFileSync(path.join(root, 'resources/js/lib/product-cutout.ts'), 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText.replace('export function', 'function');
  script = maskCode + '\n' + script;
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
    await page.route('http://paint.test/**', route => route.fulfill({ contentType: 'text/html', body: '<div id="app"></div>' }));
    await page.goto('http://paint.test/');
    await page.route('http://paint.test/image.svg', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="300"><rect width="600" height="300" fill="white"/><rect x="100" y="50" width="400" height="200" fill="red"/></svg>' }));
    await page.addScriptTag({ path: path.join(root, 'node_modules/vue/dist/vue.global.js') });
    await page.addScriptTag({ content: script });
    await page.evaluate(() => Vue.createApp({ render: () => Vue.h(window.Silhouette, { src: '/image.svg', busy: false, onSave: p => { window.points = p; }, onAutomatic: file => { window.mask = { type: file.type, size: file.size }; } }) }).mount('#app'));
    await page.locator('svg').waitFor();
    const generate = page.getByRole('button', { name: 'Genera solo sfondo' });
    assert.equal(await generate.isEnabled(), false);
    const box = await page.getByAltText('Pantalla da scontornare').boundingBox();
    for (const [x, y] of [[100, 50], [499, 50], [499, 249], [100, 249]]) {
      await page.mouse.click(box.x + x * box.width / 600, box.y + y * box.height / 300);
    }
    assert.equal(await generate.isEnabled(), true);
    await generate.click();
    const points = await page.evaluate(() => window.points);
    const expected = [[100, 50], [499, 50], [499, 249], [100, 249]];
    assert.equal(points.length, 4);
    points.forEach((p, i) => p.forEach((v, axis) => assert.ok(Math.abs(v - expected[i][axis]) <= 1)));
    assert.equal(points[1][0] - points[0][0], 399);
    assert.equal(points[2][1] - points[1][1], 199);
    await page.getByRole('combobox').selectOption('auto');
    await page.waitForFunction(() => document.querySelector('canvas')?.width === 600);
    const pixel = await page.locator('canvas').evaluate(c => {
      const ctx = c.getContext('2d'); return [ctx.getImageData(0, 0, 1, 1).data[3], ctx.getImageData(200, 100, 1, 1).data[3]];
    });
    assert.deepEqual(pixel, [0, 255]);
    await generate.click();
    await page.waitForFunction(() => window.mask);
    assert.equal((await page.evaluate(() => window.mask)).type, 'image/png');
  } finally { await browser.close(); }
});
