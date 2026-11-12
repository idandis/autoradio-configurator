# AutoGenesi — Regole per titolo e descrizione

Scrivere in spagnolo una scheda autoradio pronta per Shopify, rivolta direttamente al cliente: stile di un copywriter pubblicitario esperto, precisione tecnica di un ingegnere.

## Fonti e accuratezza

- Leggere la descrizione reale del prodotto, le specifiche, le opzioni della variante e le immagini pertinenti. Non usare un riepilogo generato da AliExpress come conferma delle specifiche.
- Conservare tutte le informazioni tecniche disponibili, senza inventare dati o attribuire a una variante le caratteristiche di un'altra.
- Distinguere dotazione inclusa, opzioni e accessori necessari o venduti separatamente. Usare formule naturali come «según la versión» e «adaptador vendido por separado» solo quando supportate dalle fonti.
- Non trasformare condizioni, garanzie, consegne o servizi del fornitore in promesse del nostro negozio.
- Segnalare dati mancanti o contraddittori separatamente al gestore, fuori dal testo commerciale. Non risolvere contraddizioni scegliendo arbitrariamente un valore.

## Titolo

- Titolo caratterizzante, singolare e accattivante: obiettivo 8 parole, massimo 10.
- Includere sempre dimensioni in pollici e tipo DIN.
- Per prodotti specifici includere marca, modello e intervallo degli anni compatibili, preferibilmente alla fine: «para Audi A4 2001-2009».
- Se pollici, DIN o compatibilità non sono verificabili, segnalarli tra i dati mancanti: non inventarli per completare il titolo. Il titolo resta da completare prima dell'approvazione.

## Apertura

- Dopo il titolo, un'introduzione di massimo 30 parole che crei una connessione emotiva e spieghi chiaramente i benefici documentati.
- Non inserire etichette come «Descripción Inicial» o «descrizione iniziale».

## Caratteristiche tecniche

- Elenco puntato molto dettagliato, senza limite di lunghezza, organizzato per categorie pertinenti al prodotto.
- Riportare tutte le specifiche presenti nel testo e nelle immagini, senza omissioni e senza duplicazioni.
- Esplicitare chiaramente differenze tra varianti, compatibilità e accessori richiesti.
- Usare linguaggio diretto, professionale e commerciale: «Pantalla IPS de 12,3 pulgadas», senza formule come «el fabricante declara», «la ficha indica», «en las imágenes se ve», «compatibilidad anunciada» o «dimensión indicada».
- Non inserire l'etichetta «descrizione tecnica». Sono consentite intestazioni utili per le categorie tecniche.

## Chiusura

- Scrivere una breve storia coinvolgente che colleghi il prodotto a una passione o a un problema del lettore, con fantasia narrativa e benefici supportati dai dati.
- Non presentare la storia come una testimonianza reale e non aggiungere prestazioni o promesse non documentate.
- Nessuna intestazione come «Conclusión» o «Conexión emocional con el producto», né commenti sulle fonti.

## Output separati

1. Scheda commerciale: titolo, apertura, caratteristiche raggruppate e chiusura, pronta per il negozio.
2. Note riservate al gestore: dati mancanti, contraddizioni e verifiche necessarie. Queste note non devono finire nella descrizione Shopify.

L'importazione legge la descrizione originale anche quando è caricata in un componente Shadow DOM, espande la tabella tecnica e acquisisce le immagini della descrizione separatamente dalla galleria. Il riepilogo AI e le recensioni restano esclusi dalle fonti della riscrittura.

La generazione usa la chiave OpenAI già configurata nel progetto, il modello `services.openai.text_model` e invia testo, specifiche e immagini acquisite. Non crea una descrizione di ripiego basata soltanto sul titolo: se manca la descrizione originale o la generazione non termina, l'operazione fallisce. Le immagini non accessibili sono elencate nelle note riservate e non considerate lette.

