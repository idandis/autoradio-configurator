const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

test('stop prevents browser launch and interrupts existing work even after an explicit restart', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'autogenesi-stop-'));
  const file = path.join(dir, 'control.json');
  const modulePath = path.resolve(__dirname, '../../scripts/autogenesi/run-control.cjs');
  let child;
  try {
    fs.writeFileSync(file, JSON.stringify({ run_id: 'first', stopped: false }));
    child = spawn(process.execPath, ['-e', `require(${JSON.stringify(modulePath)}).watch(); process.stdout.write('ready'); setInterval(()=>{},1000)`], { env: { ...process.env, AUTOGENESI_BROWSER_DIR: dir }, stdio: ['ignore', 'pipe', 'ignore'] });
    await new Promise(resolve => child.stdout.once('data', resolve));
    const exited = new Promise(resolve => child.once('exit', resolve));
    fs.writeFileSync(file, JSON.stringify({ run_id: 'second', stopped: false }));
    assert.equal(await exited, 130);
    fs.writeFileSync(file, JSON.stringify({ run_id: 'third', stopped: true }));
    const script = `require(${JSON.stringify(path.resolve(__dirname, '../../scripts/autogenesi/browser-session.cjs'))}).connect().then(()=>process.exit(1)).catch(()=>process.exit(0))`;
    const attempt = spawn(process.execPath, ['-e', script], { env: { ...process.env, AUTOGENESI_BROWSER_DIR: dir }, stdio: 'ignore' });
    assert.equal(await new Promise(resolve => attempt.once('exit', resolve)), 0);
    assert.equal(fs.existsSync(path.join(dir, 'profile')), false);
  } finally {
    if (child?.exitCode === null) child.kill();
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
