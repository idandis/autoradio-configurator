# Post-importazione — 5 novembre 2026

Carica il contenuto di `output/post-import-2026-11-05/aruba-post-import.zip` nella cartella del progetto Laravel su Aruba, conservando i percorsi. Lo ZIP contiene esattamente i 25 file elencati qui sotto: 12 cataloghi JSON e 13 immagini WEBP.

Poi premi **Aggiorna database** e ricarica la Dashboard. Il percorso esistente importa i cataloghi senza SSH. Nessuna build frontend o modifica PHP da caricare per questa attività. Non caricare database locale, `.env`, test, anteprima o documentazione.

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
- `public/images/vehicles-dark/peugeot-2008-2013-2019.webp`
- `public/images/vehicles-dark/hyundai-kona-2017-2020.webp`
- `public/images/vehicles-dark/volvo-xc40-2020-2020.webp`
- `public/images/vehicles-dark/opel-crossland-2020-2020.webp`
- `public/images/vehicles-dark/renault-zoe-2020-2020.webp`
- `public/images/vehicles-dark/skoda-karoq-2020-2020.webp`
- `public/images/vehicles-dark/citroen-c3-aircross-2020-2020.webp`
- `public/images/vehicles-dark/tesla-model-3-2020-2020.webp`
- `public/images/vehicles-dark/kia-ceed-2020-2020.webp`
- `public/images/vehicles-dark/citroen-c5-aircross-2020-2020.webp`
- `public/images/vehicles-dark/fiat-500x-2020-2020.webp`
- `public/images/vehicles-dark/ford-ecosport-2020-2020.webp`
- `public/images/vehicles-dark/kia-stonic-2020-2020.webp`

Completati i 13 titoli e le 13 descrizioni dell'allegato in italiano e inglese, nei cataloghi e nel database locale. Tutte le descrizioni locali di 441 schermi, 118 camere e 18 altoparlanti (577 per lingua) hanno traduzioni e sorgenti corrispondenti nei cataloghi. I cataloghi di camere e altoparlanti erano già completi e sono inclusi per il caricamento massivo. I titoli e gli HTML spagnoli originali sono invariati. Le 26 nuove descrizioni conservano esattamente tag HTML, attributi e sequenza dei numeri.

Generate 13 immagini distinte con imagegen integrato e convertite in WEBP 1536×1024, una per carrozzeria. Controllate visivamente nell'anteprima e singolarmente per Peugeot 2008. Il resolver riconosce tutti i percorsi richiesti. Stato locale: zero titoli mancanti, zero descrizioni mancanti, zero immagini mancanti e zero problemi veicolo da risolvere. Le attività completate scompaiono dalla Dashboard in base ai dati effettivi, senza nasconderle manualmente.

Attenzione ai dati sorgente: Kia Ceed è mappata al 2020 nel database, ma titolo e descrizione dichiarano 2010–2012. La foto segue la richiesta 2020 (terza generazione CD); la compatibilità commerciale va verificata prima della pubblicazione. Anche Renault ZOE è mappata al 2020 mentre il testo dichiara 2012–2019. Queste incongruenze non sono state corrette arbitrariamente.

Verifiche: 24 test su traduzioni titoli/descrizioni, aggiornamento database e immagini superati (693 asserzioni), incluso un nuovo test sui 13 prodotti che impedisce chiamate esterne e verifica l'importazione dal pulsante della Dashboard. Superato anche il test dell'importatore su HTML originale, conservazione delle traduzioni a sorgente invariato e invalidazione a sorgente cambiato (7 asserzioni). JSON validi, immagini decodificabili, copertura completa dei cataloghi descrizioni e sorgenti originali confrontati con lo stato iniziale.

La suite completa ConfiguratorImportTest contiene inoltre 12 fallimenti HTTP 403 e 4 errori su fixture/file temporanei e intestazioni obbligatorie; questi test esistenti non sono stati modificati. Il relativo test sulla conservazione delle descrizioni passa. Le altre modifiche già presenti nel workspace sono state lasciate intatte ed escluse dal pacchetto.

Prompt e sorgenti di generazione: `output/post-import-2026-11-05/PROMPT-IMMAGINI.md`. Anteprima: `output/post-import-2026-11-05/contact-sheet.jpg` (12 auto; Peugeot 2008 verificata separatamente). Elenco caricamento: `FILES-ARUBA.txt`; checksum: `SHA256SUMS.txt`.
