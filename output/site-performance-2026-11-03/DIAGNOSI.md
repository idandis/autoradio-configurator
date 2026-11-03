# Diagnosi siti pubblici — 3 novembre 2026

Misure desktop da questa connessione, cache browser disattivata, senza limitazione artificiale di banda. Sono campioni diagnostici, non medie degli utenti né Core Web Vitals sul campo. Nessuna modifica ai siti o al codice applicativo.

## AutoradioItaliano

URL: https://www.autoradioitaliano.it/

- HTML iniziale: circa 0,54 s con curl/browser UA; 0,99 s nella navigazione browser.
- Primo contenuto visibile: 17,54 s; ultimo campione LCP: 24,87 s. Evento load non ancora completato al termine della finestra di osservazione.
- Il JavaScript iniziale app-BeDJBQ01.js (422.725 byte) impiega 16,47 s nel browser e index.esm-v2-igikr9wf.js (292.029 byte) 13,37 s. Sono dipendenze necessarie per montare la pagina Vue/Inertia, quindi il loro arrivo condiziona la comparsa dei contenuti.
- Ricontrollo indipendente: app JS 0,52 s, index JS 5,42 s, hero WEBP 2,58 s. Prestazioni variabili, con ritardo prevalente nel trasferimento dopo il primo byte.
- Entrambi i JS sono serviti come text/javascript senza Content-Encoding anche richiedendo la compressione. Il CSS invece arriva gzip: 41.467 byte diventano 8.081 trasferiti.
- Sugli asset statici testati non sono presenti Cache-Control/Expires espliciti. L’HTML è correttamente distinto come no-cache, private.
- L’HTML include i dati del configuratore prima del montaggio: circa 293 KB non compressi, 39 KB gzip. Le proprietà maggiori sono le mappature immagini veicolo, le compatibilità e le zone di installazione.
- Nel campione completo: circa 2,12 MB trasferiti entro la finestra osservata; la hero arriva dopo l’esecuzione iniziale del frontend.

Priorità: verificare distribuzione statica Aruba (compressione text/javascript, cache dei file con hash e variabilità download), poi ridurre le dipendenze iniziali del configuratore e anticipare la richiesta dell’immagine principale. Il caricamento eager del configuratore rilevato nel codice locale pesa sulle pagine che non lo utilizzano; la homepage italiana usa invece il configuratore e richiede una separazione dei moduli più pesanti.

## AutoRadioCanario

URL finale del browser italiano: https://www.autoradiocanario.com/it

- Shopify/Cloudflare, piattaforma diversa dall’app Laravel Aruba di AutoradioItaliano.
- Primo byte HTML 0,61 s; primo contenuto e LCP osservato 1,38 s; evento load 2,20 s.
- 153 risorse osservate; circa 1,68 MB trasferiti. L’HTML iniziale è circa 1,1 MB non compresso; il browser lo riceve compresso.
- Tra i trasferimenti maggiori: Prefixbox circa 279 KB, Google Ads gtag circa 196 KB, Google Tag Manager circa 159 KB, Shopify web pixel manager circa 86 KB, Trustoo circa 84 KB. Sono presenti anche script Appio recensioni e altre risorse esterne.
- 80 tag script e 88 immagini nel documento HTML scaricato; non tutti corrispondono a download o esecuzione immediata.
- Nella sessione osservata non emerge il rallentamento di 17–25 s visto sul sito italiano. Il numero di risorse e gli script di app terze aumentano però il lavoro e il costo di rete, soprattutto su dispositivi e connessioni più lenti.

Priorità: verificare quali integrazioni Shopify sono effettivamente necessarie, specialmente ricerca e app recensioni; differire le risorse non necessarie alla prima schermata. Il costo causale di ogni app richiede un confronto controllato prima/dopo, non è dimostrato dalla sola presenza dello script.

## Limiti

Le prime richieste curl/headless al sito italiano ricevono HTTP 204 perché il codice blocca gli user agent automatici. Le misure valide sono state ripetute con normali header browser e risposta 200. Quella risposta 204 non è stata usata per diagnosticare la lentezza degli utenti.

Non sono state cambiate configurazioni hosting, tema Shopify, regole del bot o file applicativi. Per una misura mobile servirebbe una prova separata con CPU/rete limitate.
