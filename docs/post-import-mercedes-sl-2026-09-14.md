# Post-importazione Mercedes SL — 2026-09-14

## File da caricare su Aruba

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `public/images/vehicles-dark/mercedes-sl350-2001-2007.webp`
- `public/images/vehicles-dark/mercedes-sl500-2001-2007.webp`
- `public/images/vehicles-dark/mercedes-sl55-2001-2007.webp`
- `public/images/vehicles-dark/mercedes-sl600-2001-2007.webp`
- `public/images/vehicles-dark/mercedes-sl65-2001-2007.webp`

Dopo il caricamento premere **Aggiorna database**, quindi ricaricare la Dashboard. Il flusso esistente importa le traduzioni dai cataloghi e riconosce i cinque file immagine. Non occorrono modifiche al codice o build frontend. Questo documento non va caricato.

## Risultato e verifiche

- Aggiunta una voce per lingua; conservate le voci preesistenti e il titolo spagnolo originale.
- Handle assente dal database locale: nessun record permanente aggiornato.
- Importazione delle due traduzioni verificata con il comando applicativo su un record temporaneo, in transazione poi annullata; titolo ES invariato.
- 13 test pertinenti passati (23 asserzioni): ConfiguratorProductTitleTranslationTest, VehicleImageResolverTest, VehicleImageGeneratorTest.
- Una sola fotografia della carrozzeria R230, riutilizzata in cinque file identici: WEBP 1536×1024, 86.094 byte ciascuno. Controllo visivo e formato eseguiti; resolver verificato per tutti i cinque modelli in ciascun anno 2001–2007.
- Generazione con imagegen integrato, conversione WEBP con cwebp qualità 90.

## Prompt

Use case: product-mockup. Asset type: vehicle selector catalog. Generate one photorealistic studio photograph of a silver Mercedes-Benz SL roadster R230 early generation, representative 2003 body for 2001–2007 range, retractable hardtop closed, correct early twin oval connected headlamp clusters, low wide nose, Mercedes star grille and period factory wheels. Entire car centered with generous margins, front three-quarter view facing left, all wheels and body fully visible. Uniform seamless flat #121212 background, natural soft contact shadow beneath car. Soft realistic studio lighting and realistic materials. Landscape 1536x1024. No text, no license plate lettering, no model badges with lettering, no people, no watermark, no scenery. Chassis code only identifies body and must never appear as text. This single body image will be reused for SL350, SL500, SL55, SL600, SL65 as requested.

Rifinitura:

Edit this image: preserve exactly the car, correct early Mercedes SL R230 body, silver paint, wheels, framing, camera and lighting. Replace the backdrop with perfectly uniform solid #121212 (RGB 18,18,18) everywhere except the natural soft contact shadow immediately underneath the car. Remove backdrop texture and gradients. No text, no people. Keep the entire car visible.

