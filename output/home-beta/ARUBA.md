Home beta Autoradio Italiano

Anteprima: /home-beta. La home pubblica attuale rimane disponibile sul percorso /; la beta riutilizza header, logo e footer del configuratore e aggiunge WhatsApp in alto a destra.

Caricare i file dello ZIP nei medesimi percorsi relativi del progetto Laravel, sovrascrivendo le copie esistenti. Caricare anche tutta la cartella public/build generata. Se la directory pubblica Aruba è separata dalla radice Laravel, collocare public/build e public/images nella directory pubblica effettiva.

File applicativi:

- `app/Http/Controllers/ConfiguratorController.php`
- `app/Http/Controllers/StockProductsController.php`
- `app/Models/StockProduct.php`
- `bootstrap/app.php`
- `routes/web.php`
- `database/migrations/2026_10_10_120000_create_stock_products_table.php`
- `resources/js/components/HomeBetaContent.vue`
- `resources/js/components/StockProductsManager.vue`
- `resources/js/pages/Configurator.vue`
- `resources/js/pages/Dashboard.vue`
- `public/images/home-beta/hero.webp`
- `public/images/home-beta/per-modello.webp`
- `public/images/home-beta/universali.webp`
- `public/images/home-beta/per-marca.webp`
- `public/build/manifest.json` e tutti i file di `public/build/assets/` inclusi nello ZIP.

Dopo il caricamento, premere “Aggiorna database” in Dashboard per creare la tabella stock_products e pulire le cache. Aprire la sezione In stock in Dashboard: cercare per titolo, SKU o handle; scegliere un prodotto del catalogo; inserire quantità e salvare. Le schede compaiono subito nella beta. Una quantità zero nasconde la scheda; rimuovere la selezione non elimina il prodotto dal catalogo. Le selezioni seguono l’handle e si conservano nei reimport; i prodotti assenti dal catalogo non vengono mostrati.

La tabella In stock locale è inizialmente vuota: nessun prodotto è stato dichiarato disponibile arbitrariamente. L’anteprima mobile con quattro schede usa soltanto una copia temporanea del database per la verifica visiva.

Verifiche: build Vite riuscita; test di autorizzazione admin, selezione/aggiornamento/rimozione stock, ricerca SKU, validazione quantità, prodotti esauriti/assenti, localizzazione italiana e persistenza per handle; verifica browser a 390 e 1440 px, immagini caricate, apertura dettagli e ritorno alla home. Il controllo TypeScript globale segnala errori già presenti in componenti di autenticazione e altre pagine; nessun errore nei nuovi componenti.

Fotografie create con imagegen integrato. Destinazioni e prompt in IMAGE-PROMPTS.json. Non caricare .env, database/database.sqlite, tests o output come file applicativi.
