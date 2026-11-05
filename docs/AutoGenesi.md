# AutoGenesi

Progetto annotato il 29 ottobre 2026. Fase attuale: primo prodotto importato automaticamente da browser in una bozza locale privata; revisione manuale ed esportazione Excel unica su modello.

## Obiettivo

Generare schede prodotto e file di importazione partendo da una lista di auto (marca, modello, anno). Il primo prototipo userà una sola auto.

## Lista auto — 4 novembre 2026

- L'utente ha escluso i modelli recenti degli ultimi cinque anni. Soglia adottata per questa prima analisi: generazioni disponibili entro il 2020.
- Base pubblica reperita: top 100 Autonoción di gennaio–settembre 2020, non anno completo e non vendite cumulative storiche. Fonte e limiti nel rapporto.
- Input: `docs/autogenesi/top100-europa-2020-01-09.csv`.
- Confronto automatico sui campi marca/modello/anni dei prodotti screen del database locale, senza titoli: `php scripts/autogenesi-compare-vehicles.php`.
- Esito iniziale: 72 famiglie presenti, 26 assenti, 2 denominazioni da verificare (MINI Hatch e Mitsubishi Space Star). Presenza di una famiglia non garantisce copertura di ogni generazione/anno/allestimento.
- Risultati: `docs/autogenesi/confronto-top100-2020.csv`, `docs/autogenesi/modelli-assenti-pre2021.csv`, `docs/autogenesi/modelli-da-verificare-pre2021.csv`, `docs/autogenesi/rapporto-modelli-pre2021.md`.
- La raccolta della classifica web è una fotografia documentata; lo script automatizza il confronto con il database, non la raccolta di nuove classifiche. Non modifica il database.
- Prima di cercare autoradio, dettagliare generazione, intervallo anni e impianto originale dei modelli assenti. Nessuna compatibilità o disponibilità AliExpress è stata ancora verificata.

## Flusso richiesto

1. Cercare su AliExpress prodotti compatibili con marca, modello, anno, generazione e allestimento.
2. Confrontare varianti equivalenti per caratteristiche, prezzo complessivo, spedizione verso paese/CAP europeo, vendite e affidabilità del venditore, usando i dati disponibili.
3. Proporre due immagini prodotto e una degli accessori inclusi nella variante. L'ultima immagine deve raffigurare il cruscotto e la radio originali della vettura; reperirla separatamente se necessario e verificare compatibilità e possibilità d'uso.
4. Generare titolo e descrizione secondo le [regole della scheda prodotto](autogenesi/regole-scheda-prodotto.md), senza inventare caratteristiche.
5. Produrre un unico XLSX con il foglio Products nel formato del modello fornito, compatibile con Matrixify e con l’importatore XLSX dell’app, metafield e immagini ordinate.

## Prototipo previsto

Esecuzione locale: raccolta candidati, importazione automatica del prodotto scelto e delle immagini in bozza privata, revisione manuale successiva ed esportazione Excel unica su modello. Nessuna pubblicazione automatica.

### Aggiornamento: importazione in bozza autorizzata

