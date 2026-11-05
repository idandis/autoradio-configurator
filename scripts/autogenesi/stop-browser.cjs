const { closeExisting } = require('./browser-session.cjs');
closeExisting().then(() => console.log('Browser AutoGenesi chiuso.')).catch(error => { console.error(error.message); process.exitCode = 1; });
