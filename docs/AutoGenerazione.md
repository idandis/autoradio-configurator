# AutoGenerazione

Progetto annotato il 29 ottobre 2026. In attesa; priorità attuale: migliorare il bot WhatsApp sul Mac in locale.

## Obiettivo

Generare schede prodotto e file di importazione partendo da una lista di auto (marca, modello, anno). Il primo prototipo userà una sola auto.

## Flusso richiesto

1. Cercare su AliExpress prodotti compatibili con marca, modello, anno, generazione e allestimento.
2. Confrontare varianti equivalenti per caratteristiche, prezzo complessivo, spedizione verso paese/CAP europeo, vendite e affidabilità del venditore, usando i dati disponibili.
3. Proporre due immagini prodotto e una degli accessori inclusi nella variante. L'ultima immagine deve raffigurare il cruscotto e la radio originali della vettura; reperirla separatamente se necessario e verificare compatibilità e possibilità d'uso.
4. Generare una descrizione secondo regole da definire, senza inventare caratteristiche.
5. Produrre un CSV importabile sia in Shopify sia nel database dell'app, con i metafield richiesti e immagini ordinate.

## Prototipo previsto

Esecuzione locale: una vettura, confronto di 3–5 candidati, conferma dell'utente su prodotto/variante/immagini, generazione CSV. Nessuna pubblicazione automatica iniziale.

## Integrazione AliExpress

- Portale attuale: https://open.aliexpress.com/
- La procedura sul precedente portale console.aliexpress.com rifiutava il salvataggio con “海外版禁止写入”.
- Registrazione sul nuovo portale inviata come Dropshipping (individual), paese Spagna.
- Stato mostrato: Under Review; tempo indicato dal portale: 2–5 giorni lavorativi. Approvazione non ancora verificata.
- Dopo approvazione: verificare creazione app, autorizzazione account, permessi, quote e risposte reali per ricerca, dettagli, SKU, immagini e logistica. Nessuna integrazione API ancora implementata o collaudata.
- Possibile problema nel modulo inviato: campo LinkedIn con limite 30 caratteri, URL probabilmente troncato. Correggere se richiesto dal portale.

## Base esistente e questioni aperte

- `app/Services/ConfiguratorCsvImporter.php` legge già colonne Shopify e alcuni metafield, inclusa la foto del cruscotto originale.
- Definire elenco completo dei metafield e verificare tipi supportati dal CSV Shopify; adattare l'importatore dell'app dove necessario.
- Definire caratteristiche minime delle autoradio, criteri di punteggio, paese/CAP di consegna, regole descrittive, lingue e prezzi di vendita.
- Verificare le condizioni AliExpress per uso commerciale, immagini, conservazione e trasferimenti a Shopify o servizi AI.
- Localizzazione del trattamento e fornitori AI non ancora definiti o verificati. Non considerare definitive le risposte suggerite durante la compilazione del questionario.
- Prevedere tracciabilità tramite ID prodotto/SKU AliExpress e gestione di aggiornamento/cancellazione nei sistemi collegati e nei file esportati.

## Ripresa

Controllare l'esito della registrazione AliExpress, poi provare il recupero dati di un prodotto reale prima di sviluppare il flusso completo.