- L'utente ha autorizzato l'importazione senza approvazione preventiva di prodotto e immagini, anche pubblicitarie o da rimuovere successivamente. Tutti i prodotti AutoGenesi devono restare invisibili ai clienti fino alla revisione manuale dell'utente.
- Aggiunta la sezione amministrativa `/autogenesi` (voce “AutoGenesi · Bozze”), con colonna Link prodotto AliExpress, immagini private rimovibili, descrizione modificabile, costi osservati e prezzi di vendita separati, esportazione Excel unica su modello.
- Bozze nella tabella separata `autogenesi_products`: non entrano in `configurator_products`, nel configuratore o nel bot. Non è ancora implementata una pubblicazione/approvazione definitiva nel catalogo.
- Comando ripetibile: `php artisan autogenesi:import 'https://es.aliexpress.com/item/ID.html' --brand=MARCA --model=MODELLO --from=2017 --to=2020`. Richiede ambiente locale, Node con Playwright (`npm install`) e Chrome sul Mac. Percorso alternativo Chrome tramite `AUTOGENESI_CHROME`.
- Il comando usa un browser separato senza profilo personale e senza API AliExpress, visita la scheda, seleziona le combinazioni visibili (massimo 12), legge i prezzi EUR e scarica almeno 3 immagini distinte della galleria. In caso di CAPTCHA, pagina bloccata o opzioni non leggibili, si interrompe: nessun aggiramento previsto.
- Ricerca testuale con anno singolo o intervallo, per esempio `radio Hyundai KONA 2017` oppure `radio Ford Escort 2019.-2020`. Normalizza prefissi radio ripetuti e anni, senza aggiungere Android o CarPlay. Prima UE, poi Tutti se manca un candidato utile.
- «Cerca links» conserva la ricerca della prima pagina: raccoglie link pertinenti senza aprire le schede. «Usa per importazione» compila manualmente il modulo.
- «Cerca miglior prezzo» aggiunge `sortType=price_asc`, conserva l'ordine dei risultati e apre le schede dei candidati. Scarta universali, accessori, varianti solo cornice, SKU senza etichetta leggibile, schede prive di prezzo EUR positivo, almeno tre immagini di galleria o specifiche hardware. Legge descrizione e specifiche per cercare copertura degli anni e presenza di una radio. Le etichette delle varianti possono essere nel testo alternativo delle immagini. La prima scheda utile viene selezionata e precompila link e veicolo; non crea una bozza e non invoca l'AI finché l'utente non preme «Importa come bozza».
- Ricerca miglior prezzo limitata a 5 pagine e 12 schede per origine, con limite complessivo di tempo. Si ferma su una verifica umana richiesta dal sito. È la scelta del primo candidato specifico nell'ordine AliExpress, non un confronto globale dei prezzi di tutte le varianti, delle spedizioni o dei venditori. Prezzi e disponibilità vengono riletti all'importazione. Il filtro UE di ricerca non certifica l'origine della singola variante.
- «Importa lista» accetta testo con una vettura per riga oppure CSV/TXT (CSV con colonne `Marca;Modello;Dal;Al`, con o senza intestazione). Supporta anche anno singolo. Valida l'intera lista prima dell'avvio, massimo 100 vetture uniche; elimina righe duplicate senza cambiare l'ordine. Ogni riga esegue ricerca miglior prezzo e importa automaticamente il prodotto trovato in bozza, con descrizione, foto nelle proporzioni originali, prezzi ed esportazioni già previste. Lo stesso ID prodotto AliExpress riutilizza la bozza esistente.
- Liste e avanzamento persistono nelle tabelle `autogenesi_batches` e `autogenesi_batch_items`. Viene elaborata una riga alla volta, con blocco condiviso del browser fra worker. Un errore o prodotto non trovato viene registrato e si passa alla riga successiva; un CAPTCHA interrompe la lista. «Riprova righe non completate» riparte senza rifare quelle concluse. La pagina aggiorna gli stati ogni 5 secondi durante l'elaborazione; si può chiudere il browser lasciando acceso il Mac e il processo worker.
- Connessione e coda dedicate `autogenesi`, database persistente, `retry_after=1500`, timeout job 1320 secondi. Worker: `php artisan queue:work autogenesi --queue=autogenesi --timeout=1320 --sleep=3`. Incluso in `composer dev`; avviato anche per la sessione locale corrente. Log della sessione: `storage/logs/autogenesi-worker.log`. Nessuna pubblicazione automatica su Shopify o nel catalogo pubblico.
- Verifica umana AliExpress: ricerca e acquisizione usano lo stesso browser Chrome visibile e persistente, con profilo dedicato in `storage/app/private/autogenesi/browser/profile`, separato dal Chrome personale. L'automazione si scollega senza chiudere Chrome o la scheda quando compare un CAPTCHA; non lo risolve automaticamente. «Apri browser per verifica AliExpress» porta in primo piano la pagina interrotta; l'utente completa personalmente la verifica, lascia aperta la finestra e preme «Verifica completata: riprendi lista». Cookie e sessione vengono riutilizzati dalle righe successive. La connessione di controllo è solo locale, su porta casuale legata a quel profilo; file e cookie restano privati e ignorati da Git.
- «Cancella prodotto» rimuove la bozza, le immagini e le esportazioni e permette una nuova importazione dello stesso link. La rimozione delle foto usa l'hash della foto selezionata; gli URL includono lo stesso identificativo per evitare immagini visualizzate sotto indici ormai cambiati.
- Lo stesso prodotto AliExpress non viene duplicato e una reimportazione conserva le modifiche manuali. Il costo fornitore resta separato; il prezzo di vendita viene calcolato per variante secondo le [regole prezzo](autogenesi/regole-prezzo.md), con ricarico a fasce, stima importazione da 150 € e arrotondamento finale per eccesso all'euro. Il prezzo resta modificabile manualmente; le modifiche sono anch'esse arrotondate per eccesso all'euro.
- Dopo l’importazione viene creato un unico Excel `-autogenesi.xlsx` sotto `storage/app/private/autogenesi/exports/{id}/`. Il foglio Products mantiene le 21 colonne di `Export_2026-11-05_063106.xlsx`, più Status e custom.aliexpress_url; il foglio Analisi AutoGenesi documenta provenienza, campi incompleti e verifiche. I vecchi file CSV/Excel vengono rimossi e non sono più offerti.
- L’export mantiene `Status=Draft` per evitare la pubblicazione su Shopify. L’importatore dell’app rifiuta le bozze nel catalogo pubblico. Si usa il formato XLSX Matrixify, non il CSV nativo di Shopify.
- Link AliExpress nell’export come `Metafield: custom.aliexpress_url [url]`. Non copiare ID del prodotto esempio: ID e Variant ID vuoti per nuove creazioni. I quattro prezzi mercato restano vuoti come nel modello, su richiesta dell’utente; i due campi altoparlanti non applicabili all’autoradio restano vuoti.
- La descrizione originale viene letta dal componente della pagina, compreso Shadow DOM, insieme alla tabella tecnica espansa e alle immagini tecniche disponibili. `AutogenesiDescription` genera la scheda spagnola con il modello OpenAI configurato, secondo le regole salvate. Note su conflitti, dati mancanti e immagini inaccessibili restano nei metadati amministrativi, fuori dal testo commerciale. La verifica completa di compatibilità, cablaggi, dotazione, origine e consegna resta aperta.
- «Rileggi e ricrea descrizione» riacquisisce le fonti e sostituisce solo titolo e testo, conservando selezione foto e prezzi manuali. Se lettura o generazione falliscono, conserva la scheda precedente. Resta disponibile il testo originale con la tabella delle specifiche nella bozza.
- Ogni foto dispone di «Modifica con AI»: al clic invia l'originale all'API OpenAI Images Edits con il modello immagini già configurato. Prompt fisso: prodotto su piattaforma di pietra, fotografia di studio/editoriale, dune canarie, sole pieno e cielo azzurro, formato quadrato 1024×1024, nessuna scritta nello sfondo. Richiede di mantenere forma, materiali, comandi e interfaccia del prodotto. Non viene eseguita automaticamente durante l'importazione.
- La foto AI sostituisce quella selezionata mantenendo l'originale privato per «Ripristina originale». Una seconda modifica riparte dall'originale. Disponibili download privato e rimozione completa della foto e della copia originale. La modifica è locale e riservata agli amministratori; richieste obsolete non possono sostituire un'altra foto.
- Foto AI/caricate/ritagliate senza URL pubblico: Image Src resta vuoto ed evidenziato; il foglio Analisi elenca ogni foto da pubblicare. Non usare localhost né i vecchi URL delle foto originali. Il riferimento del cruscotto va compilato dopo il caricamento su Shopify Files. Nessuna pubblicazione automatica.
- Regola immagini aggiornata: nessuna quadratizzazione automatica durante importazione AliExpress, acquisizione Chrome o caricamento dal computer. Foto e anteprime conservano le proporzioni originali, senza ritaglio centrale, padding o sfondi aggiunti. Il quadrato si applica soltanto su richiesta con «Taglia» o con gli interventi AI. Le foto già modificate manualmente restano conservate. Le foto fornitore non modificate mantengono il loro URL nell’Excel.

