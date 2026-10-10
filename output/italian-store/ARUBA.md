Caricare i file dello ZIP nei medesimi percorsi relativi dell’app Laravel. Lo ZIP include anche i file della home e della correzione In stock già preparati.

File applicativi e immagini:

- `app/Http/Controllers/ConfiguratorController.php`
- `app/Http/Controllers/ItalianStorePageController.php`
- `app/Http/Controllers/StockProductsController.php`
- `app/Models/StockProduct.php`
- `app/Models/ConfiguratorProduct.php`
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
- `public/images/foto-chi-siamo.webp`
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

I prodotti in stock aprono /configurator?lang=it&stock=ID. Marca e modello sono la prima compatibilità del database; l’anno è il più recente nell’intervallo, senza superare l’anno corrente quando possibile. Il prodotto mostra le varianti prima dell’aggiunta al carrello. Per gli universali viene selezionato il DIN del database. I link a prodotti non più in stock restituiscono 404. Verifica: 11 test, 295 asserzioni; build e browser desktop/mobile riusciti.

La pagina /marche è stata eliminata e reindirizza a /configurator?lang=it&mode=specific&pick=brand. Per marca e Per modello della home aprono direttamente il selettore, con 40 loghi a sinistra e nomi centrati. Il selettore è disponibile anche dal campo Marca del configuratore. Caricare lo ZIP autoradioitaliano-menu-marche-aruba.zip nei percorsi indicati e premere Aggiorna database per svuotare le cache delle route. Verificati il percorso marca/modello/anno su desktop e mobile, 11 test e 296 asserzioni.

Sconti home: caricare tutti i file di autoradioitaliano-offerte-sconto-aruba.zip nei percorsi inclusi e premere Aggiorna database per aggiungere discount_percent. Dashboard > In offerta: Sconto % da 0 a 100, salvato al cambio del campo. 0 nasconde la targhetta, valori positivi mostrano -N% in giallo sulla foto. Il prezzo non viene modificato. Test: 13 test, 333 asserzioni; build Vite riuscita.
