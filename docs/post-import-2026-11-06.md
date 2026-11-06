# Post-importazione — 6 novembre 2026

Carica il contenuto di `output/post-import-2026-11-06/aruba-post-import.zip` nella cartella del progetto Laravel su Aruba, mantenendo i percorsi. Contiene esattamente i seguenti 22 file: 12 cataloghi JSON, nove immagini richieste e un alias Volvo per il diverso intervallo nel database locale.

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`
- `resources/data/camera-titles-it.json`
- `resources/data/camera-titles-en.json`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/speaker-titles-it.json`
- `resources/data/speaker-titles-en.json`
- `resources/data/speaker-descriptions-it.json`
- `resources/data/speaker-descriptions-en.json`
- `public/images/vehicles-dark/kia-stonic-2017-2022.webp`
- `public/images/vehicles-dark/volvo-xc40-2016-2026.webp`
- `public/images/vehicles-dark/opel-crossland-2016-2020.webp`
- `public/images/vehicles-dark/renault-zoe-2012-2019.webp`
- `public/images/vehicles-dark/skoda-karoq-2016-2021.webp`
- `public/images/vehicles-dark/citroen-c3-aircross-2017-2023.webp`
- `public/images/vehicles-dark/tesla-model-3-2019-2023.webp`
- `public/images/vehicles-dark/citroen-c5-aircross-2017-2021.webp`
- `public/images/vehicles-dark/fiat-500x-2014-2020.webp`
- `public/images/vehicles-dark/volvo-xc40-2015-2026.webp`

Premi **Aggiorna database**, poi ricarica la Dashboard. Il flusso esistente importa titoli e descrizioni dai cataloghi senza SSH e senza chiamate esterne. Nessuna nuova build o file PHP da caricare. Non caricare database, `.env`, test, documentazione o i sorgenti originali PNG.

## Traduzioni

Aggiornati i cataloghi IT/EN dei 13 prodotti dell'allegato, usando i nuovi sorgenti esatti, compresi gli a capo e i tag modificati. Conservati valori, entità HTML e specifiche, incluse le nuove indicazioni &gt;6GB per Ceed e 8cores per Stonic. Rimosse dalle traduzioni le frasi che non compaiono più nel nuovo HTML spagnolo. Titoli aggiornati per C3 Aircross 2017-2023, Stonic 2017–2022 e Volvo XC40 2016-2026.

I prodotti esistono anche localmente, ma i loro sorgenti spagnoli differiscono dall'allegato e avevano già le traduzioni. Non sono stati sovrascritti con i testi del server. Sono state applicate le traduzioni aggiornate solo dove il sorgente locale coincide. Tutte le 577 descrizioni locali (441 schermi, 118 camere, 18 altoparlanti) sono complete in IT/EN. I cataloghi camere e altoparlanti erano già completi; sono inclusi per il caricamento massivo. I quattro cataloghi screen sono quelli modificati in questa attività.

I nuovi cataloghi sono destinati ai sorgenti aggiornati dell'allegato sul server. Le verifiche automatiche confermano che il pulsante Aggiorna database importa correttamente tutti i 13 prodotti e che le attività traduzione completate spariscono dalla Dashboard. Il testo spagnolo originale locale è rimasto identico allo stato iniziale.

## Immagini

Riutilizzate le fotografie generate con imagegen integrato il 5 novembre: nessuna nuova generazione, poiché le carrozzerie sono le stesse. Copie WEBP 1536×1024 nei nove percorsi richiesti. L'alias `volvo-xc40-2015-2026.webp` copre il diverso intervallo già presente nel database locale; non modifica né conferma gli anni commerciali del veicolo. La stessa foto Volvo è condivisa dai due nuovi nomi.

Anche ZOE e Fiat 500X riutilizzano la foto della medesima carrozzeria, come richiesto per facelift e differenze minime. I nomi dei file seguono gli intervalli forniti, senza reinterpretare i dati di compatibilità. Per prompt e fotografie originali vedere `output/post-import-2026-11-05/PROMPT-IMMAGINI.md` e `output/post-import-2026-11-05/image-manifest.json`.

## Verifiche

Tutti i JSON validi; per i 13 prodotti sorgenti uguali all'allegato, tag/attributi HTML e sequenza numerica invariati nelle traduzioni. Dieci immagini WEBP decodificabili e copie identiche agli originali già generati; risoluzione dei nove percorsi verificata per ogni anno degli intervalli, anche simulando la presenza del solo nuovo file.

24 test su traduzioni, aggiornamento database e risoluzione immagini superati (693 asserzioni); superato anche il test importatore che verifica salvataggio HTML, conservazione delle traduzioni a sorgente invariato e invalidazione a sorgente cambiato (7 asserzioni). Nessuna traduzione locale mancante. ZIP verificato rispetto ai file finali. Nessuna modifica alle altre attività già presenti nel workspace.
