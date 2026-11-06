const fs = require('node:fs');
const path = require('node:path');
module.exports = (phase, details = {}) => {
  const dir = process.env.AUTOGENESI_IMPORT_LOG_DIR;
  const id = process.env.AUTOGENESI_IMPORT_LOG_ID;
  if (!dir || !id) return;
  try {
    if (fs.readFileSync(path.join(dir, 'active'), 'utf8').trim() !== id) return;
    const line = JSON.stringify({ time: new Date().toISOString(), phase, details }).replace(/Bearer\s+[^"\s]+|sk-[a-zA-Z0-9_-]+/gi, '[redacted]');
    fs.appendFileSync(path.join(dir, 'import.log'), line + '\n');
  } catch {}
};
