const { chromium } = require('playwright');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const directory = process.env.AUTOGENESI_BROWSER_DIR || path.resolve(__dirname, '../../storage/app/private/autogenesi/browser');
const profile = path.join(directory, 'profile');
const challengeFile = path.join(directory, 'last-challenge.json');

const endpoint = async () => {
  try {
    const [port, socket] = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').trim().split('\n');
    if (!/^\d+$/.test(port) || !socket?.startsWith('/devtools/browser/')) return null;
    const address = `http://127.0.0.1:${Number(port)}`;
    const response = await fetch(address + '/json/version', { signal: AbortSignal.timeout(600) });
    const data = await response.json();
    return data.webSocketDebuggerUrl?.endsWith(socket) ? address : null;
  } catch { return null; }
};

const connect = async () => {
  require('./run-control.cjs').assertActive();
  fs.mkdirSync(profile, { recursive: true, mode: 0o700 });
  let address = await endpoint();
  if (!address) {
    const preferences = path.join(profile, 'Default', 'Preferences');
    if (!fs.existsSync(preferences)) {
      fs.mkdirSync(path.dirname(preferences), { recursive: true, mode: 0o700 });
      fs.writeFileSync(preferences, JSON.stringify({ profile: { name: 'AutoGenesi' } }), { mode: 0o600 });
    }
    require('./run-control.cjs').assertActive();
    const log = fs.openSync(path.join(directory, 'browser.log'), 'a', 0o600);
    try {
      const child = spawn(process.env.AUTOGENESI_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
        '--user-data-dir=' + profile, '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--no-default-browser-check', 'about:blank',
      ], { detached: true, stdio: ['ignore', log, log] });
      child.on('error', () => {});
      child.unref();
      if (child.pid) fs.writeFileSync(path.join(directory, 'browser-process.json'), JSON.stringify({ pid: child.pid }), { mode: 0o600 });
      try { require('./run-control.cjs').assertActive(); }
      catch (error) { try { child.kill('SIGTERM'); } catch {} throw error; }
    } finally { fs.closeSync(log); }
    for (let i = 0; i < 30 && !address; i++) {
      await new Promise(resolve => setTimeout(resolve, 300));
      address = await endpoint();
    }
  }
  if (!address) throw new Error('AutoGenesi browser unavailable: open the dedicated browser on this Mac');
  const version = await (await fetch(address + '/json/version', { signal: AbortSignal.timeout(2000) })).json();
  const target = new URL(version.webSocketDebuggerUrl);
  if (target.protocol !== 'ws:' || !['127.0.0.1', 'localhost'].includes(target.hostname) || target.port !== new URL(address).port) throw new Error('Invalid local browser endpoint');
  const socket = new WebSocket(target.href);
  const transport = { send: message => socket.send(JSON.stringify(message)), close: () => socket.close() };
  socket.addEventListener('message', event => transport.onmessage?.(JSON.parse(event.data)));
  socket.addEventListener('close', () => transport.onclose?.());
  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => { socket.close(); reject(new Error('Browser connection timeout')); }, 6000);
    socket.addEventListener('open', () => { clearTimeout(timeout); resolve(); }, { once: true });
    socket.addEventListener('error', () => { clearTimeout(timeout); reject(new Error('Browser connection failed')); }, { once: true });
  });
  let browser;
  try { browser = await chromium.connectOverCDP(transport, { timeout: 10000, noDefaults: true }); }
  catch (error) { socket.close(); throw error; }
  const context = browser.contexts()[0];
  if (!context) { socket.close(); throw new Error('AutoGenesi browser context unavailable'); }
  const disconnect = async () => {
    if (socket.readyState === WebSocket.CLOSED) return;
    const closed = new Promise(resolve => socket.addEventListener('close', resolve, { once: true }));
    socket.close();
    await closed;
  };

  return { browser, context, disconnect };
};

const rememberChallenge = page => {
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  fs.writeFileSync(challengeFile, JSON.stringify({ url: page.url(), observed_at: new Date().toISOString() }), { mode: 0o600 });
};

const challengeUrl = fallback => {
  try {
    const u = new URL(JSON.parse(fs.readFileSync(challengeFile, 'utf8')).url);
    if (u.protocol === 'https:' && ['es.aliexpress.com', 'www.aliexpress.com'].includes(u.hostname) && !u.username && !u.password) return u.href;
  } catch {}
  try {
    const u = new URL(fallback);
    if (u.protocol === 'https:' && ['es.aliexpress.com', 'www.aliexpress.com'].includes(u.hostname) && !u.username && !u.password) return u.href;
  } catch {}
  return 'https://es.aliexpress.com/';
};

const closeExisting = async () => {
  const address = await endpoint();
  if (!address) {
    try {
      const { pid } = JSON.parse(fs.readFileSync(path.join(directory, 'browser-process.json'), 'utf8'));
      if (Number.isInteger(pid) && pid > 1) {
        const command = execFileSync('/bin/ps', ['-p', String(pid), '-o', 'command='], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
        if (command.includes('Google Chrome') && command.includes('--user-data-dir=' + profile)) process.kill(pid, 'SIGTERM');
      }
    } catch (error) { if (error.code !== 'ENOENT' && error.code !== 'ESRCH' && error.status !== 1) throw error; }
    return;
  }
  const version = await (await fetch(address + '/json/version', { signal: AbortSignal.timeout(2000) })).json();
  const url = new URL(version.webSocketDebuggerUrl);
  if (url.protocol !== 'ws:' || !['127.0.0.1', 'localhost'].includes(url.hostname) || url.port !== new URL(address).port) throw new Error('Invalid local browser endpoint');
  await new Promise((resolve, reject) => {
    const socket = new WebSocket(url.href);
    const timer = setTimeout(() => { socket.close(); reject(new Error('Chiusura browser non completata.')); }, 5000);
    const done = () => { clearTimeout(timer); resolve(); };
    socket.addEventListener('open', () => socket.send(JSON.stringify({ id: 1, method: 'Browser.close' })), { once: true });
    socket.addEventListener('close', done, { once: true });
    socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Browser AutoGenesi non raggiungibile.')); }, { once: true });
  });
};
module.exports = { connect, endpoint, rememberChallenge, challengeUrl, closeExisting };
