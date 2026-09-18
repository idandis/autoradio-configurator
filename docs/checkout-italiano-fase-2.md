# Checkout italiano — fase 2, prova locale

Aggiornamento: è stata implementata la fase Stripe; vedere [Stripe sandbox — fase 3](stripe-sandbox-fase-3.md). Il pulsante finale ora apre il pagamento di prova. Il resto di questo documento descrive la fase 2.

## Come provarlo

1. Aprire `http://127.0.0.1:8000/configurator?lang=it` e ricaricare la pagina per usare la nuova build.
2. Scegliere uno o più prodotti e premere **Vai al checkout**.
3. Compilare dati cliente e indirizzo italiano, verificare il riepilogo e premere **Crea ordine di prova**.
4. Accedere come amministratore a `http://127.0.0.1:8000/italian-orders`: l'ordine è contrassegnato **Prova**, con pagamento in attesa e spedizione da preparare.

La spedizione è gratuita, senza soglie, agli indirizzi italiani. L'installazione è esclusa sia dalla selezione inviata al checkout sia dai prodotti ammessi dal server. Il comportamento informativo dell'installazione nel configuratore non è stato modificato.

## Comportamento

- Checkout accessibile senza account cliente, con stile e impaginazione dedicati per desktop e mobile.
- Prezzi, titoli, varianti, SKU e quantità verificati sul database locale; nessuna dipendenza dagli ID Shopify per acquistare.
- Prodotti acquistabili: schermi, camere e altoparlanti, incluse selezioni aggiunte al preventivo personalizzato. Un prodotto con varianti richiede la scelta della variante.
- Prezzi nulli, zero o articoli non più presenti bloccano la conferma.
- Sconti automatici coerenti con quelli esistenti nel configuratore: Base 2% da 300 €, Pro 3% da 500 €, Vip 50 € da 900 €. Calcolo in centesimi e arrotondamento al centesimo. Gli sconti personalizzati inseriti manualmente nel preventivo devono essere rimossi prima di entrare in questo checkout: non vengono accettati importi arbitrari dal browser.
- I dati dell'acquisto sono copiati nelle righe dell'ordine e resistono alle successive importazioni del catalogo.
- Se il catalogo cambia prima della conferma, il cliente deve verificare il nuovo riepilogo; l'ordine non viene creato con importi non confermati.
- Creazione atomica e token univoco per impedire ordini duplicati sullo stesso invio.
- Checkout e conferma accessibili solo dalla sessione che ha iniziato il carrello. Si conservano al massimo 20 carrelli recenti nella sessione.
- Dati indirizzo: Italia, CAP a 5 cifre e provincia di 2 lettere. In questa fase l'indirizzo di fatturazione coincide con quello di spedizione.

## Ambiente

Il checkout è abilitato di default in `local`. È disabilitabile tramite `ITALIAN_CHECKOUT_ENABLED=false`. Il codice lo blocca in produzione anche se la variabile è impostata a true; l'ambiente `testing` lo abilita esplicitamente nei test.

Tutti gli ordini creati qui hanno `is_test=true` e pagamento `pending`. Non vengono eseguiti pagamenti, inviate email, prenotate scorte o avviate spedizioni. Il passaggio ai pagamenti Stripe in modalità test è una fase successiva.

La migrazione `2026_09_14_160000_add_checkout_fields_to_italian_orders.php` è già applicata al database locale e la build frontend è già generata. Nessun caricamento su Aruba è stato eseguito o richiesto.

## Verifiche

- 22 test passati, 293 asserzioni: ItalianCheckoutTest, ItalianOrdersTest e CustomerOrderImportTest, su SQLite in memoria.
- Verificati prezzi e spedizione non alterabili dal browser, soglie sconto, quantità, articoli non ammessi, prezzi mancanti, dati cliente, accesso tra sessioni, modifiche catalogo, copie degli articoli, invii ripetuti e blocco in produzione.
- Prova browser completa con Chrome: configuratore → scelta prodotto senza ID Shopify → checkout → compilazione → conferma → aggiornamento pagina. Nessun errore JavaScript e nessuna duplicazione dell'ordine nel database separato della prova.
- Impaginazione controllata a 1440 px e 390 px; nessuno scorrimento orizzontale nella pagina checkout mobile.
- Prova browser effettuata su database isolato e server temporaneo porta 8001; nessun ordine fittizio aggiunto automaticamente al database principale su porta 8000.
- Laravel Pint passato sui file PHP del checkout e build Vite completata.
- Il controllo TypeScript generale continua a riportare problemi nei file preesistenti di autenticazione, Customers, Dashboard e VisitorStatistics, senza diagnostica nei nuovi file del checkout.
- Una verifica aggiuntiva di LocaleTest riporta 7 errori preesistenti relativi ai testi numerati dei passaggi e alla selezione della lingua. Il middleware delle lingue e i dizionari non sono stati modificati in questa fase.
