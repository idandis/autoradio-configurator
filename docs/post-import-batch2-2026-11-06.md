# Post-importazione secondo lotto — 6 novembre 2026

Carica il contenuto di `output/post-import-batch2-2026-11-06/aruba-post-import.zip` nella cartella del progetto Laravel su Aruba, mantenendo tutti i percorsi. Contiene esattamente questi 27 file: 12 cataloghi e 15 immagini WEBP.

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
- `public/images/vehicles-dark/renault-kadjar-2015-2019.webp`
- `public/images/vehicles-dark/mazda-cx-30-2020-2020.webp`
- `public/images/vehicles-dark/skoda-fabia-2007-2014.webp`
- `public/images/vehicles-dark/mercedes-clase-c-2019-2023.webp`
- `public/images/vehicles-dark/mercedes-clase-c-2021-2023.webp`
- `public/images/vehicles-dark/mercedes-glc-2019-2023.webp`
- `public/images/vehicles-dark/mercedes-glc-2022-2023.webp`
- `public/images/vehicles-dark/mercedes-clase-v-2019-2023.webp`
- `public/images/vehicles-dark/skoda-kamiq-2020-2022.webp`
- `public/images/vehicles-dark/seat-ateca-2016-2021.webp`
- `public/images/vehicles-dark/skoda-superb-2015-2019.webp`
- `public/images/vehicles-dark/volkswagen-polo-mk6-2020-2022.webp`
- `public/images/vehicles-dark/skoda-scala-2020-2022.webp`
- `public/images/vehicles-dark/peugeot-4008-2016-2022.webp`
- `public/images/vehicles-dark/peugeot-5008-2016-2022.webp`

Poi premi **Aggiorna database** e ricarica la Dashboard. Il flusso esistente importa le traduzioni senza SSH e senza chiamate esterne. Non serve una nuova build o il caricamento di file PHP. Non caricare database, `.env`, test, documentazione, anteprima o PNG originali.

## Traduzioni

Aggiunti 12 titoli e 12 descrizioni in italiano e inglese, conservando esattamente i sorgenti dell'allegato, la struttura HTML, gli attributi, le entità, gli eventuali link e i valori numerici. Sono cambiati soltanto i quattro cataloghi screen; i cataloghi camere e altoparlanti già completi sono inclusi nel pacchetto per la richiesta massiva.

I 12 prodotti non sono presenti nel database locale: nessun prodotto artificiale è stato aggiunto. Il database locale ha già tutte le 578 descrizioni tradotte (442 schermi, 118 camere e 18 altoparlanti). Nessun titolo o HTML spagnolo originale è stato modificato.

Conservate le avvertenze di compatibilità, versioni non confermate e accessori opzionali. L'handle `fiat-tipo-2020-2020-ae-1005013423271459` descrive una Fiat 500X: il testo avverte esplicitamente che la compatibilità con Tipo non è confermata. Non è stata introdotta una dichiarazione di compatibilità con Tipo. Analogamente sono state mantenute le incongruenze tecniche del testo spagnolo, senza correzioni arbitrarie.

## Immagini

Generate con imagegen integrato 15 fotografie distinte, salvate come WEBP 1536×1024 nei percorsi elencati e ispezionate nell'anteprima `contact-sheet.jpg`. Una fotografia per carrozzeria, riutilizzata per differenze minime e facelift.

Le 13 immagini richieste sono incluse. Aggiunte due immagini per i cambi di generazione Mercedes: `mercedes-clase-c-2021-2023.webp` (W206) e `mercedes-glc-2022-2023.webp` (X254). I percorsi generici 2019–2023 mostrano rispettivamente W205 e X253; il resolver esistente sceglie automaticamente l'intervallo più ristretto negli anni delle nuove generazioni. Il corretto passaggio è stato verificato, senza modificare il codice. Le fotografie non costituiscono conferma della compatibilità dell'autoradio con le diverse generazioni.

Peugeot 4008 rappresenta la carrozzeria cinese introdotta nel 2016, distinta dal precedente modello basato su Mitsubishi; Peugeot 5008 rappresenta la seconda generazione SUV. Prompt completi, soggetti e riferimenti alle fonti ufficiali: `PROMPT-IMMAGINI.md`. Tracciabilità dei PNG originali: `image-manifest.json`.

## Verifiche

25 test superati, 856 asserzioni: traduzioni titoli/descrizioni, Aggiorna database, immagini e importatore CSV. Esteso il test Dashboard ai 12 nuovi handle: l'importazione dei cataloghi avviene senza chiamate esterne, conserva gli originali e azzera le attività traduzione completate. Il test importatore conferma salvataggio del testo spagnolo, conservazione delle traduzioni a sorgente invariato e invalidazione quando cambia.

Tutti i JSON validi; HTML, attributi e numeri delle 24 nuove descrizioni controllati contro il sorgente. Immagini WEBP decodificabili, tutti gli anni dei 13 intervalli richiesti risolti dal configuratore; verificati i passaggi Mercedes W205→W206 e X253→X254. ZIP confrontato con i file finali. Le altre modifiche preesistenti nel workspace sono state lasciate intatte ed escluse dal pacchetto.
