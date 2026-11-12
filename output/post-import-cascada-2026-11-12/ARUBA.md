# Post-importazione Cascada — 12 novembre 2026

Carica il contenuto di `output/post-import-cascada-2026-11-12/aruba-post-import-cascada.zip` nella cartella Laravel su Aruba, mantenendo gli stessi percorsi. Contiene esattamente questi 13 file: 12 cataloghi e una fotografia WEBP.

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
- `public/images/vehicles-dark/opel-cascada-2009-2018.webp`

Poi premi **Aggiorna database** e ricarica la Dashboard. Il flusso esistente importa le traduzioni dai cataloghi senza SSH e senza chiamate esterne. Non serve una build o un nuovo file PHP. Non caricare database, `.env`, test o documentazione.

## Immagine

Generata con imagegen integrato una fotografia della Opel Cascada, colore argento, capote nera chiusa, vista anteriore a tre quarti, vettura intera, sfondo scuro e ombra naturale. WEBP 1536×1024 verificata e ispezionata visivamente. Il resolver riconosce `opel-cascada-2009-2018.webp` per tutti gli anni dell'intervallo richiesto. Il nome conserva l'intervallo fornito, ma la carrozzeria raffigurata è quella reale Cascada 2015; il modello fu introdotto nel 2013. Prompt, PNG originale e riferimento Opel: `PROMPT-IMMAGINE.md`.

## Traduzioni locali e cataloghi

Il riepilogo ricevuto non elencava traduzioni mancanti, ma il controllo locale ha rilevato 17 descrizioni non compilate in IT/EN. Sette avevano già un sorgente corrispondente nei cataloghi e sono state importate; dieci avevano sorgenti aggiornati e sono state tradotte e aggiornate nei due cataloghi screen-descriptions. Completate 34 colonne descrizione e importate anche 12 colonne titolo per sei prodotti dai cataloghi titoli già esistenti.

Le dieci descrizioni aggiornate riguardano Honda Jazz, Range Rover, Volvo S60/V60, Renault Logan/Sandero, Ford F150, Subaru Legacy, Suzuki Jimny, Mazda 6, Jaguar XJ/XJL e Porsche Panamera. Conservate le specifiche e le incongruenze dei testi spagnoli, senza aggiungere caratteristiche.

Tutte le 595 descrizioni locali sono ora disponibili in IT/EN: 459 schermi, 118 camere e 18 altoparlanti. Nessun titolo o HTML spagnolo originale modificato. Gli unici cataloghi modificati in questa attività sono screen-descriptions-it.json e screen-descriptions-en.json; gli altri dieci cataloghi sono inclusi per la richiesta massiva.

Le attività Cascada e traduzioni completate scompariranno dal server dopo il caricamento e l'aggiornamento, se i sorgenti server coincidono con quelli dei cataloghi. Nel database locale rimane una richiesta immagine separata per Opel Combo 2001–2011, assente nell'elenco ricevuto e non inclusa in questo intervento. Non è stata nascosta manualmente.

## Verifiche

25 test superati, 928 asserzioni: traduzioni titoli/descrizioni, aggiornamento database, immagini e importazione CSV. Verificati salvataggio del sorgente originale, conservazione delle traduzioni quando il testo non cambia e invalidazione a sorgente modificato. JSON validi, tag HTML e numeri delle descrizioni aggiornate preservati, originali locali confrontati con lo stato iniziale, WEBP decodificabile, tutti gli anni richiesti risolti. ZIP confrontato con i file finali.

Le numerose modifiche già presenti nel workspace sono state lasciate intatte ed escluse dal pacchetto.
