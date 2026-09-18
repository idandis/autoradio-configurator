# Ordini italiani — fase 1

Aggiornamento: la fase successiva è disponibile su localhost; vedere [Checkout italiano — fase 2](checkout-italiano-fase-2.md). Il resto di questo documento descrive la prima fase.

La voce **Ordini italiani** nel menu amministrativo apre `/italian-orders`.

Questa fase introduce la gestione degli ordini che saranno ricevuti dal futuro checkout italiano. Non attiva pagamenti, non crea ordini dal configuratore e non invia email. Nessun ordine dimostrativo è stato inserito nel database locale. Le informazioni e il comportamento relativi all'installazione sono invariati.

## Funzioni disponibili

- Elenco paginato, ricerca per numero/nome/email e filtri separati per pagamento e spedizione.
- Dettaglio con cliente, indirizzi, prodotti, varianti, SKU, quantità e importi.
- Stato pagamento consultabile, non modificabile dal modulo amministrativo.
- Preparazione, spedizione e consegna; corriere e tracking obbligatori per spedire.
- Blocco della preparazione/spedizione prima del pagamento; gli ordini parzialmente rimborsati possono proseguire. La consegna può essere registrata anche se un ordine già spedito viene successivamente rimborsato.
- Note interne e cronologia delle modifiche con operatore e data.
- Controllo di versione per evitare che una pagina obsoleta sovrascriva modifiche concorrenti.
- Accesso consentito solo agli amministratori autenticati.

Gli importi sono espressi in centesimi di euro. Le righe conservano i dati dell'acquisto e non dipendono dalle tabelle del catalogo: la cancellazione o la reimportazione di prodotti non elimina le righe degli ordini. Gli ordini Shopify importati continuano a usare le tabelle esistenti.

La creazione degli ordini e il calcolo dei relativi importi verranno implementati nella fase checkout. Non esiste ancora un endpoint pubblico di creazione. Non sono implementati annullamenti, rimborsi operativi, email o integrazioni con corrieri.

## Caricamento su Aruba

Caricare questi file conservando i percorsi relativi alla radice del progetto Laravel:

1. `app/Models/ItalianOrder.php`
2. `app/Models/ItalianOrderItem.php`
3. `app/Models/ItalianOrderEvent.php`
4. `app/Http/Controllers/ItalianOrdersController.php`
5. `database/migrations/2026_09_14_150000_create_italian_orders_tables.php`
6. `routes/web.php`
7. Tutto il contenuto di `public/build/`, compresi i nuovi asset e `manifest.json`.

La build è già stata generata. Caricare prima gli asset e per ultimo `public/build/manifest.json`. Non eliminare i vecchi asset durante il caricamento. Non servono modifiche a `.env`, nuove dipendenze Composer o un aggiornamento di `vendor/`.

Dopo aver caricato i file PHP e la migrazione, premere **Aggiorna database** nella Dashboard prima di aprire la nuova sezione. Il pulsante crea le tre nuove tabelle e svuota le cache applicative. In locale la migrazione è già applicata: non caricare il database locale su Aruba.

I file Vue e TypeScript sono sorgenti della build e non sono necessari per l'esecuzione su Aruba. Se si mantiene anche il sorgente sul server, aggiornare:

- `resources/js/components/AppSidebar.vue`
- `resources/js/pages/ItalianOrders/Index.vue`
- `resources/js/pages/ItalianOrders/Show.vue`
- `resources/js/types/italian-orders.ts`

Questo documento e `tests/Feature/ItalianOrdersTest.php` non sono necessari sul server.

## Verifiche

- 16 test passati, 136 asserzioni: ItalianOrdersTest, CustomerOrderImportTest, ImportedProductsTest, AuthenticationTest.
- Test eseguiti su SQLite in memoria, senza alterare il database locale o il file `testing`.
- Verificati accessi, filtri, paginazione, conservazione dei dati dopo modifiche/cancellazione del catalogo, controllo pagamento, tracking, transizioni, cronologia e conflitti tra operatori.
- Laravel Pint passato sui nuovi file PHP.
- `npm run build` completato dopo l'ultima modifica frontend.
- `vue-tsc --noEmit` segnala errori nei componenti preesistenti di autenticazione, Customers, Dashboard e VisitorStatistics; nessun errore nei file nuovi. Il controllo TypeScript globale quindi non è verde.
- Migrazione locale applicata; tre rotte amministrative registrate; nessun ordine locale creato.

## Passo successivo

Checkout per soli prodotti con dati cliente e indirizzo, ricalcolo server degli importi e creazione delle copie dei prodotti acquistati. Prima di completare quel passo occorre definire la tariffa/regola di spedizione; seguiranno Stripe in modalità test e le conferme di pagamento.
