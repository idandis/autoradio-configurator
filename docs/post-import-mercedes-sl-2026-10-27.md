# Post-importazione Mercedes SL — 27 ottobre 2026

Carica questi 12 file nella cartella del progetto Laravel su Aruba, mantenendo gli stessi percorsi:

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

Poi premi **Aggiorna database** e ricarica la Dashboard. Il flusso già presente importa i cataloghi senza SSH; nessuna modifica al backend o build frontend richiesta. Non caricare il database locale, `.env`, test o questo documento.

Completati i quattro prodotti Mercedes SL dell'allegato in IT/EN, incluso il nuovo titolo del modello 7″. Aggiornate inoltre le descrizioni locali Hyundai i20, Nissan NV200, Mitsubishi ASX, Opel Insignia e prodotto stock `2` dove il sorgente locale era cambiato. Conservate le incongruenze e le specifiche del testo spagnolo, senza correzioni commerciali arbitrarie.

Copertura completa: 425 schermi, 118 camere e 18 altoparlanti, 561 descrizioni per lingua. Importate 51 descrizioni mancanti e 47 titoli mancanti per lingua nel database locale; nessuna traduzione di queste categorie resta incompleta. Nessun titolo o HTML spagnolo originale modificato.

Verifiche: tutti i JSON validi; cataloghi descrizioni corrispondenti a tutti i sorgenti locali; tag HTML, attributi, link e valori numerici preservati nelle voci aggiornate. 28 test superati, 415 asserzioni: titoli, descrizioni, import successivi con conservazione delle traduzioni a sorgente invariato e invalidazione quando cambia, aggiornamento database, scomparsa delle attività dalla Dashboard e risoluzione/generazione immagini. Nessuna immagine mancante nell'allegato; nessuna nuova immagine generata.
