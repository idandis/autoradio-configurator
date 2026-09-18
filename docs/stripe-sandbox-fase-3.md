# Stripe sandbox — fase 3 locale

## Stato attuale

L'integrazione è implementata e verificata con test automatici. Le chiavi sandbox non sono ancora configurate: non è stato eseguito un pagamento effettivo contro la sandbox dell'account dell'utente. Il checkout e gli ordini rimangono esclusivamente di prova e il blocco dell'ambiente di produzione resta attivo.

Il pulsante dopo i dati cliente ora è **Vai al pagamento di prova**. Dopo la creazione o il recupero dell'ordine apre la pagina di pagamento incorporato. Se mancano le chiavi, la pagina comunica che l'ordine è salvato e il pagamento non è ancora disponibile, con possibilità di ricaricare e consultare lo stato.

Durante l'integrazione l'utente ha riscontrato un passaggio senza esito apparente: l'ordine era stato registrato, mentre la pagina pagamento non era ancora nella build. La pagina, la migrazione e la build sono ora presenti; la prova browser su database isolato verifica che il flusso prosegua e mostri lo stato corretto.

## Configurazione locale

Nel file `.env` sono predisposte queste variabili, non versionate:

```dotenv
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

Inserire la chiave pubblicabile `pk_test_…` in STRIPE_KEY e la chiave privata `sk_test_…` in STRIPE_SECRET, entrambe dalla stessa sandbox. Non usare chiavi live né inviarle in chat. Le chiavi live sono rifiutate dal codice.

Per ricevere le notifiche anche se il browser viene chiuso, avviare la Stripe CLI da un terminale:

```sh
npx @stripe/cli login
npx @stripe/cli listen --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,payment_intent.payment_failed,payment_intent.succeeded --forward-to http://127.0.0.1:8000/stripe/webhook
```

Usare l'account/sandbox delle stesse chiavi. Copiare il segreto `whsec_…` mostrato dal listener in STRIPE_WEBHOOK_SECRET, lasciando il listener acceso durante le prove. Se Laravel usa una configurazione in cache, eseguire `php artisan config:clear`. In questa sessione è disponibile anche la CLI temporanea `/tmp/autoradio-stripe-cli/node_modules/.bin/stripe`, senza installazione globale.

La migrazione `2026_09_15_010000_create_italian_order_payments.php` è già applicata al database locale e la build è aggiornata. Nessun caricamento su Aruba.

## Comportamento

- Stripe.js carica il modulo incorporato, usando l'API `createEmbeddedCheckoutPage` del pacchetto installato. I dati della carta sono gestiti da Stripe.
- La sessione Stripe addebita il totale immutabile dell'ordine, sconti inclusi e spedizione gratuita. Il dettaglio dei prodotti è visibile nella pagina locale; Stripe presenta una riga riepilogativa con il numero ordine.
- Per questa fase sono abilitate le carte in sandbox. Ogni ordine resta `is_test=true`.
- I tentativi hanno chiavi di idempotenza persistenti e parametri stabili. Un timeout non cambia la chiave; una sessione aperta viene riutilizzata; una sessione scaduta può essere sostituita senza creare un altro ordine.
- Il server verifica la firma del webhook sul corpo originale e rilegge la sessione direttamente da Stripe. Controlla modalità test, ID sessione/ordine/tentativo, importo e valuta.
- Solo una sessione completata e pagata può impostare l'ordine come Pagato. Una notifica duplicata non duplica l'evento; una notifica tardiva di errore non annulla un pagamento riuscito.
- La pagina di ritorno può verificare il pagamento anche prima dell'arrivo del webhook, utilizzando la sessione salvata sul server, senza fidarsi di parametri di ritorno inviati dal browser.
- Errori di rete nella gestione webhook restituiscono 503 per consentire il reinvio.
- Pagamento, data e cronologia sono visibili in Ordini italiani; le modifiche automatiche sono attribuite a Stripe sandbox.
- Non sono implementati rimborsi operativi, invio email o spedizioni automatiche.

## Prova quando le chiavi saranno disponibili

Aprire il configuratore con `?lang=it`, selezionare prodotti, completare i dati e procedere al pagamento. È possibile riprendere anche un ordine già creato dalla sua pagina nella stessa sessione browser.

Per simulare un pagamento riuscito usare `4242 4242 4242 4242`, una scadenza futura e tre cifre CVC. Per simulare un rifiuto usare `4000 0000 0000 0002`. Non usare carte reali. Verificare lo stato Pagato nella Dashboard dopo il successo, poi provare rifiuto, nuovo tentativo e aggiornamento della pagina.

## Verifiche eseguite

- 32 test passati, 393 asserzioni: StripeTestPaymentsTest, ItalianCheckoutTest e ItalianOrdersTest, usando SQLite in memoria e un gateway Stripe simulato.
- Verificati successo webhook senza ritorno del browser, successo dalla pagina di ritorno, firme non valide, eventi live, importi/valute/metadati discordanti, errori rete, rifiuti, tentativi ripetuti, scadenze, sessioni di altri utenti e protezione delle chiavi private.
- Prova Chrome su database isolato: configuratore → dati cliente → pagina pagamento senza chiavi → stato ordine. Nessun errore JavaScript e nessun ordine fittizio aggiunto automaticamente al database principale.
- Build Vite completata e nessun errore TypeScript nei file del checkout; il controllo TypeScript generale riporta ancora i problemi preesistenti del progetto descritti nelle fasi precedenti.
- Laravel Pint eseguito sui file PHP aggiunti/modificati per Stripe.

## Riferimenti

- [Creazione della sessione Checkout](https://docs.stripe.com/api/checkout/sessions/create)
- [Conferma degli ordini e webhook locali](https://docs.stripe.com/checkout/fulfillment)
- [Carte di prova](https://docs.stripe.com/testing)
- [Installazione della Stripe CLI](https://github.com/stripe/stripe-cli#installation)
