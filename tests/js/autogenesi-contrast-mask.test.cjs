const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const ts = require('typescript');
const vm = require('node:vm');
const source = fs.readFileSync('resources/js/lib/product-cutout.ts', 'utf8');
const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const context = { exports: {} }; vm.runInNewContext(code, context);
const mask = context.exports.contrastMask;
test('automatic contrast keeps tabs and enclosed white details, removes background and detached logo', () => {
  const w = 120, h = 100, data = new Uint8ClampedArray(w * h * 4).fill(255);
  const fill = (x0, y0, x1, y1, rgb) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) data.set([...rgb, 255], (y * w + x) * 4); };
  fill(20, 25, 99, 74, [20, 20, 20]);
  fill(25, 20, 30, 24, [20, 20, 20]);
  fill(35, 40, 55, 60, [255, 255, 255]);
  fill(113, 2, 117, 8, [255, 0, 0]);
  const result = mask(data, w, h, 40);
  assert.equal(result[0], 0);
  assert.equal(result[22 * w + 27], 1);
  assert.equal(result[50 * w + 45], 1);
  assert.equal(result[5 * w + 115], 0);
  assert.equal(result[25 * w + 20], 1);
  assert.equal(result[74 * w + 99], 1);
});
test('automatic contour reports images with no light background', () => {
  const data = new Uint8ClampedArray(100 * 100 * 4);
  for(let i = 3; i < data.length; i += 4) data[i] = 255;
  assert.throws(() => mask(data, 100, 100, 40), /Sfondo chiaro/);
});
