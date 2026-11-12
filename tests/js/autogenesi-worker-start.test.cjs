const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

test('worker launcher starts one detached process and reuses it on the next request', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'autogenesi-worker test-'));
  const directory = path.join(root, 'browser');
  fs.mkdirSync(directory);
  fs.writeFileSync(path.join(root, 'php-upload.ini'), 'max_execution_time=30\n');
  fs.writeFileSync(path.join(directory, 'control.json'), JSON.stringify({ run_id: 'first', stopped: false }));
  fs.writeFileSync(path.join(root, 'artisan'), `<?php
    $dir=getcwd().'/browser/'; $run=substr($argv[2]??'',9);
    $lock=fopen($dir.'worker.lock','c'); flock($lock,LOCK_EX);
    file_put_contents($dir.'worker.json',json_encode(['pid'=>getmypid(),'run_id'=>$run]));
    for($i=0;$i<100;$i++) { $state=json_decode(file_get_contents($dir.'control.json'),true); if($state['stopped']||$state['run_id']!==$run)break; usleep(100000); }
    @unlink($dir.'worker.json'); flock($lock,LOCK_UN); fclose($lock);
  `);
  const php = execFileSync('php', ['-r', 'echo PHP_BINARY;'], { encoding: 'utf8' });
  const launcher = path.resolve(__dirname, '../../scripts/autogenesi/start-worker.cjs');
  let pid;
  try {
    const first = JSON.parse(execFileSync(process.execPath, [launcher, php, root, directory], { encoding: 'utf8', timeout: 10000 }));
    pid = JSON.parse(fs.readFileSync(path.join(directory, 'worker.json'))).pid;
    const second = JSON.parse(execFileSync(process.execPath, [launcher, php, root, directory], { encoding: 'utf8', timeout: 10000 }));
    assert.deepEqual(first, { started: true, running: true });
    assert.deepEqual(second, { started: false, running: true });
    assert.equal(JSON.parse(fs.readFileSync(path.join(directory, 'worker.json'))).pid, pid);
    assert.equal(fs.existsSync(path.join(directory, 'worker-start.lock')), false);
    fs.writeFileSync(path.join(directory, 'control.json'), JSON.stringify({ run_id: 'second', stopped: false }));
    const restarted = JSON.parse(execFileSync(process.execPath, [launcher, php, root, directory], { encoding: 'utf8', timeout: 15000 }));
    assert.deepEqual(restarted, { started: true, running: true });
    const current = JSON.parse(fs.readFileSync(path.join(directory, 'worker.json')));
    assert.equal(current.run_id, 'second');
    assert.notEqual(current.pid, pid);
    pid = current.pid;
    const reused = JSON.parse(execFileSync(process.execPath, [launcher, php, root, directory], { encoding: 'utf8', timeout: 10000 }));
    assert.deepEqual(reused, { started: false, running: true });
  } finally {
    if (pid) { try { process.kill(pid, 'SIGTERM'); } catch {} }
    fs.rmSync(root, { recursive: true, force: true });
  }
});
