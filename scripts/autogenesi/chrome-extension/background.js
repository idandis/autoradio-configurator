let busy = false;
const imageUrl = value => {
  const url = new URL(value);
  const ali = ['alicdn.com', 'aliexpress-media.com'].some(host => url.hostname === host || url.hostname.endsWith('.' + host));
  if (url.username || url.password || url.port || !url.hostname.includes('.') || /(?:^|\.)(?:local|localhost|internal|test|invalid)$/.test(url.hostname) || /^\d+(?:\.\d+){3}$/.test(url.hostname) || url.hostname.includes(':')) throw new Error('Origine immagine non consentita.');
  if (!['http:', 'https:'].includes(url.protocol) || (!ali && url.protocol !== 'https:')) throw new Error('Protocollo immagine non consentito.');
  url.protocol = 'https:';
  if (ali) {
    url.pathname = url.pathname.replace(/\.(jpg|jpeg|png|webp)(?:_.*)$/i, '.$1');
    url.search = '';
  }
  url.hash = '';
  return url.href;
};
const base64 = bytes => {
  let binary = '';
  for (let i = 0; i < bytes.length; i += 32768) binary += String.fromCharCode(...bytes.subarray(i, i + 32768));
  return btoa(binary);
};
const acquire = async () => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !tab.url?.startsWith('https://')) throw new Error('Apri una scheda prodotto HTTPS.');
  imageUrl(tab.url);
  const ali = /^https:\/\/(?:es|www)\.aliexpress\.com\/item\/\d+\.html(?:[?#]|$)/.test(tab.url);
  if (!ali && !await chrome.permissions.contains({ origins: ['https://*/*'] })) throw new Error('Premi prima «Abilita altri siti» nel popup.');
  const [{ result: manifest }] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: [ali ? 'capture.js' : 'generic.js'] });
  if (!manifest?.source_id) throw new Error('Nessun prodotto acquisito.');
  let total = 0;
  const deadline = Date.now() + 180000;
  const downloaded = new Map();
  const fetchImage = async raw => {
    const url = imageUrl(raw);
    if (downloaded.has(url)) return downloaded.get(url);
    if (Date.now() > deadline) throw new Error('Tempo massimo acquisizione immagini superato.');
    await new Promise(resolve => setTimeout(resolve, 750));
    const current = await chrome.tabs.get(tab.id);
    if (!current.url || (ali ? new URL(current.url).origin + new URL(current.url).pathname : current.url.split('#')[0]) !== manifest.source_url) throw new Error('Scheda chiusa o prodotto cambiato: acquisizione interrotta.');
    const response = await fetch(url, { credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(15000) });
    if (!response.ok) throw new Error('Immagine non disponibile: HTTP ' + response.status);
    const blob = await response.blob();
    if (!blob.type.startsWith('image/') || !blob.size || blob.size > 8 * 1024 * 1024) throw new Error('Dimensione o formato immagine non valido.');
    const bitmap = await createImageBitmap(blob);
    try {
      if (Math.min(bitmap.width, bitmap.height) < 200 || bitmap.width * bitmap.height > 25000000) throw new Error('Risoluzione immagine non valida.');
      const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, bitmap.width, bitmap.height);
      ctx.drawImage(bitmap, 0, 0);
      const converted = await canvas.convertToBlob({ type: 'image/jpeg', quality: 0.95 });
      const bytes = new Uint8Array(await converted.arrayBuffer());
      if (bytes.length > 8 * 1024 * 1024 || total + bytes.length > 35 * 1024 * 1024) throw new Error('Pacchetto immagini troppo grande (massimo 35 MB).');
      total += bytes.length;
      const image = { source_url: url, bytes_base64: base64(bytes) };
      downloaded.set(url, image);
      return image;
    } finally { bitmap.close(); }
  };
  const images = [];
  for (const raw of [...new Set(manifest.gallery.map(imageUrl))]) {
    try { images.push({ ...await fetchImage(raw), file: `image-${images.length + 1}.bin` }); }
    catch (error) { if (ali || /Scheda chiusa|prodotto cambiato|Tempo massimo|troppo grande|No tab|HTTP (?:403|429)/i.test(error.message)) throw error; }
  }
  if (images.length < 3) throw new Error('Meno di tre immagini disponibili.');
  const descriptionImages = [];
  const unavailable = [];
  for (const raw of [...new Set(manifest.description.image_urls.map(imageUrl))]) {
    try { descriptionImages.push({ ...await fetchImage(raw), file: `description-${descriptionImages.length + 1}.bin` }); }
    catch (error) {
      if (/Scheda chiusa|prodotto cambiato|Tempo massimo|troppo grande|No tab|HTTP (?:403|429)/i.test(error.message)) throw error;
      unavailable.push({ source_url: raw, error: error.message });
    }
  }
  delete manifest.gallery;
  delete manifest.description.image_urls;
  manifest.images = images;
  manifest.description.images = descriptionImages;
  manifest.description.unavailable_images = unavailable;
  const filename = `autogenesi-${manifest.source_id}.json`;
  const bytes = new TextEncoder().encode(JSON.stringify({ format: 'autogenesi.chrome.v1', manifest }));
  if (bytes.length > 52 * 1024 * 1024) throw new Error('File di acquisizione troppo grande.');
  await chrome.downloads.download({ url: 'data:application/json;base64,' + base64(bytes), filename, saveAs: true });
  return filename;
};
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (sender.id !== chrome.runtime.id || !['autogenesi-capture', 'autogenesi-dsers'].includes(message?.type)) return;
  if (busy) { sendResponse({ ok: false, error: 'Acquisizione già in corso. Attendi oppure chiudi la scheda.' }); return; }
  busy = true;
  (message.type === 'autogenesi-dsers' ? acquireDsers() : acquire()).then(filename => sendResponse({ ok: true, filename }), error => sendResponse({ ok: false, error: error.message }))
    .finally(() => { busy = false; });
  return true;
});
const acquireDsers = async () => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https:\/\/([a-z0-9-]+\.)*dsers\.com\//i.test(tab.url || '')) throw new Error('Apri My Products nel tuo account DSers.');
  const [{ result }] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['dsers.js'] });
  if (!result?.rows?.length) throw new Error('Nessuna associazione leggibile. Apri Mapping di un prodotto e riprova: devono essere visibili titolo e link AliExpress.');
  const filename = `autogenesi-dsers-${Date.now()}.json`;
  await chrome.downloads.download({ url: 'data:application/json;base64,' + base64(new TextEncoder().encode(JSON.stringify(result))), filename, saveAs: true });
  return filename;
};
