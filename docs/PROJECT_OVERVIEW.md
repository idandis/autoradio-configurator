# Shopify Configurator

## Scopo del progetto

Questo progetto serve a costruire un configuratore prodotti in stile automotive, guidato da un file CSV/Excel esportato da Shopify.

L'obiettivo funzionale attuale e':
- importare un export CSV Shopify
- normalizzare i dati in tabelle Laravel dedicate
- mostrare un configuratore pubblico con:
  - marca
  - modello
  - anno
  - schermo
  - camera
  - installazione
- fornire un'area admin per:
  - import CSV
  - controllo marche
  - controllo prodotti importati

## Stack

- Laravel 13
- Inertia.js
- Vue 3
- Tailwind CSS 4
- UI starter kit Laravel Vue recente come base frontend

## Flusso generale

### 1. Import CSV

Il file CSV Shopify viene caricato da dashboard oppure importato da CLI.

Ingressi principali:
- pagina admin: `/dashboard`
- comando CLI:

```bash
php artisan configurator:import-csv "/percorso/file.csv"
```

Componenti coinvolti:
- [ConfiguratorImportController.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Http/Controllers/ConfiguratorImportController.php)
- [ConfiguratorCsvImporter.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Services/ConfiguratorCsvImporter.php)
- [ImportConfiguratorCsv.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Console/Commands/ImportConfiguratorCsv.php)

### 2. Normalizzazione dati

Il CSV Shopify non viene usato direttamente dal frontend.

Durante l'import:
- i prodotti vengono raggruppati per `handle`
- vengono classificati in:
  - `screen`
  - `camera`
  - `installation`
- per i prodotti schermo vengono estratti:
  - marca
  - modello
  - anno iniziale/finale
- le varianti vengono salvate separatamente

Persistenza:
- [ConfiguratorProduct.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Models/ConfiguratorProduct.php)
- [ConfiguratorVariant.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Models/ConfiguratorVariant.php)
- migration:
  [2026_06_15_230000_create_configurator_products_table.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/database/migrations/2026_06_15_230000_create_configurator_products_table.php)

Supporto parsing titolo:
- [VehicleTitleParser.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Support/VehicleTitleParser.php)

## Pagine principali

### Area admin

#### `/dashboard`
Serve solo per:
- contatori generali
- upload CSV Shopify
- messaggio esito import

File:
- [DashboardController.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Http/Controllers/DashboardController.php)
- [Dashboard.vue](/Users/ianalongo/Desktop/easy2web/shopify-configurator/resources/js/pages/Dashboard.vue)

#### `/brands`
Tabella riepilogativa delle marche importate.

Mostra:
- marca
- numero modelli
- numero prodotti
- anni min/max
- prezzo minimo

File:
- [BrandsController.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Http/Controllers/BrandsController.php)
- [Brands.vue](/Users/ianalongo/Desktop/easy2web/shopify-configurator/resources/js/pages/Brands.vue)

#### `/imported-products`
Tabella paginata del dataset normalizzato presente nel DB.

Mostra:
- titolo
- handle
- categoria
- veicolo
- prezzo base
- numero varianti

Supporta:
- filtro categoria
- ricerca testuale

File:
- [ImportedProductsController.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Http/Controllers/ImportedProductsController.php)
- [ImportedProducts.vue](/Users/ianalongo/Desktop/easy2web/shopify-configurator/resources/js/pages/ImportedProducts.vue)

### Area pubblica

#### `/configurator`
Configuratore frontend ispirato allo screenshot fornito.

Stato attuale:
- selezione marca
- selezione modello
- selezione anno
- scelta variante schermo
- scelta camera
- scelta installazione
- riepilogo prezzo

File:
- [ConfiguratorController.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/app/Http/Controllers/ConfiguratorController.php)
- [Configurator.vue](/Users/ianalongo/Desktop/easy2web/shopify-configurator/resources/js/pages/Configurator.vue)

## Sidebar admin

Le voci attuali della sidebar sono:
- Dashboard
- Configuratore
- Marche
- Prodotti importati

File:
- [AppSidebar.vue](/Users/ianalongo/Desktop/easy2web/shopify-configurator/resources/js/components/AppSidebar.vue)

## Route principali

Definite in:
- [web.php](/Users/ianalongo/Desktop/easy2web/shopify-configurator/routes/web.php)

Route custom principali:
- `GET /dashboard`
- `POST /dashboard/import-csv`
- `GET /brands`
- `GET /imported-products`
- `GET /configurator`

## Cosa e' stato fatto fino ad ora

1. Aggiornata la base frontend verso il nuovo starter kit Laravel Vue.
2. Sistemato il layout admin con sidebar.
3. Creato il modello dati del configuratore nel database.
4. Creato l'importer CSV Shopify.
5. Creato il comando CLI per import.
6. Creata la dashboard admin per import.
7. Creata la pagina pubblica del configuratore.
8. Create le pagine admin separate per:
   - marche
   - prodotti importati

## Limitazioni attuali

Questa e' ancora una base MVP.

Punti ancora aperti:
- il parsing marca/modello/anni dipende dai titoli Shopify e non coprira' perfettamente tutti i casi
- camera e installazione sono mappate con regole pragmatiche, non ancora con un motore di compatibilita' completo
- il pulsante finale del configuratore non invia ancora i dati a un checkout/cart Shopify
- manca una UI admin per correggere manualmente mapping errati dopo l'import

## Prossimi passi consigliati

1. aggiungere pagina `Modelli`
2. aggiungere dettaglio prodotto admin con tabella varianti
3. introdurre correzione manuale mapping marca/modello/anni
4. salvare regole di compatibilita' esplicite invece di dedurle solo dal titolo
5. collegare il configuratore al carrello Shopify

## Comandi utili

Migrazioni:

```bash
php artisan migrate
```

Import CSV:

```bash
php artisan configurator:import-csv "/Users/ianalongo/Desktop/products_export_1 3.csv"
```

Avvio frontend:

```bash
npm run dev
```

Build:

```bash
npm run build
```
