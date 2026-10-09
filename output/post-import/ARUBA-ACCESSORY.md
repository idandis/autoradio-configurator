Pacchetto completo aggiornato: 28 file, inclusi i file del precedente intervento.

Caricare i seguenti file su Aruba nei medesimi percorsi relativi del progetto e sovrascrivere le copie esistenti. Se la cartella pubblica su Aruba è separata dalla radice Laravel, collocare public/images nella directory pubblica effettiva.

- `app/Console/Commands/TranslateConfiguratorProductTitles.php`
- `app/Console/Commands/TranslateConfiguratorProductDescriptions.php`
- `app/Http/Controllers/DatabaseMigrationController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Services/ConfiguratorCsvImporter.php`
- `app/Models/ConfiguratorProduct.php`
- `database/migrations/2026_09_23_130000_ensure_body_html_exists_on_configurator_products_table.php`
- `database/migrations/2026_09_26_120000_add_translated_descriptions_to_configurator_products.php`
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
- `resources/data/vehicle-image-generations.json`
- `public/images/vehicles-dark/volkswagen-amarok-2017-2025.webp`
- `public/images/vehicles-dark/volkswagen-amarok-2023-2025.webp`
- `public/images/vehicles-dark/man-van-2017-2025.webp`
- `resources/data/accessory-titles-it.json`
- `resources/data/accessory-titles-en.json`
- `resources/data/accessory-descriptions-it.json`
- `resources/data/accessory-descriptions-en.json`

Premere “Aggiorna database” dalla Dashboard e ricaricarla. Le traduzioni dell’accessorio vengono applicate se titolo e descrizione spagnoli corrispondono alle sorgenti dei cataloghi. Non serve SSH né una build frontend per questo aggiornamento. Le attività completate spariscono; eventuali altre attività restano visibili.

Verifiche: 521 descrizioni del database locale già interamente tradotte in IT/EN e coperte nei cataloghi; JSON validi; struttura HTML e dati tecnici dell’accessorio preservati; immagini esistenti valide, nessuna nuova immagine richiesta. L’accessorio non è presente nel database locale: i quattro JSON preparano le traduzioni per il prodotto importato su Aruba. Il test del pulsante verifica importazione IT/EN e scomparsa dell’attività dalla Dashboard senza richieste esterne; il test di reimport verifica conservazione delle traduzioni con sorgente invariata e invalidazione quando cambia.

Non caricare database/database.sqlite, .env, tests o output come file applicativi.
