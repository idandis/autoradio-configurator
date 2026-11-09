# Post-importazione — 9 novembre 2026

Carica il contenuto di `output/post-import-2026-11-09/aruba-post-import.zip` nella cartella del progetto Laravel su Aruba, mantenendo gli stessi percorsi. Contiene esattamente i seguenti 15 file: 12 cataloghi JSON e tre immagini WEBP.

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
- `public/images/vehicles-dark/citroen-xsara-picasso-1999-2010.webp`
- `public/images/vehicles-dark/citroen-saxo-1996-2003.webp`
- `public/images/vehicles-dark/ford-mondeo-3-2000-2007.webp`

Poi premi **Aggiorna database** e ricarica la Dashboard. Il percorso esistente importa i cataloghi senza SSH e senza servizi esterni. Nessun file PHP o build frontend da caricare. Non caricare database locale, `.env`, test, documentazione, anteprima o PNG originali.

Completati titolo e descrizione dei sei prodotti dell'allegato in italiano e inglese: Citroen Saxo, Xsara Picasso, Ford Mondeo, Opel Astra/Zafira, Opel Combo/Corsa/Tigra e Renault Megane. I prodotti non sono nel database locale: le traduzioni sono nei cataloghi pronte per il server, senza aggiungere prodotti fittizi. I quattro cataloghi screen sono stati aggiornati; quelli camere e altoparlanti erano già completi e sono inclusi per il caricamento massivo.

Tutte le 590 descrizioni locali di 454 schermi, 118 camere e 18 altoparlanti hanno già traduzioni IT/EN. Nessun sorgente spagnolo locale modificato. HTML, attributi e sequenza numerica delle nuove traduzioni sono identici ai sorgenti dell'allegato. Conservate avvertenze e limiti, tra cui l'incompatibilità con i comandi originali al volante della Saxo e il possibile oscuramento dei pulsanti OEM di Astra/Zafira.

Tre fotografie distinte generate con imagegen integrato, una per carrozzeria: Xsara Picasso prima generazione, Saxo prima generazione e Mondeo Mk3. Salvate WEBP 1536×1024 e controllate visivamente nell'anteprima `contact-sheet.jpg`. Prompt e soggetti completi: `PROMPT-IMMAGINI.md`; percorsi dei PNG originali: `image-manifest.json`. Il resolver riconosce i file per ogni anno degli intervalli richiesti, anche passando come disponibile soltanto il nuovo file.

25 test superati, 928 asserzioni: traduzioni titoli/descrizioni, aggiornamento database, immagini e importatore CSV. Il test Dashboard è stato esteso ai sei nuovi handle: il pulsante importa le traduzioni dai cataloghi senza richieste esterne, conserva i sorgenti e azzera le attività traduzione completate. Il test importatore conferma salvataggio del sorgente, conservazione delle traduzioni a testo invariato e invalidazione a testo cambiato.

Tutti i JSON validi; le tre WEBP sono decodificabili e di dimensioni corrette; sorgenti locali confrontati con lo stato iniziale; nessuna immagine mancante nel database locale. ZIP confrontato con i file finali.