### Prima bozza effettiva

- Bozza locale #1: HYUNDAI Kona 2017–2020; AliExpress ID `1005009132362965`; link `https://es.aliexpress.com/item/1005009132362965.html`.
- La prima bozza è stata eliminata e ricreata come #2 con lettura della descrizione originale, 47 voci tecniche, 9 immagini della descrizione disponibili e 6 foto di galleria. Altre 14 immagini della descrizione restituiscono HTTP 403 e restano segnalate come non lette. Scheda spagnola rigenerata e rivista; titolo `Autoradio Android 9″ 1DIN para Hyundai KONA 2017-2020`. Contraddizioni Android 12/13/14, RAM/ROM, DVD e altre etichette ambigue nelle note riservate. CSV Shopify, CSV catalogo ed Excel rigenerati per la bozza #2.
- Venditore letto: ZWNAV Professional Car Official Store. Tre combinazioni: “Android 14 64G 4G / Ocho Núcleos”, “Android 14 128 8G / Ocho Núcleos”, “Android 14 256 8G / Ocho Núcleos”. SKU generati internamente: non sono SKU reali del fornitore.
- Costi osservati nel browser: 343,69 / 382,39 / 448,39 EUR, non prezzi di vendita e non preventivi di consegna.
- Sei immagini scaricate e conservate privatamente. Revisione visiva iniziale: quattro foto prodotto, una pubblicitaria e una foto cruscotto da verificare, messa in ultima posizione. Non è stata trovata una foto degli accessori nella galleria acquisita.
- La pubblicità cita Android 12 mentre titolo e varianti dichiarano Android 14: anomalia segnalata, immagine mantenuta come richiesto. Originalità del cruscotto e corrispondenza all'allestimento non confermate.
- Verifiche: test mirati AutoGenesi per import, privacy amministratore, idempotenza, modifiche, file CSV/Excel e blocco delle bozze nell'importatore pubblico; build frontend. La suite preesistente `ConfiguratorImportTest` ha 12 fallimenti e 4 errori anche caricando l'importatore originale da HEAD: non dichiarare l'intera suite verde.

## Ricerca AliExpress: filtro di pertinenza

- Requisito dell'utente: per questo flusso importare autoradio specifiche per il veicolo; escludere universali senza kit dedicato e schermi portatili/da appoggio.
- Le parole chiave producono candidati, non una certificazione di compatibilità. Non presumere che filtri negativi o virgolette siano supportati allo stesso modo dal sito e dalle API; verificarlo dopo l'accesso API.
- Prima del confronto commerciale, controllare marca, modello, generazione, anni e impianto originale nelle informazioni del prodotto e della variante selezionata. La sola presenza di “2008” può essere un anno di un'altra auto; neppure una prefissazione “Peugeot 2008” nel risultato prova la compatibilità se il resto dell'inserzione riguarda Peugeot 207.
- Un'autoradio con elettronica comune è candidata soltanto se la variante comprende effettivamente la soluzione dedicata: mascherina/fissaggio e cablaggio/adattatori per l'auto, CANBUS quando richiesto. “1DIN/2DIN” da solo non determina se un prodotto sia universale o specifico.
- Separare autoradio sostitutive, moduli retrofit CarPlay per lo schermo originale e sole cornici/adattatori. Per la ricerca autoradio sostitutiva, moduli e accessori non vanno confrontati come prodotti equivalenti.
- Se specificità o dotazione non sono verificabili: stato “da verificare”, esclusione dalla selezione approvata e dall'export finché non confermato. Controllare foto del cruscotto originale, mascherina e connettori.
- Soltanto dopo questi controlli confrontare caratteristiche della medesima variante, prezzo effettivo, spedizione e vendite. Niente scelta in base al prezzo minimo della scheda di ricerca.
- Deduplicare per ID prodotto AliExpress, appena disponibile. I testi incollati possono ripetere il titolo molte volte e non permettono una deduplicazione certa per prodotto/venditore.

### Prima pagina manuale Peugeot 2008

- Ricerca di partenza aggiornata: `radio Peugeot 2008 2013 2019`. Le precedenti ricerche con Android/CarPlay restano soltanto esempi storici nei link forniti dall'utente.
- Prima pagina allegata dall'utente il 4 novembre 2026: numerosi risultati universali, portatili e riferiti ad altre vetture. I risultati non sono stati controllati direttamente nelle schede prodotto; non ci sono URL/ID utilizzabili nell'allegato.
- Candidati testuali da esaminare: AWESAFE per 208/2008; Cestovet per 208/2008 Mk1; DUDUAUTO per 2008/208; Junsun per 2008/208 2013–2019. Non costituiscono compatibilità confermata, né scelta di variante o fornitore.
- Scartare i risultati riferiti a Renault Megane/Fluence, Peugeot 207/207CC, Hyundai e VW quando “2008” è un anno o un prefisso ingannevole. Separare i moduli SMEG/MRN e le cornici dai kit radio completi.

### Link di ricerca per altri modelli

