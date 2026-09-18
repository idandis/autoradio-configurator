# Post-importazione Nissan Qashqai — 2026-09-14

## File da caricare su Aruba

- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `public/images/vehicles-dark/nissan-qashqai2-j10-2006-2013.webp`

Dopo il caricamento premere **Aggiorna database**: il flusso esistente importa le traduzioni mancanti dai cataloghi. I titoli spagnoli restano invariati. I due handle richiesti non sono presenti nel database locale, quindi non ci sono record locali da aggiornare.

## Immagine

Generata con imagegen integrato, corretta per eliminare il testo sul badge e convertita con cwebp qualità 90. WEBP 1536×1024, 88.096 byte. Una sola carrozzeria Qashqai+2; nessuna variante duplicata.

Prompt iniziale:

Use case: product-mockup. Asset type: vehicle selector catalog. Photorealistic studio automotive photograph of a silver Nissan Qashqai+2 first generation J10 long-wheelbase seven-seat crossover, representative 2009 body, with the distinctly extended rear body and longer rear quarter windows of the Qashqai+2. Generation requested by catalog: 2006–2013. Entire automobile centered with generous margins, front three-quarter view facing left; show the front and long side clearly. Factory-stock proportions and period-correct headlights and grille, realistic tires and materials. Uniform seamless flat background #121212 with a natural contact shadow. Soft studio lighting. Landscape 1536x1024. Blank license plate, no text, no people, no watermark, no scenery. Chassis code is identification only and must not appear as text.

Prompt di rifinitura:

Edit this vehicle photograph only: preserve the exact Nissan Qashqai+2 body, proportions, wheels, silver paint, camera angle and framing. Remove all lettering from the front grille emblem, leaving a plain chrome circular emblem with blank horizontal bar. Make the backdrop uniformly flat solid #121212 (RGB 18,18,18), without gradients or texture, retaining only a natural soft contact shadow immediately beneath the automobile. No text anywhere, no people. Keep photorealistic lighting on the car.

## Verifiche

- JSON validi; aggiunte due voci per lingua, senza modificare le voci precedenti.
- WEBP decodificabile e immagine controllata visivamente.
- VehicleImageResolver riconosce il file per NISSAN Qashqai+2 J10 in ciascun anno 2006–2013.
- Test pertinenti: 7 passati; il test DatabaseMigrationTest::test_admin_can_start_database_migrations_from_dashboard incontra una factory preesistente mancante (Database\\Factories\\ConfiguratorProductFactory).

Questo documento è un resoconto locale e non deve essere caricato su Aruba.

