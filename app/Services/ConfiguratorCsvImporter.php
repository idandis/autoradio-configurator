<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ConfiguratorCsvImporter
{
    private const REQUIRED_HEADERS = [
        'Title',
        'Image Src',
        'Option1 Value',
        'Handle',
        'ID',
        'Variant ID',
        'Type',
        'Variant Price',
        'Variant SKU',
        'Price / Italia',
        'Price / Resto del Mondo',
        'Price / USA-CANADA',
        'Price / spagna',
        'Metafield: custom.radio_type [single_line_text_field]',
        'Metafield: custom.altavoces [single_line_text_field]',
        'Metafield: custom.modello_auto [single_line_text_field]',
        'Metafield: custom.anno [single_line_text_field]',
        'Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]',
    ];

    private const CAMERA_STANDARD_HANDLE = 'camara-trasera-estandar';

    private const CAMERA_SPECIFIC_HANDLE = 'camara-trasera-especifica';

    private const GENERIC_BRAND_TERMS = [
        '', '24h', 'accesorios', 'altavoces', 'amplificadores', 'amazon', 'cables', 'camaras',
        'cámaras', 'garmin', 'hizpo', 'kit dvr', 'localizadores gps', 'monitor', 'sistema de grabación',
    ];

    private const AUTOMOTIVE_BRANDS = [
        'ALFA ROMEO', 'AUDI', 'BMW', 'BYD', 'CHEVROLET', 'CITROEN', 'CITROËN', 'DACIA', 'DODGE',
        'FIAT', 'FORD', 'HONDA', 'HYUNDAI', 'JAGUAR', 'JEEP', 'KIA', 'LAND ROVER', 'LANCIA',
        'LEXUS', 'MAZDA', 'MERCEDES', 'MERCEDES-BENZ', 'MINI', 'MITSUBISHI', 'NISSAN', 'OPEL',
        'PEUGEOT', 'PORSCHE', 'RENAULT', 'SEAT', 'SKODA', 'ŠKODA', 'SMART', 'SUBARU', 'SUZUKI',
        'TOYOTA', 'VOLKSWAGEN', 'VW', 'VOLVO', 'MUSTANG', 'OPEL', 'KIA', 'JMC', 'JMCQ',
    ];

    private ?bool $supportsShopifyVariantId = null;

    private array $variantCatalog = [];

    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly VehicleImageGenerator $vehicleImageGenerator,
    ) {}

    public function import(string|UploadedFile $source, bool $replaceExistingDataset = true): array
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $extension = mb_strtolower($source instanceof UploadedFile
            ? $source->getClientOriginalExtension()
            : pathinfo($source, PATHINFO_EXTENSION));

        if (! $path || ! is_file($path)) {
            throw new RuntimeException('Import file not found.');
        }

        [$headers, $rows] = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->readSpreadsheet($path)
            : $this->readCsv($path);

        if (! is_array($headers)) {
            throw new RuntimeException('Invalid file header.');
        }

        $this->validateRequiredHeaders($headers);

        $grouped = [];

        foreach ($rows as $row) {
            $mapped = $this->mapRow($headers, $row);
            $productHandle = trim((string) $this->value($mapped, ['Handle', 'Product Handle']));

            if ($productHandle === '') {
                continue;
            }

            $grouped[$productHandle][] = $mapped;
        }

        $this->variantCatalog = $this->loadVariantCatalog($grouped);
        $this->loadMissingPublicVariantOptions($grouped);

        $stats = [
            'screen_products' => 0,
            'camera_products' => 0,
            'speaker_products' => 0,
            'variants' => 0,
        ];
        $existingTranslations = ConfiguratorProduct::query()
            ->get(['handle', 'title', 'title_it', 'title_en'])
            ->keyBy('handle');

        DB::transaction(function () use ($grouped, &$stats, $replaceExistingDataset, $existingTranslations): void {
            if ($replaceExistingDataset) {
                DB::table('configurator_variants')->delete();
                DB::table('configurator_products')->delete();
            } else {
                // Installations are managed exclusively through internal zones/services.
                ConfiguratorProduct::query()->where('category', 'installation')->delete();
            }

            foreach ($grouped as $handle => $rows) {
                $product = $this->buildProduct($handle, $rows);

                if ($product === null) {
                    continue;
                }

                $previous = $existingTranslations->get($handle);
                $titleChanged = ! $previous || $previous->title !== $product['product']['title'];
                $productData = [
                    ...$product['product'],
                    'title_it' => $titleChanged ? null : $previous->title_it,
                    'title_en' => $titleChanged ? null : $previous->title_en,
                ];

                $configProduct = ConfiguratorProduct::updateOrCreate(
                    ['handle' => $handle],
                    $productData,
                );
                $configProduct->variants()->delete();
                $configProduct->variants()->createMany($product['variants']);

                $stats[$product['product']['category'].'_products']++;
                $stats['variants'] += count($product['variants']);
            }
        });

        $imageStats = $this->vehicleImageGenerator->syncAfterImport();
        $stats['vehicle_images_missing'] = $imageStats['missing'];
        $stats['vehicle_images_queued'] = $imageStats['queued'];

        return $stats;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function readSpreadsheet(string $path): array
    {
        try {
            $rows = IOFactory::load($path)
                ->getActiveSheet()
                ->toArray(null, true, true, false);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to open Excel file.', previous: $exception);
        }

        $headers = array_shift($rows);

        return [$headers, $rows];
    }

    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            $header = str_replace("\u{FEFF}", '', (string) $this->sanitizeCsvValue($header));
            $header = trim($header, " \t\n\r\0\x0B\"'");

            if ($header !== '') {
                $mapped[$header] = $this->sanitizeCsvValue($row[$index] ?? null);
            }
        }

        return $mapped;
    }

    private function validateRequiredHeaders(array $headers): void
    {
        $normalizedHeaders = array_map(
            fn ($header) => mb_strtolower($this->normalizeHeader($header)),
            $headers,
        );
        $missing = [];

        foreach (self::REQUIRED_HEADERS as $requiredHeader) {
            if (! in_array(mb_strtolower($requiredHeader), $normalizedHeaders, true)) {
                $missing[] = $requiredHeader;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Import non eseguito. Mancano i campi essenziali: '.implode(', ', $missing).'.'
            );
        }
    }

    private function normalizeHeader(mixed $header): string
    {
        $header = str_replace("\u{FEFF}", '', (string) $this->sanitizeCsvValue($header));

        return trim($header, " \t\n\r\0\x0B\"'");
    }

    private function buildProduct(string $handle, array $rows): ?array
    {
        $primaryRow = $this->findPrimaryRow($rows);
        $primaryImageUrl = $this->extractPrimaryImage(
            $this->value($primaryRow, ['Image Src', 'Product Image'])
        );
        $title = trim((string) $this->value($primaryRow, ['Title', 'Product Title']));
        $primaryVariantId = $this->normalizeShopifyVariantId(
            $this->value($primaryRow, ['Variant ID', 'Variant Id'])
        );
        $shopifyProductTitle = trim((string) (
            $primaryVariantId ? ($this->variantCatalog[$primaryVariantId]['product_title'] ?? '') : ''
        ));

        if (($title === '' || $title === $handle) && $shopifyProductTitle !== '') {
            $title = $shopifyProductTitle;
        }
        $type = strtoupper(trim((string) $this->value($primaryRow, ['Type', 'Product Type'])));
        $tags = trim((string) $this->value($primaryRow, ['Tags', 'Product Tags']));
        $category = $this->detectCategory($handle, $title, $type, $tags);
        $isUniversalScreen = $this->isUniversalScreen($handle, $title, $tags);

        if ($category === null && $isUniversalScreen) {
            $category = 'screen';
        }

        if (
            $category === null &&
            trim((string) $this->value($primaryRow, $this->modelHeaders())) !== '' &&
            $this->parseExplicitYears($this->value($primaryRow, $this->yearHeaders())) !== null &&
            ! preg_match('/camara|cámara|camera/u', mb_strtolower($handle.' '.$title))
        ) {
            $category = 'screen';
        }

        if ($category === null) {
            return null;
        }

        $brand = $category === 'camera'
            ? $this->normalizeBrand($this->value($primaryRow, $this->radioTypeHeaders()))
            : $this->resolveBrand($primaryRow, $category);
        $explicitModel = $this->normalizeBrand($this->value($primaryRow, $this->modelHeaders()));
        $explicitYears = $this->parseExplicitYears(
            $this->value($primaryRow, $this->yearHeaders())
        );

        if (
            $category === 'screen' &&
            ! $isUniversalScreen &&
            ($explicitModel === null || $explicitYears === null)
        ) {
            return null;
        }

        $variants = array_values(array_filter(array_map(function (array $row) {
            $variantId = $this->normalizeShopifyVariantId($this->value($row, ['Variant ID', 'Variant Id']));
            $enriched = $variantId ? ($this->variantCatalog[$variantId] ?? []) : [];
            $price = $this->normalizeMoney(
                $this->value($row, [
                    'Price / spagna',
                    'Price / Spagna',
                    'Variant Price',
                    'Price / Italia',
                    'Price / Resto del Mondo',
                    'Price / USA-CANADA',
                ]) ?? ($enriched['price'] ?? null)
            );
            $sku = trim((string) ($this->value($row, ['Variant SKU']) ?? ($enriched['sku'] ?? '')));
            $optionValue = trim((string) ($this->value($row, ['Variant Option1 Value', 'Option1 Value']) ?? ''));
            $title = trim((string) ($this->value($row, ['Title', 'Product Title']) ?? ''));
            $variantTitle = trim((string) ($this->value($row, ['Variant Title']) ?? ''));
            $enrichedVariantTitle = trim((string) ($enriched['variant_title'] ?? ''));
            $imageUrl = $this->extractPrimaryImage(
                $this->value($row, ['Image Src', 'Product Image']) ?? ($enriched['image_url'] ?? $enriched['featured_image'] ?? null)
            );

            if ($price === null && $sku === '' && $optionValue === '' && $variantId === null && $variantTitle === '') {
                return null;
            }

            if (
                $enrichedVariantTitle !== '' &&
                ($variantTitle === '' || $variantTitle === $title)
            ) {
                $variantTitle = $enrichedVariantTitle;
            }

            if ($optionValue === '' && $variantTitle !== '' && $variantTitle !== $title) {
                $optionValue = $variantTitle;
            }

            $variant = [
                'title' => $optionValue !== '' ? $optionValue : ($variantTitle !== '' ? $variantTitle : ($title !== '' ? $title : $sku)),
                'sku' => $sku !== '' ? $sku : null,
                'option_value' => $optionValue !== '' ? $optionValue : null,
                'price' => $price,
                'image_url' => $imageUrl,
                'meta' => [
                    'option2' => $this->value($row, ['Variant Option2 Value', 'Option2 Value'])
                        ?? ($enriched['option2'] ?? null),
                    'option3' => $this->value($row, ['Variant Option3 Value', 'Option3 Value'])
                        ?? ($enriched['option3'] ?? null),
                    'variant_image' => $this->value($row, ['Variant Image']),
                    'variant_cost' => $this->value($row, ['Variant Cost']),
                    'regional_prices' => [
                        'italia' => $this->value($row, ['Price / Italia']),
                        'resto_del_mondo' => $this->value($row, ['Price / Resto del Mondo']),
                        'usa_canada' => $this->value($row, ['Price / USA-CANADA']),
                        'spagna' => $this->value($row, ['Price / spagna', 'Price / Spagna']),
                    ],
                ],
            ];

            if ($this->supportsShopifyVariantId()) {
                $variant['shopify_variant_id'] = $variantId;
            }

            return $variant;
        }, $rows)));

        if ($variants === []) {
            return null;
        }

        $prices = array_values(array_filter(array_map(
            fn (array $variant) => $variant['price'] !== null ? (float) $variant['price'] : null,
            $variants
        )));

        return [
            'product' => [
                'handle' => $handle,
                'category' => $category,
                'subtype' => $this->detectSubtype($category, $handle, $title),
                'title' => $title !== '' ? $title : $handle,
                'brand' => $brand,
                'model' => $explicitModel,
                'year_from' => $explicitYears['year_from'] ?? null,
                'year_to' => $explicitYears['year_to'] ?? null,
                'option_name' => trim((string) $this->value($primaryRow, ['Option1 Name'])) ?: null,
                'price_min' => $prices !== [] ? min($prices) : null,
                'image_url' => $primaryImageUrl,
                'tags' => $tags !== '' ? $tags : null,
                'meta' => [
                    'type' => $this->value($primaryRow, ['Type', 'Product Type']),
                    'screen_size' => $this->value($primaryRow, ['PULGADAS (product.metafields.custom.pulgadas)']),
                    'din' => $this->value($primaryRow, ['DIN (product.metafields.custom.dimensioni_schermo)']),
                    'cam' => $this->value($primaryRow, ['CAM (product.metafields.custom.cam)']),
                    'shopify_product_id' => $this->value($primaryRow, ['ID']),
                    'speaker_nominal_size' => $this->value($primaryRow, [
                        'Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]',
                    ]),
                    'speaker_sizes' => $this->parseSpeakerSizes($this->value($primaryRow, [
                        'Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]',
                    ])),
                    'speaker_categories' => $this->parseSpeakerCategories($this->value($primaryRow, [
                        'Metafield: custom.altavoces [single_line_text_field]',
                        'Product.custom.altavoces',
                    ])),
                    'original_dashboard_images' => $this->parseFileList($this->value($primaryRow, [
                        'Metafield: custom.foto_cruscotto_originale [list.file_reference]',
                        'Metafield: custom.foto_cruscotto_originale [list.file]',
                        'Product.custom.foto_cruscotto_originale',
                        'custom.foto_cruscotto_originale',
                        'Foto cruscotto originale (product.metafields.custom.foto_cruscotto_originale)',
                        'FOTO CRUSCOTTO ORIGINALE (product.metafields.custom.foto_cruscotto_originale)',
                    ]), $primaryImageUrl),
                ],
            ],
            'variants' => $variants,
        ];
    }

    /** @return array<int, array{url: string, variant: ?string}> */
    private function parseFileList(mixed $value, ?string $primaryImageUrl = null): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            $items = is_array($decoded)
                ? $decoded
                : (preg_split('/\s*(?:,|;|\||\R)\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        return collect($items)
            ->map(function ($item) use ($primaryImageUrl) {
                $url = trim((string) (is_array($item)
                    ? ($item['url'] ?? $item['src'] ?? $item['originalSource'] ?? '')
                    : $item));
                $url = $this->resolveFileUrl($url, $primaryImageUrl);
                $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);
                $filename = pathinfo(rawurldecode($path), PATHINFO_FILENAME);
                preg_match('/-([a-z])$/iu', $filename, $matches);

                return $url === '' ? null : [
                    'url' => $url,
                    'variant' => isset($matches[1]) ? mb_strtoupper($matches[1]) : null,
                ];
            })
            ->filter()
            ->unique('url')
            ->values()
            ->all();
    }

    private function resolveFileUrl(string $value, ?string $primaryImageUrl): string
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (! $primaryImageUrl || ! filter_var($primaryImageUrl, FILTER_VALIDATE_URL)) {
            return $value;
        }

        $parts = parse_url($primaryImageUrl);
        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $value;
        }

        $directory = rtrim(str_replace('\\', '/', dirname($parts['path'])), '/');
        $filename = rawurlencode(rawurldecode(basename(str_replace('\\', '/', $value))));

        return "{$parts['scheme']}://{$parts['host']}{$directory}/{$filename}";
    }

    private function findPrimaryRow(array $rows): array
    {
        foreach ($rows as $row) {
            if (trim((string) $this->value($row, ['Title', 'Product Title'])) !== '') {
                return $row;
            }
        }

        return $rows[0];
    }

    private function detectCategory(string $handle, string $title, string $type, string $tags = ''): ?string
    {
        $needle = mb_strtolower(trim($handle.' '.$title.' '.$tags));

        if ($type === 'CAM') {
            return 'camera';
        }

        if (in_array($type, ['ALTAVOCES', 'ALTAVOZ', 'ALTOPARLANTI', 'ALTOPARLANTE', 'SPEAKER', 'SPEAKERS'], true)) {
            return 'speaker';
        }

        if (in_array($type, ['RADIO AM/FM', 'OEM'], true)) {
            return 'screen';
        }

        if (str_contains($needle, 'instalacion') || str_contains($needle, 'instalación')) {
            return 'installation';
        }

        return null;
    }

    private function isUniversalScreen(string $handle, string $title, string $tags = ''): bool
    {
        $needle = mb_strtolower(trim($handle.' '.$title.' '.$tags));

        return str_contains($needle, 'universal') &&
            preg_match('/autoradio|auto radio|pantalla|screen|carplay|android auto|\b(?:1|2)\s*din\b/u', $needle) === 1 &&
            preg_match('/camara|cámara|camera|altavoz|altavoces|speaker|tweeter|subwoofer/u', $needle) !== 1;
    }

    private function parseSpeakerSizes(mixed $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        $values = is_array($decoded)
            ? $decoded
            : (preg_split('/\s*[|;,]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return collect($values)
            ->map(fn ($size) => trim((string) $size, " \t\n\r\0\x0B\"'[]"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function parseSpeakerCategories(mixed $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        $values = is_array($decoded)
            ? $decoded
            : (preg_split('/\s*[|;,]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return collect($values)
            ->map(fn ($category) => trim((string) $category, " \t\n\r\0\x0B\"'[]"))
            ->filter()
            ->unique(fn ($category) => mb_strtolower($category))
            ->values()
            ->all();
    }

    private function detectSubtype(string $category, string $handle, string $title): ?string
    {
        $needle = mb_strtolower($handle.' '.$title);

        if ($category === 'camera') {
            if ($handle === self::CAMERA_STANDARD_HANDLE) {
                return 'standard';
            }

            if ($handle === self::CAMERA_SPECIFIC_HANDLE) {
                return 'specific';
            }

            if (str_contains($needle, 'ahd')) {
                return 'ahd';
            }

            if (str_contains($needle, 'frontal')) {
                return 'front';
            }

            return 'rear';
        }

        return null;
    }

    private function normalizeBrand(?string $brand): ?string
    {
        $brand = trim((string) $brand);

        return $brand !== '' ? $brand : null;
    }

    private function resolveBrand(array $row, string $category): ?string
    {
        $explicitBrand = $this->normalizeBrand($this->value($row, $this->radioTypeHeaders()));

        if ($explicitBrand !== null) {
            return $explicitBrand;
        }

        $title = trim((string) $this->value($row, ['Title', 'Product Title']));
        $candidates = $this->extractBrandCandidatesFromTitle($title);

        foreach ([$this->value($row, ['Collection Titles']), $this->value($row, ['Product Tags'])] as $source) {
            foreach (preg_split('/\s*,\s*/', trim((string) $source), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
                $candidate = $this->normalizeBrandCandidate($item);

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));

        return $candidates[0] ?? null;
    }

    private function normalizeBrandCandidate(string $value): ?string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $lower = mb_strtolower($normalized);

        if (in_array($lower, self::GENERIC_BRAND_TERMS, true)) {
            return null;
        }

        $uppercase = mb_strtoupper($normalized);

        if (in_array($uppercase, self::AUTOMOTIVE_BRANDS, true)) {
            return $uppercase === 'VW' ? 'VOLKSWAGEN' : $uppercase;
        }

        return null;
    }

    private function extractBrandCandidatesFromTitle(string $title): array
    {
        $normalizedTitle = mb_strtoupper($title);
        $candidates = [];

        foreach (self::AUTOMOTIVE_BRANDS as $brand) {
            if (str_contains($normalizedTitle, $brand)) {
                $candidates[] = $brand === 'VW' ? 'VOLKSWAGEN' : $brand;
            }
        }

        return $candidates;
    }

    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_convert_encoding($value, 'UTF-8', ['UTF-8', 'Windows-1252', 'ISO-8859-1']);

        return preg_replace('/^\xEF\xBB\xBF/u', '', $normalized) ?? $normalized;
    }

    private function radioTypeHeaders(): array
    {
        return [
            'Product.custom.radio_type',
            'MARCA DE COCHE (product.metafields.custom.radio_type)',
            'Metafield: custom.radio_type [single_line_text_field]',
        ];
    }

    private function modelHeaders(): array
    {
        return [
            'Product.custom.modello_auto',
            'Metafield: custom.modello_auto [single_line_text_field]',
        ];
    }

    private function yearHeaders(): array
    {
        return [
            'Product.custom.anno',
            'Metafield: custom.anno [single_line_text_field]',
        ];
    }

    private function normalizeMoney(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\d,.-]/u', '', $value) ?? '';

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function parseExplicitYears(mixed $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        preg_match_all('/\b(?:19|20)\d{2}\b/u', $value, $matches);
        $years = array_map('intval', $matches[0] ?? []);

        if ($years === []) {
            return null;
        }

        return [
            'year_from' => min($years),
            'year_to' => count($years) === 1 && str_contains($value, '+')
                ? (int) date('Y')
                : max($years),
        ];
    }

    private function normalizeShopifyVariantId(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\d+$/', $value, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function supportsShopifyVariantId(): bool
    {
        return $this->supportsShopifyVariantId ??= Schema::hasColumn('configurator_variants', 'shopify_variant_id');
    }

    private function value(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function extractPrimaryImage(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $images = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $images[0] ?? null;
    }

    private function loadVariantCatalog(array $grouped): array
    {
        $missingData = false;
        $variantIds = [];

        foreach ($grouped as $rows) {
            foreach ($rows as $row) {
                $variantId = $this->normalizeShopifyVariantId($this->value($row, ['Variant ID', 'Variant Id']));

                if ($variantId !== null) {
                    $variantIds[] = $variantId;
                }

                if (
                    $this->value($row, [
                        'Variant Price',
                        'Price / spagna',
                        'Price / Spagna',
                        'Price / Italia',
                        'Price / Resto del Mondo',
                        'Price / USA-CANADA',
                    ]) === null ||
                    $this->value($row, ['Variant SKU']) === null
                ) {
                    $missingData = true;
                }
            }
        }

        if (! $missingData || $variantIds === [] || ! $this->shopifyService->isConfigured()) {
            return [];
        }

        return $this->shopifyService->getVariantsByIds($variantIds);
    }

    private function loadMissingPublicVariantOptions(array $grouped): void
    {
        foreach ($grouped as $handle => $rows) {
            $rowsByFirstOption = collect($rows)
                ->filter(function (array $row): bool {
                    return $this->normalizeShopifyVariantId(
                        $this->value($row, ['Variant ID', 'Variant Id']),
                    ) !== null && filled($this->value($row, ['Variant Option1 Value', 'Option1 Value']));
                })
                ->groupBy(fn (array $row) => mb_strtolower(trim((string) (
                    $this->value($row, ['Variant Option1 Value', 'Option1 Value']) ?? ''
                ))));
            $hasUnresolvedDuplicateOptions = $rowsByFirstOption->contains(function ($matchingRows): bool {
                if ($matchingRows->count() < 2) {
                    return false;
                }

                return $matchingRows->contains(fn (array $row) => $this->value($row, [
                    'Variant Option2 Value',
                    'Option2 Value',
                ]) === null);
            });

            if (! $hasUnresolvedDuplicateOptions) {
                continue;
            }

            try {
                foreach ($this->shopifyService->getPublicVariantOptions($handle) as $variantId => $options) {
                    $this->variantCatalog[$variantId] = array_merge(
                        $this->variantCatalog[$variantId] ?? [],
                        $options,
                    );
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