- La precedente fase “solo istruzioni” è stata superata dall'autorizzazione al test browser e alla prima importazione in bozza. Restano valide le regole seguenti per la futura automazione della ricerca.
- Link di ricerca fornito dall'utente per Peugeot 2008: `https://es.aliexpress.com/w/wholesale-Peugeot-2008-2013%2525252d2019-Android-CarPlay.html?g=y&SearchText=Peugeot+2008+2013-2019+Android+CarPlay&shpf_co=EU`.
- Regola aggiornata su osservazione dell'utente: query iniziale `radio {marca} {modello} {anno_inizio} {anno_fine}`, con anni separati da spazi, senza trattino e senza termini Android/CarPlay. Esempio: `radio Hyundai Kona 2017 2020`. Gli anni devono riferirsi alla generazione da cercare, non essere copiati automaticamente dall'esempio Peugeot.
- Verificare Android, CarPlay e le altre caratteristiche nelle schede e nella variante dopo il filtro di pertinenza, senza usarli come parole chiave della ricerca iniziale.
- Costruire i parametri con un encoder URL standard, una sola volta. Il percorso del link ricevuto contiene un trattino codificato ripetutamente (`%2525252d`): non riutilizzare quella codifica come modello.
- Un possibile formato da verificare nel browser è `https://es.aliexpress.com/w/wholesale-{query-con-trattini}.html?SearchText={query-codificata}&shpf_co=EU`. Non considerare stabile il formato del sito né equivalente a un endpoint API ufficiale.
- Lo screenshot successivo dell'utente mostra il filtro `Envío desde`: `Unión Europea` produce il link con `shpf_co=EU`, mentre `Todos` produce il medesimo link senza quel parametro. Quindi il parametro rappresenta il filtro di origine della spedizione nell'interfaccia osservata, non la destinazione di consegna.
- Link con `Todos` fornito dall'utente: `https://es.aliexpress.com/w/wholesale-Peugeot-2008-2013%2525252d2019-Android-CarPlay.html?g=y&SearchText=Peugeot+2008+2013-2019+Android+CarPlay`.
- Preferenza confermata dall'utente: iniziare sempre dall'origine Unione Europea (`shpf_co=EU`). Applicare il filtro di pertinenza ai risultati: la presenza di risultati universali o non compatibili non conta come disponibilità di candidati adatti.
- Se con origine UE non emergono autoradio specifiche adatte o restano soltanto universali, ripetere la stessa query con `Todos`, rimuovendo `shpf_co`, e applicare nuovamente gli stessi criteri di compatibilità. Allargare l'origine non autorizza ad accettare prodotti universali senza kit dedicato.
- Conservare per ogni candidato origine dichiarata e contesto della ricerca (UE oppure Todos); preferire l'origine UE a parità di requisiti. I valori URL per i singoli paesi non sono ancora verificati.
- Il filtro di origine non prova disponibilità del magazzino per la variante selezionata, consegna alla destinazione del cliente, tempi o costi. Verificare separatamente paese/CAP di destinazione e costo complessivo della variante.
- Generare un link significa soltanto predisporre una ricerca: risultati, filtri, pagine, compatibilità, variante e spedizione restano da verificare. Applicare sempre il filtro di pertinenza sopra descritto.

## Integrazione AliExpress

- Portale attuale: https://open.aliexpress.com/
- La procedura sul precedente portale console.aliexpress.com rifiutava il salvataggio con “海外版禁止写入”.
- Registrazione sul nuovo portale inviata come Dropshipping (individual), paese Spagna.
- Account visualizzato come connesso durante la registrazione: `escuelasoft72@gmail.com`. Confermato il 4 novembre 2026 rileggendo gli screenshot originali del 29 ottobre, dove compare nel menu account in alto a destra, sia nella scelta del ruolo sia nello stato Under Review. Non è soltanto l'email di contatto; non risulta annotato un nome utente distinto o il metodo di autenticazione.
- Conversazione originale: “Avvia localhost”, 29 ottobre 2026, ID `01a186f5-3282-7b22-bfa0-7486a0bc8ed0`; discussione AliExpress tra le 10:56 e le 11:44 (Europe/Rome). Cronologia locale: `/Users/pollong/.codex/sessions/2026/10/29/rollout-2026-10-29T10-19-38-01a186f5-3282-7b22-bfa0-7486a0bc8ed0.jsonl`.
- Profilo approvato: il 2 novembre 2026 l'utente ha condiviso l'email “Profile Approval Granted” di AliExpress Open Platform. L'email conferma l'approvazione del profilo e invita a creare l'applicazione su https://openservice.aliexpress.com/.
- Prossimo passo: creare l'applicazione e verificare autorizzazione account, permessi, quote e risposte reali per ricerca, dettagli, SKU, immagini e logistica. L'approvazione del profilo non conferma ancora i permessi API dell'app. Nessuna integrazione API ancora implementata o collaudata.
- Possibile problema nel modulo inviato: campo LinkedIn con limite 30 caratteri, URL probabilmente troncato. Correggere se richiesto dal portale.

## Base esistente e questioni aperte

- `app/Services/ConfiguratorCsvImporter.php` legge già colonne Shopify e alcuni metafield, inclusa la foto del cruscotto originale.
- Definire elenco completo dei metafield e verificare tipi supportati dal CSV Shopify; adattare l'importatore dell'app dove necessario.
- Definire caratteristiche minime delle autoradio, criteri di punteggio, paese/CAP di consegna, regole descrittive, lingue e prezzi di vendita.
- Verificare le condizioni AliExpress per uso commerciale, immagini, conservazione e trasferimenti a Shopify o servizi AI.
- Localizzazione del trattamento e fornitori AI non ancora definiti o verificati. Non considerare definitive le risposte suggerite durante la compilazione del questionario.
- Prevedere tracciabilità tramite ID prodotto/SKU AliExpress e gestione di aggiornamento/cancellazione nei sistemi collegati e nei file esportati.

## Ripresa

Profilo AliExpress approvato, ma accesso al portale bloccato. Risolvere il collegamento dell'account prima di creare l'applicazione su https://openservice.aliexpress.com/, poi provare il recupero dati di un prodotto reale prima di sviluppare il flusso completo.

## Assistenza accesso — 4 novembre 2026

