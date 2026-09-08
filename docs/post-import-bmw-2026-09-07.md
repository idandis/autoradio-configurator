# Immagini BMW post-importazione

Generazione: strumento imagegen integrato; conversione WEBP con cwebp, qualità 90.
Quattro immagini esistenti adattate rimuovendo il testo sulle targhe, tre nuove fotografie.

## Prompt utilizzati

### public/images/vehicles-dark/bmw-serie-1-e8x-2004-2011.webp

Modalità: edit

Riferimento: public/images/vehicles-dark/bmw-serie-1-2004-2011.webp

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: BMW 1 Series E87 five-door hatchback. Edit the reference: preserve this exact car and camera angle, remove all plate lettering and make plate blank, ensure backdrop #121212.

### public/images/vehicles-dark/bmw-serie-1-f2x-2011-2020.webp

Modalità: edit

Riferimento: public/images/vehicles-dark/bmw-f20-2011-2020.webp

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: BMW 1 Series F20 five-door hatchback. Edit the reference: preserve this exact car and camera angle, remove all plate lettering and make plate blank, ensure backdrop #121212.

### public/images/vehicles-dark/bmw-serie-3-e9x-2005-2013.webp

Modalità: edit

Riferimento: public/images/vehicles-dark/bmw-e90-2005-2013.webp

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: BMW 3 Series E90 four-door sedan. Edit the reference: preserve this exact car and camera angle, remove all plate lettering and make plate blank, ensure backdrop #121212.

### public/images/vehicles-dark/bmw-serie-5-e6x-2005-2012.webp

Modalità: edit

Riferimento: public/images/vehicles-dark/bmw-e60-2005-2008.webp

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: BMW 5 Series E60 four-door sedan. Edit the reference: preserve this exact car and camera angle, remove all plate lettering and make plate blank, ensure backdrop #121212.

### public/images/vehicles-dark/bmw-serie-2-f2x-2011-2020.webp

Modalità: generate

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: 2015 BMW 2 Series F22 two-door coupe, silver metallic. Authentic production vehicle, standard road trim. No chassis codes or model lettering anywhere.

### public/images/vehicles-dark/bmw-serie-3-f3x-2011-2020.webp

Modalità: generate

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: 2015 BMW 3 Series F30 four-door sedan, silver metallic. Authentic production vehicle, standard road trim. No chassis codes or model lettering anywhere.

### public/images/vehicles-dark/bmw-serie-4-f3x-2011-2020.webp

Modalità: generate

Use case: product-mockup. Photorealistic automotive catalog photograph. Entire car centered in front three-quarter view facing left, comfortable margins, natural contact shadow. Uniform seamless background exactly #121212, no gradient or scenery. No text, no people, no watermark; license plate completely blank. Preserve accurate factory body proportions. Landscape 1536x1024. Subject: 2015 BMW 4 Series F32 two-door coupe, silver metallic. Authentic production vehicle, standard road trim. No chassis codes or model lettering anywhere.

## Caricamento Aruba

Caricare resources/data/vehicle-image-generations.json e le sette immagini sopra elencate, mantenendo i percorsi del progetto. Questo documento non serve sul server.
Non ci sono traduzioni nell’elenco richiesto: nessun JSON dei titoli o titolo originale è stato modificato.
Dopo il caricamento aggiornare la Dashboard; il riconoscimento immagini legge i file direttamente. È possibile premere Aggiorna database come previsto dal normale flusso, ma per queste sole immagini non è necessario importare traduzioni.

## Verifiche

- Sette WEBP 1536×1024 ispezionati visivamente.
- Tutte le 65 combinazioni modello/anno richieste risolte nel catalogo.
- Sei JSON dei titoli validi (1.104 voci complessive).
- Database locale: zero immagini mancanti; 36 prodotti con traduzioni mancanti, estranei all’elenco ricevuto.
- Suite importazione: 12 fallimenti HTTP 403 e 4 errori relativi a fixture/file temporanei e intestazioni obbligatorie; non modificata in questa attività.

