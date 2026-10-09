Caricare su Aruba tutti i 24 file seguenti nei medesimi percorsi del progetto, sovrascrivendo le copie esistenti. Lo ZIP conserva i percorsi relativi. La cartella public va nella directory pubblica del sito se su Aruba è separata dalla radice Laravel.

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

Poi premere “Aggiorna database” dalla Dashboard e ricaricarla. Non serve SSH né una nuova build frontend per queste modifiche.

Verifiche: 23 test passati; cataloghi JSON validati; 521 descrizioni locali già presenti coperte integralmente in italiano e inglese; 3 nuovi prodotti preparati nei cataloghi ma assenti dal database locale; originali spagnoli invariati. Il test del pulsante verifica tutte le traduzioni dei tre prodotti e la scomparsa delle attività di traduzione. Il test delle immagini verifica la risoluzione per ogni anno 2017–2025 e l’assenza delle attività relative ai veicoli richiesti. Altre attività non comprese nella richiesta possono rimanere visibili.

La prima immagine Amarok è riusata per la carrozzeria 2H e i suoi facelift. Il file aggiuntivo 2023–2025 rappresenta la carrozzeria NF. Il cambio di generazione è documentato da [Volkswagen](https://www.vw.co.za/en/volkswagen-experience/newsroom/cape-town-hosts-the-international-launch-of-the-all-new-amarok.html). MAN Van è rappresentato dal TGE introdotto nel 2017 ([Volkswagen Group](https://annualreport2017.volkswagenag.com/divisions/man.html)). Immagini generate con imagegen integrato; prompt in IMAGE-PROMPTS.json.

Non caricare database/database.sqlite, .env, tests o i documenti di output come file applicativi. Il database Aruba viene aggiornato dal pulsante usando i testi ES effettivamente presenti: le traduzioni vengono applicate alle sorgenti corrispondenti.
