# Post-importazione 2026-09-08

## Aruba

Caricare i seguenti otto file mantenendo i percorsi del progetto:

- `resources/data/camera-titles-it.json`
- `resources/data/camera-titles-en.json`
- `resources/data/screen-titles-it.json`
- `resources/data/screen-titles-en.json`
- `resources/data/vehicle-image-generations.json`
- `public/images/vehicles-dark/alfa-romeo-gt-937-2000-2010.webp`
- `public/images/vehicles-dark/kia-morning-3-2016-2023.webp`
- `public/images/vehicles-dark/renault-twingo-2014-2024.webp`

Poi premere “Aggiorna database” e ricaricare la Dashboard. Il test isolato dei sei prodotti e tre veicoli termina con zero attività mancanti.

## Verifiche

- 12 traduzioni esatte importate usando il comando applicativo, senza API; sei originali spagnoli preservati.
- Sei JSON dei titoli validi e voci estranee alla richiesta invariate.
- Tre WEBP 1536×1024 ispezionati visivamente, 30 abbinamenti veicolo/anno verificati.
- 10 test superati (VehicleImageResolverTest e ConfiguratorProductTitleTranslationTest).
- Solo i due handle Qashqai sono presenti nel database locale, con titoli ES precedenti e traduzioni già complete e coerenti: mantenuti. Gli altri quattro prodotti non sono presenti localmente.

## Prompt imagegen

Strumento integrato imagegen, tre generazioni separate; conversione cwebp qualità 90. Gli intervalli nei nomi dei file sono quelli richiesti dal catalogo prodotti.

### alfa-romeo-gt-937-2000-2010.webp

Use case: product-mockup. Photorealistic automotive catalog photograph, silver metallic factory stock car. Front three-quarter view facing left, whole automobile centered with comfortable margins. Natural contact shadow only. Seamless flat uniform background #121212, no gradient, no scenery. No text, no people, no watermark; blank license plate. Landscape 1536x1024. Correct historical body generation, realistic proportions. Alfa Romeo GT type 937 Bertone two-door coupe, representative 2005 model, narrow horizontal headlights and triangular Alfa grille. NOT Alfa 147 hatchback, not Brera.

### kia-morning-3-2016-2023.webp

Use case: product-mockup. Photorealistic automotive catalog photograph, silver metallic factory stock car. Front three-quarter view facing left, whole automobile centered with comfortable margins. Natural contact shadow only. Seamless flat uniform background #121212, no gradient, no scenery. No text, no people, no watermark; blank license plate. Landscape 1536x1024. Correct historical body generation, realistic proportions. Kia Morning / Picanto THIRD generation JA five-door hatchback, representative 2018 model, authentic third generation body, not the second generation TA.

### renault-twingo-2014-2024.webp

Use case: product-mockup. Photorealistic automotive catalog photograph, silver metallic factory stock car. Front three-quarter view facing left, whole automobile centered with comfortable margins. Natural contact shadow only. Seamless flat uniform background #121212, no gradient, no scenery. No text, no people, no watermark; blank license plate. Landscape 1536x1024. Correct historical body generation, realistic proportions. Renault Twingo III third generation five-door hatchback, representative 2015 model, rear-engine generation with short hood and concealed rear door handles.

