# Caricamento Aruba post-importazione

## Volvo C30/S40/V50/C70 — 8 ottobre 2026

Carica questi sette file mantenendo i percorsi, poi premi **Aggiorna database**:

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`
- `public/images/vehicles-dark/volvo-c30-2004-2012.webp`
- `public/images/vehicles-dark/volvo-v50-2004-2012.webp`
- `public/images/vehicles-dark/volvo-c70-2004-2012.webp`

Titolo e HTML tradotti in IT/EN, inclusa l’avvertenza CD/AUX. Tag e numeri preservati. Prodotto assente nel database locale; cataloghi pronti per il server. Nessuna descrizione locale mancante. 16 test pertinenti superati (77 asserzioni); tre WEBP verificati e risolti dal configuratore. Nessuno ZIP. Questo promemoria non va caricato su Aruba.

Immagini generate con imagegen integrato, una per carrozzeria. Prompt comune: “Photorealistic studio vehicle catalogue photograph. Correct distinct factory body shape and proportions. Silver metallic paint. Front three-quarter view, whole automobile centred with all wheels visible and comfortable margins, landscape. Uniform #121212 background and matching floor, natural soft contact shadow, soft studio lighting. No people, no text, blank license plate, no watermark. This is one individual car photograph, not a collage.” Soggetti: Volvo C30 three-door hatchback, first generation, representative 2008 model; Volvo V50 five-door estate wagon, representative 2008 model; Volvo C70 second-generation two-door convertible with retractable hardtop CLOSED, representative 2008 model.

## SEAT Ibiza e Mitsubishi ASX — 8 ottobre 2026

Carica questi file, mantenendo gli stessi percorsi:

- `resources/data/camera-titles-it.json`
- `resources/data/camera-titles-en.json`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`

Poi premi **Aggiorna database**. Tradotti il titolo Ibiza e le due descrizioni in IT/EN; HTML e numeri preservati. Nessuna immagine richiesta, nessuno ZIP creato. I comandi di importazione locale non segnalano traduzioni mancanti. Questo documento è solo un promemoria e non va caricato sul server.

## Audi Chorus — 7 ottobre 2026

Carica soltanto questi file per il nuovo prodotto:

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`

Premi **Aggiorna database**. Titolo e descrizione Audi Chorus tradotti in IT/EN; HTML e numeri preservati, incluso il riferimento BMW presente nel testo spagnolo. Nessuna immagine richiesta e nessuno ZIP creato. Il prodotto non è nel database locale: i cataloghi sono pronti per il server. Nove test pertinenti superati.

## Aggiornamento Qashqai del 6 ottobre 2026

Carica questi file e premi **Aggiorna database**:

- `public/images/vehicles-dark/nissan-qashqai-2013-2022.webp`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`
- `app/Console/Commands/TranslateConfiguratorProductDescriptions.php`

I cataloghi contengono già le sei descrizioni richieste. I tre prodotti non sono presenti nel database locale; le 521 descrizioni locali risultano già tradotte. Nessun ZIP creato. JSON, tag HTML e numeri verificati; 13 test mirati superati (68 asserzioni).

Immagine generata con lo strumento integrato imagegen: Nissan Qashqai J11, carrozzeria rappresentativa del facelift 2017, fotografia realistica, vista anteriore a tre quarti, auto intera centrata, ombra naturale, sfondo #121212, nessuna persona o scritta aggiunta. File WEBP 1536 × 1024. Il configuratore risolve lo stesso file per 2013, 2017 e 2022, riutilizzando una sola immagine.

## Aggiornamento per le tre descrizioni ancora segnalate

Carica questi cinque file, poi premi **Aggiorna database**:

- `app/Console/Commands/TranslateConfiguratorProductDescriptions.php`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`

Le traduzioni erano già presenti. Il confronto ora tollera spazi e righe vuote ai bordi del sorgente, mantenendo invariato l’HTML spagnolo. Cambiamenti alle specifiche continuano a impedire l’importazione di traduzioni obsolete. I tre prodotti non esistono nel database locale; nessun originale locale è stato modificato.

Verifiche aggiornate: sei traduzioni, tag e numeri validati; sei test mirati superati, 59 asserzioni, inclusa la scomparsa delle attività di traduzione dalla Dashboard. Nessuna nuova immagine richiesta. Il file di test non serve su Aruba.

Carica questi file mantenendo gli stessi percorsi nella cartella del progetto Laravel:

- `resources/data/camera-titles-it.json`
- `resources/data/camera-titles-en.json`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`
- `resources/data/speaker-titles-it.json`
- `resources/data/speaker-titles-en.json`
- `resources/data/speaker-descriptions-it.json`
- `resources/data/speaker-descriptions-en.json`
- `public/images/vehicles-dark/honda-cr-v-2007-2012.webp`

Per assicurare che sul server siano presenti importazione delle descrizioni, conservazione delle traduzioni e aggiornamento dalla Dashboard, carica anche:

- `app/Console/Commands/TranslateConfiguratorProductTitles.php`
- `app/Console/Commands/TranslateConfiguratorProductDescriptions.php`
- `app/Http/Controllers/DatabaseMigrationController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Services/ConfiguratorCsvImporter.php`
- `app/Services/VehicleImageResolver.php`
- `app/Models/ConfiguratorProduct.php`
- `resources/data/vehicle-image-generations.json`
- `database/migrations/2026_09_23_120000_add_body_html_to_configurator_products_table.php`
- `database/migrations/2026_09_23_130000_ensure_body_html_exists_on_configurator_products_table.php`
- `database/migrations/2026_09_26_120000_add_translated_descriptions_to_configurator_products.php`

Poi premi **Aggiorna database** nella Dashboard. I cataloghi vengono importati solo quando il testo spagnolo coincide con `source`; le attività completate scompaiono al successivo caricamento della Dashboard. Non caricare il database locale o `.env`.

Verifiche: JSON validi; otto prodotti richiesti coperti in IT/EN; struttura HTML preservata; 521 descrizioni locali già tradotte; nessuna traduzione locale mancante. Cinque prodotti richiesti non esistono nel database locale: i cataloghi sono pronti per il database del server. La descrizione Opel 9,7″ locale è una versione precedente: il catalogo segue il testo allegato, mentre l’originale locale resta invariato.

L’immagine WEBP Honda CR-V 2007–2012 esistente è stata verificata e riutilizzata.

17 test mirati superati (47 asserzioni): descrizione importata e traduzioni preservate/invalidate, titoli, aggiornamento database, immagini. La suite generale di importazione presenta 12 fallimenti (403), quattro errori (file temporanei/header) e un test risky; non è interamente superata.