Il controllo verifica i limiti di parole, i riferimenti vietati alle fonti e la presenza di ogni voce tecnica nel testo o nelle note. Le versioni Android numeriche discordanti restano nelle note. Questo controllo non sostituisce la verifica tecnica manuale delle affermazioni del fornitore.

Per le bozze esistenti usare «Rileggi e ricrea descrizione»: sostituisce titolo e descrizione conservando foto selezionate e prezzi manuali. La versione precedente è conservata nei metadati privati. Le esportazioni vengono rigenerate; il prodotto resta in bozza.

## Compatibilità dalla descrizione originale
Marca, modello e anni inseriti sono una query, non una prova. Estrarre tutte le coppie marca/modello realmente compatibili dalla descrizione, specifiche e immagini originali, con gli intervalli completi dichiarati. Correggere il modello richiesto quando la fonte descrive un’altra vettura. Escludere riferimenti negativi, non confermati o ipotetici. Restituire compatibilities con citazione letterale e, se letta in immagine, nome esatto del file. Non ricavare gli anni dal titolo appena generato e non troncare il range al solo anno cercato. Per più marche usare associazioni indicizzate e conservare l’intervallo di ciascuna coppia; nessuna combinazione marca/modello inventata.
## TRASFORMA — campi configuratore

Usare la lista più corta possibile senza unire compatibilità tecnicamente diverse. Non distinguere carrozzerie e versioni (Coupé, Cabrio, Touring, Sedan, 3/5 porte) quando condividono plancia, vano radio, sistema multimediale e cablaggi necessari per questo stesso prodotto. Separare soltanto quando tali caratteristiche cambiano. Usare nomi semplici ed evitare codici telaio superflui.

In compatibilities conservare model ed evidence letterali per verificare la fonte. Se la fonte conferma la medesima plancia/soluzione radio/cablaggi, proporre il nome semplificato in display_model e riportare la prova letterale in shared_fitment_evidence. Esempi condizionali: MINI F55/F56/F57 → MINI; BMW Serie 1 E81/E82/E87/E88 → Serie 1 E8x. Senza prova della compatibilità condivisa entrambi i campi aggiuntivi sono null; non accorpare per sola somiglianza dei nomi. Non unire intervalli separati inventando copertura intermedia.

Formato TRASFORMA: una sola marca scritta una volta, modelli separati da « | » senza numerazione (Marca: VOLVO; Modello: S60 | V70 | XC70). Più marche separate da « | » e modelli con l'indice della propria marca (Marca: FIAT | CITROEN | PEUGEOT; Modello: 1:Ducato | 2:Jumper | 3:Boxer). Al comando conversazionale TRASFORMA restituire soltanto i due campi Marca e Modello pronti da copiare, senza spiegazioni.
Maiuscole TRASFORMA: marche sempre tutte MAIUSCOLE. Per ogni nome modello usare soltanto l’iniziale maiuscola, conservando codici e sigle interamente MAIUSCOLI (esempi: Galaxy, Serie 1 E8X, S60, RAV4, C-HR). Applicare lo stesso stile ai due campi pronti da copiare.


### Foto da conservare nel prodotto

- Importazione e reimportazione, sia nelle bozze sia nel catalogo, conservano al massimo 3 foto prodotto, 1 foto accessori/package list e 1 confronto prima/dopo, quando presenti e riconosciuti.
- Preferire 2–3 fotografie chiare e distinte dell’autoradio; anche le immagini delle varianti sono candidate valide e prioritarie. Escludere tabelle delle versioni, schede RAM/CPU, pubblicità di funzioni e immagini non pertinenti.
- Riutilizzare i criteri AutoGenesi per accessori inclusi e confronto del cruscotto originale; non inventare categorie mancanti.
- Gli originali mantengono le proporzioni. Le immagini candidate non selezionate non entrano nella galleria del prodotto.
- Le foto già salvate o modificate manualmente non vengono eliminate dalla reimportazione. Evitare duplicati e aggiungere solo i tipi mancanti, rispettando i limiti.
