const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { parse, compileScript } = require('@vue/compiler-sfc');
const ts = require('typescript');
const { chromium } = require('playwright');

test('text band spans full width, moves vertically and saves white text without resizing', async () => {
  const root = path.resolve(__dirname, '../..');
  const sfc = parse(fs.readFileSync(path.join(root, 'resources/js/components/ImageTextBand.vue'), 'utf8'), { filename: 'ImageTextBand.vue' });
  const compiled = compileScript(sfc.descriptor, { id: 'text-test', inlineTemplate: true });
  let script = ts.transpileModule(compiled.content, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
  script = script.replace(/import\s*\{([^}]+)\}\s*from\s*['"]vue['"];?/g, (_, imports) => `const {${imports.replace(/\s+as\s+/g, ': ')}} = Vue;`).replace('export default', 'window.TextBand =');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
    await page.setContent('<div id="app"></div>');
    await page.addScriptTag({ path: path.join(root, 'node_modules/vue/dist/vue.global.js') });
    await page.addScriptTag({ content: script });
    await page.evaluate(() => {
      const c = document.createElement('canvas'); c.width = 400; c.height = 250;
      const ctx = c.getContext('2d'); ctx.fillStyle = '#ff0000'; ctx.fillRect(0, 0, 400, 250);
      Vue.createApp({ render: () => Vue.h(window.TextBand, { src: c.toDataURL(), busy: false, onSave: async file => {
        const bitmap = await createImageBitmap(file); window.saved = { width: bitmap.width, height: bitmap.height, type: file.type };
      } }) }).mount('#app');
    });
    const save = page.getByRole('button', { name: 'Salva foto', exact: true });
    await page.waitForFunction(() => document.querySelector('canvas')?.width === 400);
    assert.equal(await save.isEnabled(), false);
    await page.getByRole('textbox').fill('Pantalla 8Core');
    assert.equal(await save.isEnabled(), true);
    const blackBottom = await page.locator('canvas').evaluate(c => [0, 399].every(x => c.getContext('2d').getImageData(x, 249, 1, 1).data[0] === 0));
    assert.equal(blackBottom, true);
    const box = await page.locator('canvas').boundingBox();
    await page.mouse.move(box.x + 10, box.y + box.height - 10); await page.mouse.down();
    await page.mouse.move(box.x + 10, box.y + 15); await page.mouse.up();
    await page.waitForTimeout(50);
    assert.equal(await save.isEnabled(), true);
    const pixels = await page.locator('canvas').evaluate(c => {
      const ctx = c.getContext('2d'), data = ctx.getImageData(0, 0, c.width, c.height).data;
      let white = 0; for (let i = 0; i < data.length; i += 4) if (data[i] > 240 && data[i + 1] > 240 && data[i + 2] > 240) white++;
      return { top: ctx.getImageData(0, 0, 1, 1).data[0], bottom: ctx.getImageData(0, 249, 1, 1).data[0], white };
    });
    assert.equal(pixels.top, 0); assert.equal(pixels.bottom, 255); assert.ok(pixels.white > 0);
    await save.click(); await page.waitForFunction(() => window.saved);
    assert.deepEqual(await page.evaluate(() => window.saved), { width: 400, height: 250, type: 'image/png' });
  } finally { await browser.close(); }
});
