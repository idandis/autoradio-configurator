const { rememberChallenge } = require('./browser-session.cjs');

module.exports = async page => {
  for (const frame of page.frames()) {
    if (frame !== page.mainFrame() && !await frame.frameElement().then(el => el.isVisible()).catch(() => false)) continue;
    const text = await frame.locator('body').innerText({ timeout: 1000 }).catch(() => '');
    if (/unusual traffic|tr[aá]fico (?:inusual|inusitado|an[oó]malo)|traffico (?:insolito|anomalo)/i.test(text)) {
      rememberChallenge(page);
      throw new Error('Network traffic blocked; automation stopped');
    }
  }
  const frames = page.locator('iframe[src*="/punish"], iframe[src*="captcha"], iframe[src*="x5sec"]');
  for (let i = 0; i < await frames.count(); i++) {
    if (await frames.nth(i).isVisible()) {
      rememberChallenge(page);
      throw new Error('Verification required; automation stopped');
    }
  }
  if (/verify you are human|verifica que eres humano/i.test(await page.locator('body').innerText())) {
    rememberChallenge(page);
    throw new Error('Verification required; automation stopped');
  }
};