- Aggiornamento riportato dall'utente incollando la conversazione di assistenza; screenshot originali non allegati a questo aggiornamento.
- La notifica automatica “Profile Approval Granted” del 2 novembre 2026 proviene da `message@info.aliexpress.com`. Non è confermato che l'indirizzo gestisca le risposte.
- L'utente non riesce ad accedere a `openservice.aliexpress.com`; il recupero password segnala che non esiste un account associato all'email che ha ricevuto l'approvazione.
- Obiettivo della richiesta: verificare quale account sia collegato al profilo sviluppatore approvato e ottenere la procedura corretta di accesso, preservando l'approvazione.
- Secondo il resoconto incollato, l'operatore Rodrigo ha inoltrato la pratica **2053809081822420** al team di livello superiore, indicando fino a 48 ore per la risposta.
- Termine di riscontro riportato nella conversazione: **6 novembre 2026, ore 12:40**; è un'attesa comunicata, non una risposta garantita. Fuso dell'orario non verificato sul messaggio originale.
- Attendere il riscontro prima di creare un secondo profilo; se non arriva, ricontattare la chat ufficiale citando il numero della pratica.
- Indirizzi menzionati nell'altra conversazione (`aeop-support@aliexpress.com`, `AE_customerservice@aliexpress.com`): attualità e pertinenza non verificate qui. Non considerarli canali confermati.
- Per eventuali testi all'assistenza, usare l'inglese salvo diversa richiesta dell'utente; durante la chat con Rodrigo è stata richiesta una versione spagnola.

### Varianti: nomi, esclusioni e ordine
- Escludere le varianti con `360` nel nome/opzioni, già durante la raccolta; il semplice supporto 360 nella tabella generale non elimina altre versioni.
- Ricavare 4Core/8Core dalla tabella comparativa nelle immagini originali della descrizione, associando il codice esatto. Non dedurre i core dalla RAM. Se non verificabili, conservare il nome originale.
- Formato: `8Core 8GB 256GB 11.5''`, `4Core 2GB 64GB`. Dimensione solo quando specifica della variante; conservare il codice come suffisso quando serve a distinguere configurazioni omonime.
- Nomi modificabili manualmente; modifiche conservate e usate anche negli export.
- Varianti ordinate per prezzo di vendita crescente (in assenza, costo fornitore); SKU e prezzo restano associati alla variante.

### Foto accessori e prima/dopo
- Leggere visivamente tutte le immagini della descrizione originale: aggiungere la foto del contenuto confezione (Accessoires/Accessories/Accesorios), distinta dagli optional pubblicizzati separatamente.
- Aggiungere il confronto cruscotto originale/autoradio installata solo quando coerente con vettura e generazione; questa immagine va per ultima. Escludere esempi contrassegnati come incompatibili.
- Classificazione durante la lettura AI, tramite nomi file presenti nella cattura; nessun URL inventato. Conservare fonte e originale, evitando duplicati.
- Foto accessori e prima/dopo: conservare proporzioni e contenuti originali, senza sfondo sfocato aggiunto; eventuale taglio solo manuale.
- Preferire foto originali della scheda. Se il prima/dopo manca, cercare riferimenti reali della stessa vettura su Internet prima di ricrearlo; non inventare geometria del cruscotto né attestare compatibilità da una ricostruzione.

### Avvio singolo delle richieste mancanti
- Ogni riga in coda, fallita, non trovata o bloccata può essere avviata con «Avvia solo questa» quando la lista non è in elaborazione.
- Il job riceve l’ID della riga: esegue soltanto quella ricerca/importazione e poi attende un nuovo avvio manuale. Nessun proseguimento automatico alle altre righe, anche in caso di errore o timeout.
- Le righe completate restano conservate; un eventuale CAPTCHA interrompe anche l’avvio singolo. Rimane disponibile la ripresa dell’intera lista.

### Arresto completo
- «Ferma tutto e chiudi browser» interrompe le ricerche AutoGenesi, cancella soltanto la coda dedicata e chiude soltanto il profilo Chrome AutoGenesi. Non modifica le bozze completate o gli altri job dell’app.
- Un segnale persistente con ID di esecuzione ferma i processi Node attivi e impedisce aperture successive del browser. Anche una ripartenza esplicita non riabilita i processi della precedente esecuzione.
- Le richieste AI della descrizione controllano l’arresto durante il trasferimento e prima del salvataggio. I job fermati non continuano alle altre righe.
- Solo una nuova azione esplicita (ricerca, importazione, ripresa lista/riga, apertura verifica) riabilita il lavoro.
- Il server PHP locale usa quattro processi per ricevere l’arresto anche durante una richiesta browser lunga.

### Link diretto nel campo ricerca
Il campo «Testo di ricerca o link prodotto» accetta anche URL HTTPS es.aliexpress.com/item/ID.html o www.aliexpress.com/item/ID.html. Entrambi i pulsanti riconoscono il link, rimuovono i parametri di tracciamento e preparano il modulo di importazione senza avviare la ricerca o il browser. Marca, modello e anni vanno completati dall’utente prima di «Importa come bozza»; non usare valori predefiniti di un’altra vettura. URL estranei, credenziali e porte non standard vengono rifiutati.

### Blocco per traffico insolito
Riconoscere anche il popup «We have detected unusual traffic from your network, please try again later», incluso negli iframe visibili. Fermare la lista senza ritentare altri candidati e indicare «traffico insolito», senza chiamarlo CAPTCHA. Un’altra sessione funzionante non dimostra che il blocco sia esteso alla rete: il profilo Chrome AutoGenesi è separato e persistente. Non sostituire automaticamente profilo, cookie o connessione per riprovare.

### Limiti locali
Le azioni AutoGenesi usano il limiter dedicato `autogenesi`, senza limite per amministratori nell’ambiente locale. Negli altri ambienti i contatori sono separati per azione e utente, senza condividere i limiti numerici di altre sezioni dell’app. Questo riguarda soltanto i 429 di localhost, non eventuali limitazioni di AliExpress.

## Secondo metodo: importazione dalla scheda aperta nel Chrome dell’utente
Prototipo indipendente dal browser CDP: estensione Manifest V3 in `scripts/autogenesi/chrome-extension`, scaricabile dalla sezione «Importa dal tuo Chrome» di AutoGenesi.

