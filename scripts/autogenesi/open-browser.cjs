const { connect, challengeUrl } = require('./browser-session.cjs');
const assertAccessible = require('./assert-accessible.cjs');

(async () => {
  require('./run-control.cjs').watch();
  const { context, disconnect } = await connect();
  try {
    let page;
    for (const current of context.pages()) {
      try { await assertAccessible(current); } catch { page = current; break; }
    }
    if (!page) {
      page = context.pages().find(p => /^https:\/\/(?:es|www)\.aliexpress\.com\//.test(p.url())) || await context.newPage();
      await page.goto(challengeUrl(process.argv[2]), { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
    }
    await page.bringToFront();
    console.log('Browser AutoGenesi aperto. Completa personalmente la verifica e lascia la finestra aperta.');
  } finally { await disconnect(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
