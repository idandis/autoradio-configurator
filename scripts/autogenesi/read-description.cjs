module.exports = async page => {
  await page.locator('#nav-description').scrollIntoViewIfNeeded({ timeout: 15000 });
  for (const selector of ['#nav-specification button', '#nav-description button']) {
    const button = page.locator(selector).first();
    if (await button.isVisible().catch(() => false) && /ver m[aá]s|view more|show more/i.test(await button.innerText())) await button.click();
  }
  await page.waitForFunction(() => {
    const host = document.querySelector('#product-description');
    if (!host) return false;
    const roots = [host, ...host.querySelectorAll('*')].flatMap(n => n.shadowRoot ? [n, n.shadowRoot] : [n]);
    return roots.some(n => n.querySelector('.detail-desc-decorate-richtext, .detailmodule_image, .detail-desc-decorate-image'));
  }, null, { timeout: 20000 });
  const data = await page.evaluate(() => {
    const host = document.querySelector('#product-description');
    const shadow = [host, ...host.querySelectorAll('*')].find(n => n.shadowRoot)?.shadowRoot;
    const root = shadow?.querySelector('#product-description') || shadow || host;
    const clone = root.cloneNode(true);
    clone.querySelectorAll('script,style,iframe').forEach(n => n.remove());
    const specs = [...document.querySelectorAll('#nav-specification [class*="specification--prop--"]')].map(n => ({
      name: n.querySelector('[class*="specification--title--"]')?.innerText?.trim(),
      value: n.querySelector('[class*="specification--desc--"]')?.innerText?.trim(),
    })).filter(s => s.name && s.value);
    return {
      text: root.innerText?.trim() || clone.textContent.trim(), html: clone.innerHTML,
      image_urls: [...root.querySelectorAll('img')].map(i => i.getAttribute('data-src') || i.getAttribute('src')).filter(Boolean),
      specifications: specs,
    };
  });
  if (!data.text && !data.image_urls.length) throw new Error('Original seller description could not be read');
  if (data.text.length > 120000 || data.image_urls.length > 60) throw new Error('Description exceeds supported size; nothing imported');
  if (!data.specifications.length) throw new Error('Full product specifications could not be read');
  return data;
};
