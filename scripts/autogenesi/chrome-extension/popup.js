const button = document.getElementById('capture');
const status = document.getElementById('status');
button.addEventListener('click', async () => {
  button.disabled = true;
  status.textContent = 'Acquisizione in corso. Non cambiare prodotto o variante in questa scheda…';
  try {
    const result = await chrome.runtime.sendMessage({ type: 'autogenesi-capture' });
    if (!result?.ok) throw new Error(result?.error || 'Acquisizione non completata.');
    status.textContent = `Scaricato ${result.filename}. In AutoGenesi apri «Importa dal tuo Chrome» e carica il file.`;
  } catch (error) { status.textContent = error.message; }
  finally { button.disabled = false; }
});

document.getElementById('enable').addEventListener('click', () => {
  chrome.permissions.request({ origins: ['https://*/*'] }).then(ok => {
    status.textContent = ok ? 'Altri siti abilitati. Premi «Acquisisci questo prodotto».' : 'Accesso non concesso. AliExpress resta disponibile.';
  }).catch(error => { status.textContent = error.message; });
});
