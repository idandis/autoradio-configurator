# Post-importazione — 13 novembre 2026

Carica il contenuto di `output/post-import-2026-11-13/aruba-post-import.zip` nella cartella Laravel su Aruba, mantenendo i percorsi. Contiene esattamente questi 15 file: 12 cataloghi JSON e tre immagini WEBP.

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
- `public/images/vehicles-dark/opel-combo-2001-2011.webp`
- `public/images/vehicles-dark/opel-astra-j-2009-2018.webp`
- `public/images/vehicles-dark/opel-cascade-2009-2018.webp`

Poi premi **Aggiorna database** e ricarica la Dashboard. Il flusso esistente importa i cataloghi senza SSH e senza chiamate esterne. Nessuna build o nuovo file PHP da caricare. Non caricare database, `.env`, test, documentazione, anteprima o PNG originali.

## Traduzioni

Completate le descrizioni Astra J e Lexus RX in italiano e inglese, nei cataloghi e nel database locale. La nuova descrizione Astra è stata tradotta; per Lexus erano già disponibili traduzioni nei cataloghi, riapplicate al sorgente corrente preservando tabella, varianti Android 2015–2019 e Linux 2009–2015, tag, attributi, entità e numeri. Sorgenti spagnoli invariati. I titoli non richiedevano traduzione; quelli Astra già tradotti nel database sono stati aggiunti ai cataloghi, dove mancavano, per rendere completo l'import dal pulsante.

Tutte le 596 descrizioni locali (460 schermi, 118 camere, 18 altoparlanti) sono complete in IT/EN. Modificati i quattro cataloghi screen; cataloghi camere e altoparlanti già completi inclusi per la richiesta massiva.

## Immagini

- Combo C 2001–2011: nuova fotografia generata con imagegen integrato, carrozzeria furgone a tetto alto della seconda generazione.
- Astra J 2009–2018: riutilizzata la fotografia della stessa generazione già presente, modificando lo sfondo tramite imagegen e salvandola nel percorso richiesto. L'originale opel-astra-2009-2019.webp è conservato.
- Cascade 2009–2018: alias della fotografia Cascada già generata, senza duplicare la generazione. Il nome segue l'elenco ricevuto; rappresenta la stessa Cascada 2015, non un modello diverso.

Tutti i finali sono WEBP 1536×1024, interi e centrati con sfondo scuro e ombra naturale. Ispezionati nell'anteprima `contact-sheet.jpg`. Prompt completi: `PROMPT-IMMAGINI.md`; PNG originali delle due operazioni imagegen: `image-manifest.json`.

## Verifiche

25 test mirati superati su titoli, descrizioni, Aggiorna database, immagini e import CSV. Esteso e rieseguito con successo il test Dashboard per Astra J, inclusa importazione senza chiamate esterne e scomparsa delle attività traduzione completate (423 asserzioni in questo test). Il test importatore conferma conservazione delle traduzioni a sorgente invariato e invalidazione quando cambia.

JSON validi; descrizioni e sorgenti confrontati con l'allegato; HTML, attributi e sequenza numerica preservati; database locale aggiornato senza alterare i sorgenti. Tutti gli anni dei tre intervalli risolti nei percorsi richiesti. Zero immagini mancanti nel database locale. WEBP decodificabili e ZIP confrontato con i file finali. Le altre modifiche già presenti nel workspace sono escluse dal pacchetto.
