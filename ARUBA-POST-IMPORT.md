# Caricamento Aruba post-importazione

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
