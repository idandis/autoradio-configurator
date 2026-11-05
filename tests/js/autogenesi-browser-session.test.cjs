const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

test('dedicated browser preserves tabs and cookies after automation disconnects', { timeout: 25000 }, async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'autogenesi-cdp-test-'));
  process.env.AUTOGENESI_BROWSER_DIR = directory;
  const session = require('../../scripts/autogenesi/browser-session.cjs');
  const child = spawn(process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
    '--headless=new', '--disable-background-networking', '--no-first-run', '--no-default-browser-check',
    '--user-data-dir=' + path.join(directory, 'profile'), '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0', 'about:blank',
  ], { stdio: 'ignore' });
  let launchError;
  child.on('error', error => { launchError = error; });
  let first;
  let second;
  try {
    let ready;
    for (let i = 0; i < 40 && !ready; i++) {
      if (launchError) throw launchError;
      ready = await session.endpoint();
      if (!ready) await new Promise(resolve => setTimeout(resolve, 200));
    }
    assert.ok(ready);
    first = await session.connect();
    await first.context.addCookies([{ name: 'autogenesi_test', value: 'shared', domain: 'example.invalid', path: '/' }]);
    const page = await first.context.newPage();
    await page.setContent('<p>Manual verification page preserved</p>');
    await first.disconnect();
    assert.ok(await session.endpoint());
    second = await session.connect();
    const cookies = await second.context.cookies('https://example.invalid');
    assert.equal(cookies.find(c => c.name === 'autogenesi_test')?.value, 'shared');
    const texts = await Promise.all(second.context.pages().map(p => p.locator('body').innerText()));
    assert.ok(texts.includes('Manual verification page preserved'));
    assert.equal(session.challengeUrl('https://outside.example/'), 'https://es.aliexpress.com/');
    assert.equal(session.challengeUrl('https://es.aliexpress.com/item/123.html'), 'https://es.aliexpress.com/item/123.html');
    session.rememberChallenge({ url: () => 'https://es.aliexpress.com/item/456.html' });
    assert.equal(session.challengeUrl(), 'https://es.aliexpress.com/item/456.html');
    fs.writeFileSync(path.join(directory, 'control.json'), JSON.stringify({ run_id: 'stop', stopped: true }));
    await session.closeExisting();
    for (let i = 0; i < 20 && await session.endpoint(); i++) await new Promise(resolve => setTimeout(resolve, 100));
    assert.equal(await session.endpoint(), null);
    await assert.rejects(session.connect(), /Ricerca interrotta/);
  } finally {
    if (second?.browser.isConnected()) {
      const cdp = await second.browser.newBrowserCDPSession().catch(() => null);
      await cdp?.send('Browser.close').catch(() => {});
    }
    await first?.disconnect().catch(() => {});
    await second?.disconnect().catch(() => {});
    if (child.exitCode === null) child.kill('SIGTERM');
    await new Promise(resolve => { if (child.exitCode !== null) resolve(); else { child.once('exit', resolve); setTimeout(resolve, 3000); } });
    await fs.promises.rm(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 }).catch(() => {});
  }
});
