const fs = require('node:fs');
const path = require('node:path');
const directory = process.env.AUTOGENESI_BROWSER_DIR || path.resolve(__dirname, '../../storage/app/private/autogenesi/browser');
const state = () => {
  try { return JSON.parse(fs.readFileSync(path.join(directory, 'control.json'), 'utf8')); }
  catch (e) { if (e.code === 'ENOENT') return { run_id: null, stopped: false }; throw e; }
};
const assertActive = () => { if (state().stopped) throw new Error('Ricerca interrotta dall’utente.'); };
const watch = () => {
  assertActive();
  const id = state().run_id;
  const timer = setInterval(() => {
    try {
      const current = state();
      if (!current.stopped && current.run_id === id) return;
    } catch {}
    console.error('Ricerca interrotta dall’utente.');
    process.exit(130);
  }, 200);
  timer.unref();
};
module.exports = { state, assertActive, watch };