1. Installazione manuale da `chrome://extensions` con Modalità sviluppatore e «Carica estensione non pacchettizzata».
2. L’utente apre normalmente un prodotto AliExpress in euro ed espande descrizione e specifiche.
3. Un clic su «Acquisisci questo prodotto» legge la pagina corrente, seleziona al massimo 12 combinazioni di varianti e legge i prezzi; esclude 360 e opzioni di soli accessori. Ripristina la selezione iniziale se la pagina resta accessibile.
4. Scarica immagini di galleria e descrizione dai soli CDN AliExpress, in sequenza, senza cookie. Converte AVIF e altri formati decodificabili da Chrome in JPEG. Limite complessivo immagini 35 MB; nessun tentativo per superare blocchi 403/429.
5. Scarica `autogenesi-ID.json`. L’utente lo carica nel nuovo modulo locale, inserisce marca/modello/anni e importa come bozza con gli stessi servizi di descrizione, immagini accessori/prima-dopo, prezzi, varianti ed export.

L’estensione non legge cookie/password, non usa il debugger, non avvia browser, non naviga a prodotti diversi e non invia dati a localhost da sola. Nessun permesso permanente sulle pagine AliExpress: usa `activeTab` al clic dell’utente. Chiudere la scheda interrompe l’acquisizione; «Ferma tutto» sul sito gestisce soltanto i processi locali, non l’estensione indipendente.

Il caricamento JSON è autenticato, riservato agli amministratori in locale, con verifica formato, immagini, origini e nomi file; non scarica URL ricevuti dal file. Il materiale viene usato anche dalla generazione AI già configurata dopo l’importazione volontaria. Installazione e prova reale su AliExpress richiedono l’interazione dell’utente nel proprio Chrome.

### Ritaglio e pulizia package list
- Rimosso «Scarica foto» dalla scheda; restano modifica AI, rimozione e ripristino originale.
- «Taglia» apre un selettore quadrato 1:1 spostabile, ridimensionabile con angolo o cursore, con taglio definitivo solo dopo «Applica taglio». Coordinate nei pixel naturali della foto corrente; server valida limiti e identità SHA della foto. Risultato sempre quadrato e originale conservato.
- «Rimuovi sfondo» usa la foto corrente (anche ritagliata), tramite il servizio immagini AI già configurato. Ricrea la package list su bianco puro, conservando tutti i componenti, quantità, geometrie, etichette e condizioni di versione; le scritte restano nere sotto il rispettivo accessorio. Elimina sfondi decorativi, riquadri, frecce e intestazioni pubblicitarie. Per questa azione il bianco è richiesto esplicitamente.
- Modifiche limitate alla foto selezionata, originali ripristinabili e bozza privata. Le immagini modificate prive di URL pubblico non vengono esportate come URL originali nell’Excel.

### Foto dal computer
La scheda bozza include «Aggiungi foto dal computer», con selezione multipla e tipo foto (prodotto, accessori/package list, prima/dopo). Supporta JPG/JPEG, PNG, WebP, AVIF, GIF e BMP tramite GD; HEIC/HEIF e TIFF tramite ImageIO/sips sul Mac. GIF/TIFF sono importati come immagine statica.

Limiti: 10 foto per invio, 8 MB per file, 60 MB complessivi, dimensioni minime 200 px per lato e massimo 25 megapixel. Verifica il contenuto effettivo, esclude SVG e file non immagine. Corregge orientamento JPEG e normalizza in PNG/WebP; nessuna deformazione, ritaglio automatico o sfondo aggiunto: conserva le proporzioni originali. L’originale normalizzato resta disponibile per gli interventi AI. Le foto prima/dopo restano per ultime e i duplicati vengono ignorati. L’invio è atomico: un file non valido annulla quel caricamento senza alterare le foto precedenti.

Le foto caricate restano private, utilizzabili con Taglia/Rimuovi sfondo/Modifica AI. Non possiedono URL fornitore e sono escluse dagli URL immagini dell’Excel finché prive di pubblicazione dedicata.

### Eliminazione varianti
Ogni riga presenta «Elimina variante». La rimozione usa lo SKU interno e una transazione, conserva le altre varianti con i loro SKU e prezzi, aggiorna l’Excel e mantiene la bozza privata. Le modifiche non ancora salvate a titolo, descrizione, nomi e prezzi delle altre righe restano nel modulo. Deve rimanere almeno una variante; le richieste con SKU inesistente non eliminano un’altra riga. Funzione riservata agli amministratori nell’ambiente locale.

### Pulisci e migliora
Ogni foto dispone di «Pulisci e migliora»: usa l’immagine corrente, incluso l’eventuale ritaglio, tramite il servizio AI configurato. Rimuove scritte pubblicitarie sovrapposte, frecce, cornici e decorazioni e migliora nitidezza e compressione. Mantiene cruscotto, autoradio, geometrie, materiali, colori, comandi e interfaccia originale, senza inventare equipaggiamento o cambiare ambientazione. Conserva le etichette fisiche autentiche e rimuove soltanto le scritte promozionali aggiunte. Opera solo sulla foto selezionata e mantiene l’originale ripristinabile; non viene eseguito durante l’importazione.

### Analisi modello Excel Peugeot 2008
Esempio verificato sulla bozza #14, source ID 1005008593938688. Analisi completa: `docs/autogenesi/analisi-export-peugeot-2008.md`. Prime 21 intestazioni confrontate automaticamente con l’allegato: identiche e nello stesso ordine. Le 4 varianti correnti e i prezzi manuali sono preservati. Mancano gli URL pubblici delle 8 foto e il riferimento Shopify Files del cruscotto; DIN 1DIN nel titolo/specifiche ma conflitto 2DIN segnalato dalle fonti. Non inventare valori solo per riempire celle.

### Importazione approvata nel configuratore e foto locali
«Approva e importa nel configuratore» salva prima titolo, descrizione, nomi e prezzi correnti e poi crea/aggiorna il record in `configurator_products`, le varianti e tutte le foto della singola bozza. Il clic è l’approvazione manuale: nessuna bozza viene pubblicata nel catalogo durante l’acquisizione o l’export.

