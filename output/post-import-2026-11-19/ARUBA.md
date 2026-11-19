# Post-importazione — 19 novembre 2026

Carica il contenuto di `output/post-import-2026-11-19/aruba-post-import.zip` nella cartella Laravel su Aruba mantenendo i percorsi. Contiene esattamente questi 15 file: 12 cataloghi JSON e tre immagini WEBP.

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/screen-descriptions-it.json`
- `resources/data/screen-descriptions-en.json`
- `resources/data/camera-titles-it.json`
- `resources/data/camera-titles-en.json`
- `resources/data/camera-descriptions-it.json`
- `resources/data/camera-descriptions-en.json`
- `resources/data/speaker-titles-it.json`
- `resources/data/speaker-titles-en.json`
- `resources/data/speaker-descriptions-it.json`
- `resources/data/speaker-descriptions-en.json`
- `public/images/vehicles-dark/hyundai-encino-2018-2022.webp`
- `public/images/vehicles-dark/bmw-serie-5-e39-1996-2007.webp`
- `public/images/vehicles-dark/bmw-x5-e53-1996-2007.webp`

Poi premi **Aggiorna database** e ricarica la Dashboard. L'importazione usa i cataloghi esistenti senza SSH e senza richieste esterne. Non serve nuova build o caricamento PHP. Non caricare database, `.env`, test, anteprima, documentazione o PNG originali.

## Schede richieste

Completati i titoli IT/EN Toyota RAV4 (camera), Renault Latitude/Laguna, BMW E39/X5/M5, Citroen C4 Grand Picasso e Hyundai Kona. Confermato il titolo SEAT Ateca già presente. Completate le cinque descrizioni HTML richieste, compresa quella Ateca con il contenuto aggiornato. Nessuna descrizione aggiunta per Renault Latitude/Laguna, per cui l'allegato richiedeva solo il titolo.

La telecamera RAV4 non è presente nel database locale: cataloghi pronti per il server, senza creare prodotti fittizi. Per gli altri prodotti i sorgenti locali differiscono in parte dall'allegato. I sorgenti spagnoli locali sono stati conservati; le traduzioni locali corrispondono al testo locale, mentre i cataloghi per gli handle richiesti usano come sorgente esatto il testo dell'allegato. Non è stata applicata a un vecchio sorgente una traduzione contenente le nuove specifiche. Se un sorgente server differisce da quello dell'allegato, il comando conserva la verifica del sorgente.

## Copertura massiva

Tutte le 600 descrizioni locali sono tradotte in italiano e inglese: 464 schermi, 118 camere e 18 altoparlanti. Il controllo ha trovato e completato 15 descrizioni locali mancanti. Aggiornati i cataloghi per i testi locali non compresi nell'allegato; aggiunto anche titolo IT/EN L6 Pro Renault Logan presente localmente e mancante nei cataloghi. Importati gli altri titoli locali già coperti dai cataloghi. Nessun titolo o HTML spagnolo originale è cambiato.

I sorgenti aggiornati comprendono Focus, Subaru, Mitsubishi Pajero, Jeep, Beetle, Citroen C3/DS3, Audi Q5 e Renault Logan/Sandero. Conservate tutte le specifiche e le differenze fra varianti, incluse compatibilità da verificare, versioni Linux, accessori separati e funzioni soggette ad attivazione.

## Immagini

Due nuove fotografie generate con imagegen integrato: BMW Serie 5 E39 berlina e X5 E53 SUV. Per Hyundai Encino riutilizzata la foto Kona della stessa generazione. Finali WEBP 1536×1024 nei percorsi esatti richiesti, ispezionati nell'anteprima `contact-sheet.jpg`; ciascun anno degli intervalli richiesti è risolto anche passando al resolver il solo nuovo file.

I nomi mantengono i range forniti nel catalogo: non costituiscono conferma dei reali anni di produzione né di compatibilità delle autoradio. Le sigle E39/E53 identificano la carrozzeria e non sono scritte nelle immagini. Prompt completi e percorsi finali: `PROMPT-IMMAGINI.md`. PNG originali: `image-manifest.json`.

Nel database locale restano tre immagini separate non presenti nell'elenco ricevuto: Citroen C4 Grand Picasso 2006–2013, Renault Sandero 2 2012–2019 e Renault Logan 2 2012–2019. Non sono state nascoste o sostituite con carrozzerie diverse. Le tre immagini richieste nell'allegato sono completate.

## Verifiche

25 test superati, 996 asserzioni: traduzioni, aggiornamento database, immagini e importatore. Estesi i test Dashboard ai nuovi handle screen e alla telecamera RAV4: l'importazione dei cataloghi avviene senza chiamate esterne, conserva gli originali e azzera le attività traduzione completate. Verificata la conservazione delle traduzioni a sorgente invariato e invalidazione a testo cambiato.

JSON validi; tag HTML, attributi e sequenza numerica preservati nelle cinque descrizioni richieste e nelle 15 locali; sorgenti spagnoli locali confrontati con lo stato iniziale. Tutti i titoli locali sono tradotti. Tre WEBP decodificabili e ZIP confrontato con i file finali. Le altre modifiche già presenti nel workspace sono state lasciate intatte ed escluse dal pacchetto.
