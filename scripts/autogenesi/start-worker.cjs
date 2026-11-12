const fs = require('node:fs');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const [php, root, directory] = process.argv.slice(2);
if (!php || !root || !directory || !path.isAbsolute(php) || !path.isAbsolute(root) || !path.isAbsolute(directory)) throw new Error('Invalid worker paths');
fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
const stateFile = path.join(directory, 'worker.json');
const startup = path.join(directory, 'worker-start.lock');
const generation = () => {
  try { return JSON.parse(fs.readFileSync(path.join(directory, 'control.json'), 'utf8')); }
  catch (error) { if (error.code === 'ENOENT') return { run_id: null, stopped: false }; throw error; }
};
const expected = generation();
const assertActive = () => { const state = generation(); if (state.stopped || state.run_id !== expected.run_id) throw new Error('Ricerca fermata durante l’avvio del processo'); };
const running = () => {
  try {
    const { pid, run_id = null } = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    if (run_id !== expected.run_id) return false;
    if (!Number.isInteger(pid) || pid <= 1) return false;
    process.kill(pid, 0);
    const command = execFileSync('/bin/ps', ['-p', String(pid), '-o', 'command='], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    return command.includes(path.join(root, 'artisan')) && command.includes('autogenesi:work');
  } catch { return false; }
};
(async () => {
  let lock;
  for (let n = 0; n < 60; n++) {
    assertActive();
    if (running()) { console.log(JSON.stringify({ started: false, running: true })); return; }
    try { lock = fs.openSync(startup, 'wx', 0o600); break; }
    catch (error) {
      if (error.code !== 'EEXIST') throw error;
      try { if (Date.now() - fs.statSync(startup).mtimeMs > 15000) fs.unlinkSync(startup); } catch {}
      await new Promise(resolve => setTimeout(resolve, 200));
    }
  }
  if (lock === undefined) throw new Error('Worker startup already in progress');
  try {
    if (running()) { console.log(JSON.stringify({ started: false, running: true })); return; }
    const log = fs.openSync(path.join(directory, 'worker.log'), 'a', 0o600);
    try {
      const child = spawn(php, ['-c', path.join(root, 'php-upload.ini'), path.join(root, 'artisan'), 'autogenesi:work', ...(expected.run_id ? ['--run-id=' + expected.run_id] : [])], {
        cwd: root, detached: true, stdio: ['ignore', log, log],
      });
      let failure; child.on('error', error => { failure = error; }); child.unref();
      for (let n = 0; n < 100; n++) {
        assertActive();
        if (failure) throw failure;
        if (running()) { console.log(JSON.stringify({ started: true, running: true })); return; }
        if (child.exitCode !== null) throw new Error('Worker exited during startup');
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      throw new Error('Worker did not start; inspect worker.log');
    } finally { fs.closeSync(log); }
  } finally { fs.closeSync(lock); try { fs.unlinkSync(startup); } catch {} }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
