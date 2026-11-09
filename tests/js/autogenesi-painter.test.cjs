const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { parse, compileScript } = require('@vue/compiler-sfc');
const ts = require('typescript');
const { chromium } = require('playwright');

for (const cover of [false, true]) test(`Paint ${cover ? 'cover' : 'brush'} saves without altering pixels outside the stroke`, async () => {
  const root = path.resolve(__dirname, '../..');
  const sfc = parse(fs.readFileSync(path.join(root, 'resources/js/components/ImagePainter.vue'), 'utf8'), { filename: 'ImagePainter.vue' });
  const compiled = compileScript(sfc.descriptor, { id: 'paint-test', inlineTemplate: true });
  let script = ts.transpileModule(compiled.content, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
  script = script.replace(/import\s*\{([^}]+)\}\s*from\s*['"]vue['"];?/g, (_, imports) => `const {${imports.replace(/\s+as\s+/g, ': ')}} = Vue;`).replace('export default', 'window.PaintComponent =');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
    await page.setContent('<div id="app"></div>');
    await page.addScriptTag({ path: path.join(root, 'node_modules/vue/dist/vue.global.js') });
    await page.addScriptTag({ content: script });
    await page.evaluate(cover => {
      const c = document.createElement('canvas'); c.width = 400; c.height = 250;
      const ctx = c.getContext('2d'); ctx.fillStyle = '#ff0000'; ctx.fillRect(0, 0, 400, 250);
      Vue.createApp({ render: () => Vue.h(window.PaintComponent, { src: c.toDataURL(), cover, busy: false, onSave: async file => { window.saved = { size: file.size, type: file.type }; } }) }).mount('#app');
    }, cover);
    const save = page.getByRole('button', { name: 'Salva foto', exact: true });
    await save.waitFor();
    await page.waitForFunction(() => !Array.from(document.querySelectorAll('button')).find(b => b.textContent === 'Salva foto').disabled);
    const box = await page.locator('canvas').boundingBox();
    await page.mouse.move(box.x + 50, box.y + 50); await page.mouse.down();
    await page.mouse.move(box.x + 100, box.y + 50); await page.mouse.up();
    await page.waitForTimeout(50);
    assert.equal(await save.isEnabled(), true);
    const pixels = await page.locator('canvas').evaluate(c => {
      const ctx = c.getContext('2d'), data = ctx.getImageData(0, 0, c.width, c.height).data;
      let changedOutside = 0;
      for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
        if (x >= 38 && x <= 112 && y >= 38 && y <= 62) continue;
        const i = (y * c.width + x) * 4;
        if (data[i] !== 255 || data[i + 1] !== 0 || data[i + 2] !== 0 || data[i + 3] !== 255) changedOutside++;
      }
      return { center: Array.from(ctx.getImageData(75, 50, 1, 1).data), changedOutside };
    });
    assert.deepEqual(pixels.center, cover ? [0, 0, 0, 255] : [255, 255, 255, 255]);
    assert.equal(pixels.changedOutside, 0);
    await save.click();
    await page.waitForFunction(() => window.saved);
    assert.equal((await page.evaluate(() => window.saved)).type, 'image/png');
    await page.getByRole('button', { name: 'Annulla tratto' }).click();
    assert.equal(await page.locator('canvas').evaluate(c => c.getContext('2d').getImageData(75, 50, 1, 1).data[1]), 0);
  } finally { await browser.close(); }
});