Le foto correnti vengono copiate dal disco privato al disco pubblico in `autogenesi/catalog/{source_id}/{sha256}.{ext}`. Il catalogo usa URL relativi `/storage/...`, funzionanti in locale e trasferibili con l’app; la foto cruscotto è associata anche a `original_dashboard_images`. Non serve Shopify o una sua API per questo passaggio. `public/storage` deve puntare a `../storage/app/public`: corretto il vecchio collegamento a una cartella Desktop inesistente e verificato un file temporaneo via HTTP 200.

L’import è transazionale, richiede almeno una variante con prezzo valido e una foto leggibile; in caso di errore annulla i record e rimuove le nuove copie. Aggiornando la stessa bozza riusa prodotto e SKU, mantiene gli ID locali delle varianti rimaste e rimuove quelle eliminate dalla bozza. Non sovrascrive un handle appartenente a un altro prodotto. La bozza di lavoro resta modificabile e mostra «Importata nel configuratore»; il pulsante diventa «Aggiorna nel configuratore». Le modifiche successive richiedono un nuovo clic per essere riportate nel catalogo.

Questo importa soltanto nell’installazione attuale. Per trasferire il configuratore su Aruba occorrono database e foto del disco pubblico; il solo Excel non contiene i file immagine. La creazione del prodotto in Shopify è separata: non inventare Shopify Variant ID per gli ID locali.

### Export di tutte le bozze e righe del modello
«Esporta tutte le bozze» genera un unico XLSX, stesso schema a 23 colonne e stessi fogli dell’export singolo. Include soltanto record `status=draft`, raggruppati per prodotto; mantiene variant SKU, prezzi, foto e analisi senza creare prodotti o chiamare Shopify. Il foglio Analisi identifica anche il prodotto e corregge i riferimenti di riga dopo l’accodamento.

Body HTML e tutti i metafield sono compilati soltanto sulla prima riga di ogni prodotto, come nell’allegato originale. Titolo, handle, tipo, stato e dati di variante restano sulle rispettive righe. Le foto locali diventano URL validi per Shopify soltanto quando il file è disponibile su un host pubblico; la disponibilità nel configuratore locale è segnalata separatamente nell’analisi.

### Immagini Aruba tramite FileZilla
L’export prepara automaticamente le copie correnti delle foto in `storage/app/public/autogenesi/catalog/{source_id}/{sha256}.{ext}`. Gli URL Excel usano `https://www.autoradioitaliano.it/storage/autogenesi/catalog/...` (base configurabile). Non esistono più pulsante, route o generazione ZIP delle foto.
Copiare con FileZilla anche `storage/app/public/autogenesi/catalog/` nella stessa cartella del progetto su Aruba. Il sito deve esporla tramite `public/storage` → `storage/app/public`; con document root separata o senza collegamento, copiare i file nella directory web `storage/autogenesi/catalog/`. Non basta copiare il solo codice: le cartelle storage spesso sono escluse dai trasferimenti. Salvare le bozze, scaricare l’Excel, trasferire le foto, verificare gli URL senza login e infine importare in Shopify. I prodotti rimangono bozze, le copie delle foto sono destinate alla pubblicazione.

### Generazione cruscotto originale
Nelle immagini della bozza, «Genera foto cruscotto originale» usa marca, modello e anni registrati. Esegue ricerca web OpenAI e richiede due fonti indipendenti; se il cruscotto non è identificabile o il range comprende cruscotti incompatibili, interrompe la generazione. Aggiunge in fondo una ricostruzione AI quadrata 1024×1024, etichettata da verificare, con link delle fonti conservati. Non sostituisce le foto presenti né approva il prodotto. Usa i modelli OpenAI già configurati.

### Estensione multisito 0.2.0
AliExpress conserva il parser dedicato. Gli altri negozi HTTPS usano Product/ProductGroup JSON-LD, immagini della galleria, descrizione e specifiche della pagina. Supportati prezzi esatti in EUR, 1–12 varianti leggibili, almeno tre immagini; niente prezzi aggregati inventati. «Abilita altri siti» richiede il permesso opzionale HTTPS per scaricare immagini anche dai CDN. Nessun cookie o login viene esportato; il backend legge esclusivamente le immagini incorporate nel JSON, senza richieste al fornitore. Origine originale conservata, SKU WEB e ID deterministico separati dai prodotti AliExpress. Le schede prive di dati strutturati richiedono un adattatore specifico. Dopo l’aggiornamento ricaricare l’estensione in Chrome.

### Ordine immagini e foto rappresentativa del cruscotto
I pulsanti Prima / Dopo salvano subito l’ordine della galleria; la prima foto è la principale e l’export Excel e il configuratore rispettano lo stesso ordine. Il flag unico «Cruscotto originale» seleziona la foto per il configuratore e per custom.foto_cruscotto_originale. Le bozze precedenti mantengono la scelta automatica del cruscotto fino alla prima selezione manuale. Per Shopify compilare sotto la foto il nome esatto del file già caricato in Contenuti → File o il suo GID: file_reference non accetta URL Aruba. Il flag da solo conserva la scelta ma non crea un riferimento Shopify inesistente; l’analisi export segnala il riferimento mancante. Deselezionando il flag il metafield esportato resta vuoto. Modifiche AI e ritagli conservano il flag ma invalidano il vecchio riferimento Shopify alla foto; il ripristino conserva la selezione.

### Estensione 0.2.1 — variante unica AliExpress
Acquisisce anche schede senza selettori SKU, usando una sola variante «Versión única» e il prezzo esatto visibile in EUR. Un’opzione realmente unica non richiede la classe CSS selected. Se esistono opzioni non leggibili, escluse (360/accessori) o comparse durante il caricamento, non viene usata la variante unica come ripiego. Intervalli di prezzo vengono rifiutati. Aggiornare i file dell’estensione e premere Ricarica in chrome://extensions.

### Anni inseriti manualmente
Nessun limite al 2020 o al 1950 nei moduli di importazione, nel backend e nelle ricerche inserite dall’utente. Gli anni devono essere interi positivi e l’anno finale non deve precedere quello iniziale. La selezione storica pre-2021 riguarda solo la lista iniziale di ricerca, non limita gli inserimenti manuali.

