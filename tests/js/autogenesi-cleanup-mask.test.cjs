const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { parse, compileScript } = require('@vue/compiler-sfc');
const ts = require('typescript');
const { chromium } = require('playwright');

test('cleanup brush exports only selected areas as a mask, without painting the original', async () => {
  const root = path.resolve(__dirname, '../..');
  const sfc = parse(fs.readFileSync(path.join(root, 'resources/js/components/ImageCleanupMask.vue'), 'utf8'), { filename: 'ImageCleanupMask.vue' });
  const compiled = compileScript(sfc.descriptor, { id: 'cleanup-test', inlineTemplate: true });
  let script = ts.transpileModule(compiled.content, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
  script = script.replace(/import\s*\{([^}]+)\}\s*from\s*['"]vue['"];?/g, (_, imports) => `const {${imports.replace(/\s+as\s+/g, ': ')}} = Vue;`).replace('export default', 'window.CleanupComponent =');
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
  const css = fs.readFileSync(path.join(root, 'public/build', manifest['resources/css/app.css'].file), 'utf8');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
    await page.setContent(`<style>${css}</style><div id="app"></div>`);
    await page.addScriptTag({ path: path.join(root, 'node_modules/vue/dist/vue.global.js') });
    await page.addScriptTag({ content: script });
    await page.evaluate(() => {
      const c = document.createElement('canvas'); c.width = 400; c.height = 250;
      const ctx = c.getContext('2d'); ctx.fillStyle = '#ff0000'; ctx.fillRect(0, 0, 400, 250);
      window.originalSrc = c.toDataURL();
      Vue.createApp({ render: () => Vue.h(window.CleanupComponent, { src: window.originalSrc, busy: false, onSave: async ({ mask: file, strength }) => {
        const bmp = await createImageBitmap(file), out = document.createElement('canvas'); out.width = bmp.width; out.height = bmp.height;
        const oc = out.getContext('2d'); oc.drawImage(bmp, 0, 0);
        window.saved = { strength, width: bmp.width, height: bmp.height, type: file.type, inside: Array.from(oc.getImageData(75, 50, 1, 1).data), outside: Array.from(oc.getImageData(0, 0, 1, 1).data) };
      } }) }).mount('#app');
    });
    await page.waitForFunction(() => document.querySelector('canvas')?.width === 400);
    const save = page.getByRole('button', { name: 'Sfoca scritte selezionate' });
    await save.click();
    assert.match(await page.getByRole('alert').textContent(), /Evidenzia almeno/);
    const box = await page.locator('canvas').boundingBox();
    await page.mouse.move(box.x + 50, box.y + 50); await page.mouse.down();
    await page.mouse.move(box.x + 100, box.y + 50); await page.mouse.up();
    await save.click(); await page.waitForFunction(() => window.saved);
    assert.deepEqual(await page.evaluate(() => window.saved), { strength: 12, width: 400, height: 250, type: 'image/png', inside: [255, 255, 255, 255], outside: [0, 0, 0, 255] });
    assert.equal(await page.getByAltText('Foto originale da ripulire').getAttribute('src'), await page.evaluate(() => window.originalSrc));
  } finally { await browser.close(); }
});
