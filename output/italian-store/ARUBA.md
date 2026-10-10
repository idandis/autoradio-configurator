Caricare i file dello ZIP nei medesimi percorsi relativi dell’app Laravel. Lo ZIP include anche i file della home e della correzione In stock già preparati.

File applicativi e immagini:

- `app/Http/Controllers/ConfiguratorController.php`
- `app/Http/Controllers/ItalianStorePageController.php`
- `app/Http/Controllers/StockProductsController.php`
- `app/Models/StockProduct.php`
- `bootstrap/app.php`
- `database/migrations/2026_10_10_120000_create_stock_products_table.php`
- `lang/en/configurator.php`
- `lang/es/configurator.php`
- `lang/it/configurator.php`
- `public/images/home-beta/hero.webp`
- `public/images/home-beta/per-marca.webp`
- `public/images/home-beta/per-modello.webp`
- `public/images/home-beta/universali.webp`
- `public/images/logo-it.png`
- `resources/data/italian-store-pages.json`
- `resources/js/app.js`
- `resources/js/components/HomeBetaContent.vue`
- `resources/js/components/ItalianStoreFooter.vue`
- `resources/js/components/ItalianStoreHeader.vue`
- `resources/js/components/StockProductsManager.vue`
- `resources/js/layouts/ItalianCheckoutLayout.vue`
- `resources/js/pages/Configurator.vue`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/ItalianStorePage.vue`
- `resources/views/area-not-served.blade.php`
- `routes/web.php`

Caricare anche `public/build/manifest.json` e tutti i file inclusi in `public/build/assets/`. Se la cartella pubblica di Aruba è separata, collocare build e images nella directory pubblica effettiva. Conservare gli asset precedenti per i browser già aperti.

Dopo il caricamento premere “Aggiorna database” in Dashboard per pulire le cache. Non serve SSH. Poi ricaricare la pagina nel browser.

Percorsi italiani: /chi-siamo, /privacy, /resi-e-rimborsi, /termini-del-servizio, /spedizioni, /contatti, /note-legali, /marche. I dati del titolare e l’indirizzo spagnolo sono mantenuti come confermato; email e telefono sono quelli italiani. I testi sono adattamenti italiani delle pagine sorgenti, non traduzioni integrali del negozio Shopify.

Verifiche: 10 test, 272 asserzioni; build Vite riuscita; JSON valido; Chrome a 320, 390 e 1440 pixel su home, marche e tutte le sette pagine, nessun overflow o collegamento Canario nelle nuove pagine. Verificati menu e selezione marca. Rimossa l’attribuzione GeoNames dall’interfaccia per tutte le lingue.
