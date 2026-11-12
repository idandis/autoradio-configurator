const fs = require('fs');
const puppeteer = require('puppeteer');

const [session, raw] = process.argv.slice(2);
let browser;
let stage = 'arguments';
const timer = setTimeout(() => process.exit(1), 65000);

(async () => {
    if (!/^[A-Za-z0-9_-]+$/.test(session)) throw new Error('session');
    const ids = JSON.parse(raw);
    if (!Array.isArray(ids) || !ids.length || ids.length > 2 || ids.some(id => !/^\d+@(?:c\.us|lid)$/.test(id))) throw new Error('ids');
    const file = `/app/.sessions/webjs/${session}/session-${session}/DevToolsActivePort`;
    stage = 'browser-file';
    const [port, path] = fs.readFileSync(file, 'utf8').trim().split('\n');
    if (!/^\d+$/.test(port) || !path.startsWith('/devtools/browser/')) throw new Error('browser');
    stage = 'browser-connect';
    browser = await puppeteer.connect({ browserWSEndpoint: `ws://127.0.0.1:${port}${path}` });
    stage = 'browser-page';
    const pages = (await browser.pages()).filter(p => new URL(p.url()).hostname === 'web.whatsapp.com');
    if (pages.length !== 1) throw new Error('page');
    stage = 'history-request';
    const result = await pages[0].evaluate(async ids => {
        const end = Date.now() + 45000;
        const seen = new Set();
        let requested = false, found = 0, before = 0, after = 0, limited = false;
        for (const id of ids) {
            const wid = window.require('WAWebWidFactory').createWid(id);
            const store = window.require('WAWebCollections').Chat;
            const chat = store.get(wid) ?? store.get(id) ?? store.getModelsArray().find(c => c.id?._serialized === id);
            if (!chat || seen.has(chat.id._serialized)) continue;
            seen.add(chat.id._serialized);
            found++;
            before += chat.msgs.getModelsArray().length;
            if (chat.endOfHistoryTransferType === 0) {
                await window.require('WAWebSendNonMessageDataRequest').sendPeerDataOperationRequest(3, { chatId: chat.id });
                requested = true;
                await new Promise(resolve => setTimeout(resolve, 1500));
            }
            for (let n = 0; n < 100; n++) {
                if (Date.now() >= end) { limited = true; break; }
                const rows = await window.require('WAWebChatLoadMessages').loadEarlierMsgs({ chat });
                if (!rows?.length) break;
                if (n === 99) limited = true;
            }
            after += chat.msgs.getModelsArray().length;
        }
        if (!found) throw new Error('chat');
        return { requested, before, after, limited };
    }, ids);
    process.stdout.write(JSON.stringify(result));
})().catch(() => { process.stderr.write(`Recupero storico WEBJS non riuscito (${stage}).`); process.exitCode = 1; })
    .finally(async () => { clearTimeout(timer); if (browser) await browser.disconnect(); });
