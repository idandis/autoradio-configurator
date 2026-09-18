# Pagamenti live — Italia

Il codice supporta ora pagamenti e rimborsi reali. Le vecchie note delle fasi 2 e 3 descrivono la precedente implementazione esclusivamente sandbox.

## Stato della preparazione (18 settembre 2026)

- Account Stripe verificato via API: incassi, accrediti e carte abilitati.
- Chiavi live e segreto webhook presenti nel `.env` locale, mai inclusi nei documenti o nella build.
- Endpoint Stripe `we_1UGyQgLt2Q1RrYnJwzk8OTa9` predisposto a `https://www.autoradioitaliano.it/stripe/webhook`, disabilitato in attesa del rilascio. Il dominio senza `www` risponde con un redirect: non usarlo per il webhook.
- Pubblicazione tramite caricamento file su Aruba; SSH non disponibile. Le operazioni database e cache si eseguono dal nuovo pulsante della dashboard.
- SMTP locale configurato con Register.it, ma il tentativo di connessione dalla macchina di sviluppo va in timeout. Autenticazione e consegna email non ancora verificate; riprovare dal server con le credenziali della casella scelta.
- Nessun pagamento o rimborso reale eseguito durante lo sviluppo.

## Configurazione sul server

Conservare il `.env` già esistente sul server, soprattutto APP_KEY e le credenziali del database. Non sostituirlo con quello locale e non generare una nuova APP_KEY.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.autoradioitaliano.it
SESSION_SECURE_COOKIE=true
ITALIAN_CHECKOUT_ORIGIN=https://www.autoradioitaliano.it
ITALIAN_CHECKOUT_ENABLED=false
STRIPE_MODE=live
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
ITALIAN_ORDER_MAIL_ENABLED=true
MAIL_MAILER=smtp
```

Inserire i valori reali delle tre chiavi Stripe già presenti nel `.env` locale e configurare MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD e MAIL_FROM_ADDRESS per la casella operativa. Il nome visualizzato nelle conferme è Autoradio Italiano. Il servizio non considera `log`, `array` o il failover verso log come consegna reale.

Il server richiede PHP 8.4.1 o superiore (requisito effettivo delle dipendenze bloccate nel composer.lock) e le estensioni richieste dal composer.lock. Document root sulla cartella `public`; `storage` e `bootstrap/cache` scrivibili. Il checkout italiano è disponibile in produzione esclusivamente sui domini autoradioitaliano.it e www.autoradioitaliano.it; la UI lo propone nella versione italiana. Il server accetta soltanto spedizioni con paese IT e valuta EUR, mantiene spedizione gratuita e sconti esistenti. Installazione esclusa.

## Rilascio

1. Salvare backup del database e del codice corrente sul server.
2. Caricare il codice aggiornato, le migrazioni, i template e `public/build`. Conservare `.env`, database, upload e immagini già presenti. Non caricare `passkey stripe/`, database locali o cache generate in locale.
   Prima di riaprire il sito, dal File Manager Aruba o dal client FTP eliminare i soli file `.php` dentro `bootstrap/cache/` sul server (config.php, packages.php, services.php, eventuali routes-v7.php/events.php). Conservare la cartella e `.gitignore`; non eliminare `bootstrap/app.php` o `bootstrap/providers.php`. Laravel rigenera i manifest. Questo passaggio è necessario anche sovrascrivendo lo ZIP: l'upload non rimuove una vecchia config.php che potrebbe ancora citare dipendenze di sviluppo, come Laravel Pail, assenti nel vendor di produzione. La dashboard non può pulire quella cache se l'applicazione non riesce ad avviarsi.
3. Nella dashboard, sotto **Link utili → Checkout Italia**, premere **Aggiorna e verifica checkout Italia**. Il pacchetto contiene già le dipendenze PHP: non occorrono Composer o SSH sul server. Il pulsante applica esclusivamente le sei migrazioni degli ordini italiani, aggiorna le cache Laravel e mostra l'esito dei controlli nella dashboard. I comandi e i percorsi sono fissati nel codice, l'azione è riservata agli amministratori e usa lo stesso blocco dell'aggiornamento database generale. Può essere ripetuta: le migrazioni già eseguite non vengono riapplicate. Non importa traduzioni né catalogo. La verifica parte dopo il ricaricamento per leggere il `.env` aggiornato.

Se sul server è ancora presente una cache delle rotte della versione precedente e il nuovo pulsante restituisce 404, usare una volta il pulsante esistente **Aggiorna database**, che svuota anche quella cache. Dopo il ricaricamento usare il pulsante dedicato al checkout.

4. Per i ritentativi automatici delle email, configurare nel pannello Aruba un'attività pianificata ogni minuto, dalla directory del progetto (non richiede una sessione SSH):

```sh
php artisan schedule:run
```

La conferma email viene tentata subito dopo il pagamento; il cron ritenta le righe rimaste `pending`. Lo stato `sent` significa accettata dal trasporto email, non consegnata nella casella del destinatario. Come in ogni invio SMTP, un arresto dopo l'accettazione ma prima del commit può causare un ritentativo; viene usato un Message-ID stabile.

5. Verificare il webhook sul dominio www: una POST senza firma deve ricevere 400 (non redirect, pagina HTML o errore CSRF). Le richieste Stripe non devono essere bloccate da protezioni geografiche o challenge del proxy.
6. Attivare l'endpoint già predisposto nella dashboard Stripe e controllare una consegna firmata. Stripe non restituisce il segreto degli endpoint esistenti via API: conservare quello già salvato.
7. Impostare `ITALIAN_CHECKOUT_ENABLED=true` nel `.env` del server e premere nuovamente **Aggiorna e verifica checkout Italia**. Il riquadro mostra i controlli passati e gli elementi da completare. Le chiavi private non vengono mostrate. Il pulsante non modifica il `.env` e non attiva automaticamente il webhook Stripe.

La verifica controlla configurazione, tabelle, build, account Stripe e stato/eventi dell'endpoint. Non esegue addebiti e non prova la consegna HTTP o SMTP. Il comando `italian-payments:check --stripe` rimane disponibile come alternativa per gli ambienti con terminale.

8. Verificare dal sito carrello, dati italiani, riepilogo, pagina Stripe, pagamento effettivo autorizzato, stato Pagato nella dashboard e conferma email. Per un eventuale rimborso usare l'ordine nella dashboard; la richiesta è reale e mostra una conferma con l'importo.

## Arresto di nuovi acquisti

Impostare `ITALIAN_CHECKOUT_ENABLED=false` e premere **Aggiorna e verifica checkout Italia**. Non disabilitare il webhook o cambiare chiavi/modalità: le notifiche di pagamenti già avviati e i rimborsi devono continuare a essere elaborati. Gli ordini di prova non possono essere pagati/rimborsati con le chiavi live, e gli ordini reali non possono essere eliminati attraverso le azioni riservate agli ordini di prova.

## Verifiche di sviluppo

58 test automatici passati, 599 asserzioni, su SQLite in memoria, con gateway Stripe simulato: creazione ordini reali, indirizzi esteri rifiutati, separazione test/live, importi verificati sul server, proprietà della sessione, firme e notifiche duplicate, rimborsi, invio email e ritentativi. Nessuna chiamata di addebito reale nei test. Dopo l'aggiunta del pulsante, passati anche i 15 test mirati (136 asserzioni) di manutenzione checkout, aggiornamento database generale e pagamenti live: accesso amministratore, ambito delle migrazioni, blocco delle operazioni contemporanee, gestione errori e visualizzazione dell'esito dopo il ricaricamento.

Prova Chrome su database isolato completata a 1440 px e 390 px: checkout, dati cliente, pagina pagamento senza credenziali e conferma; nessun errore JavaScript né scorrimento orizzontale. Il modulo Stripe con una carta reale non è stato provato.

Build Vite completata. Il controllo TypeScript generale precedente segnalava errori in autenticazione/passkey, Customers, Dashboard e VisitorStatistics. I tipi degli errori dei form della Dashboard sono stati corretti con l'aggiunta del pulsante.

Riferimenti: [Stripe Checkout](https://docs.stripe.com/api/checkout/sessions/create), [webhook Stripe](https://docs.stripe.com/api/webhook_endpoints), [email Laravel](https://laravel.com/framework/docs/13.x/mail).

## Pacchetto pronto per Aruba

`exports/italian-payments-live-2026-09-18.zip` è un aggiornamento per l'installazione esistente, con app, configurazione, rotte, migrazioni, risorse, build e dipendenze PHP di produzione. Comprende lo stato attuale del progetto, incluse le precedenti modifiche locali del checkout. Non contiene `.env`, credenziali, database, cache locali né l'intero archivio immagini: mantenere quelli del server. Il percorso di estrazione deve essere la radice del progetto Laravel, non la sola cartella public. Se si usa questo pacchetto, vendor è già incluso e non serve eseguire composer sul server; eseguire comunque migrazioni e aggiornamento delle cache. I manifest dei pacchetti in `bootstrap/cache` sono generati per le sole dipendenze di produzione incluse.