### Importazione di tutte le bozze nel configuratore
Il pulsante «Importa tutte le bozze nel configuratore» salva la bozza aperta, importa tutte le bozze in una transazione e, solo dopo il successo dell’intero elenco, elimina tutti i record importati dalla sezione bozze e i relativi originali privati/export. Foto pubbliche, prodotti e varianti nel configuratore sono conservati. In caso di errore su un prodotto, nessuna bozza viene cancellata e tutte le modifiche al catalogo vengono annullate. Il link originale del fornitore viene conservato in meta.autogenesi.source_url nel configuratore, ma la colonna custom.aliexpress_url non è più presente nell’Excel Shopify. Nessuna chiamata a Shopify viene eseguita.

### Modifica compatibilità dopo l’importazione
In Prodotti importati → Modifica veicolo si modificano marca, modello e anni del prodotto già nel configuratore. Aggiungi marca / modello consente più righe; Trasforma le converte nei campi indicizzati (KIA | HYUNDAI; 1:STONIC | 2:KONA). Ripetere una marca per associare più modelli allo stesso indice. Salva veicolo aggiorna il DB e valida anni e associazioni; titolo, varianti e prezzi restano invariati.

### Foto cruscotto nei prodotti già importati
Prodotti importati → Imposta/Modifica foto cruscotto apre la galleria del prodotto (foto principale, galleria, immagini varianti e foto cruscotto già associate). Selezionare una foto e salvare aggiorna meta.original_dashboard_images nel configuratore, senza cambiare la foto principale o gli altri campi. «Nessuna foto cruscotto» rimuove l’associazione. Il backend consente soltanto immagini già associate a quel prodotto e richiede accesso amministratore.

### Pulizia storico importazioni da lista
Ogni riga ha Cancella; Cancella righe completate rimuove tutte le righe completate di quella lista. Vengono eliminate soltanto le richieste nello storico, conservando prodotti e foto. Conteggio aggiornato automaticamente e liste vuote rimosse. Fermare prima eventuali ricerche attive tramite Ferma tutto.

### Compatibilità automatica da descrizione
L’importazione ricava compatibilities durante la lettura AI delle fonti originali: marca, modello, anni, citazione e possibile file immagine. I campi di ricerca non vincolano il range reale del prodotto. Le evidenze testuali vengono confrontate con testo e specifiche, i file devono essere tra quelli acquisiti. Sono mantenute tutte le coppie valide; più marche/modelli sono codificati con indici. Gli intervalli per coppia restano in metadata.vehicle_compatibilities e nel catalogo, dove le ricerche del configuratore e del bot verificano anche il range specifico. La compatibilità richiesta inizialmente resta in requested_vehicle. In assenza di riscontri non si inventano correzioni e viene registrato un avviso. Le modifiche manuali successive in Prodotti importati sostituiscono questi intervalli.

### Esporta catalogo importato per data
In Prodotti importati, selezionare una data e premere Esporta Excel Shopify. Sono inclusi tutti i prodotti di quel giorno (Europe/Rome), indipendentemente da filtri e paginazione, leggendo il catalogo attuale e le sue varianti, anche dopo l’eliminazione delle bozze. L’importazione registra meta.imported_at; per i record precedenti si usa meta.autogenesi.imported_at o la data di creazione come ripiego dichiarato. Formato Products identico al modello, più Analisi AutoGenesi: descrizione e metafield soltanto sulla prima riga, stato Draft e nessun metafield AliExpress URL. I percorsi /storage diventano URL Aruba; trasferire prima i file con FileZilla. Eventuali riferimenti Shopify della foto cruscotto mancanti sono segnalati nel foglio Analisi. Nessun invio automatico a Shopify.

### Excel Shopify: solo Products
Tutti gli export (singola bozza, tutte le bozze e prodotti importati per data) contengono esclusivamente il foglio Products. Il foglio Analisi AutoGenesi non viene più incluso. Per ottenere il formato aggiornato scaricare nuovamente l’Excel.

### Foto Aruba senza collegamento public/storage
La route pubblica, autonoma in routes/web.php senza controller aggiuntivi, GET/HEAD /storage/autogenesi/catalog/{source}/{sha256}.{png|jpg|webp} legge direttamente storage/app/public/autogenesi/catalog/. Funziona anche senza collegamento simbolico e senza record della bozza. Serve esclusivamente immagini valide con nome SHA256 corrispondente e percorso dentro la directory catalogo; nessun accesso agli originali privati. Per attivarla su Aruba aggiornare soltanto routes/web.php, poi aggiornare l’eventuale cache rotte tramite il normale aggiornamento dell’app. Le foto restano nel percorso già trasferito con FileZilla.

### Stato prodotti negli export
Tutti gli Excel esportano Status = Active, sia dalle bozze sia dai prodotti importati per data. La permanenza della scheda in bozza locale non impone lo stato bozza al file esportato. Scaricare nuovamente il file dopo questa modifica; i vecchi Excel con Status Draft continuano a essere respinti dall’importatore del configuratore.

### Recupero migrazione batches su MySQL Aruba
La migrazione 2026_11_04_180000 rileva tipo e attributo unsigned di users.id e li applica a autogenesi_batches.user_id, senza modificare users. Può essere rieseguita dopo un errore DDL MySQL: riutilizza tabelle esistenti e aggiunge le foreign key mancanti conservando i dati. Per un aggiornamento fallito in produzione sostituire il file della migrazione e ripremere Aggiorna database. Verificato in SQLite il recupero e mediante metadata simulati i tipi INT/BIGINT MySQL; nessuna connessione al database remoto effettuata.

### Link fornitore dopo import Excel
Prodotti importati mostra il link salvato in meta.autogenesi.source_url o meta.source_url. Per i prodotti AutoGenesi AliExpress il cui Excel non contiene il metafield fornitore, recupera il link dall’ID nel suffisso -ae-ID dell’Handle. Nessuna colonna aggiunta all’Excel Shopify e nessun link inventato per i prodotti di altri siti.
