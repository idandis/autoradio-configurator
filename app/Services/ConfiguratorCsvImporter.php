<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use App\Support\VehicleTitleParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConfiguratorCsvImporter
{
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
    ) {
    }

    public function import(string|UploadedFile $source): array
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;

        if (! $path || ! is_file($path)) {
            throw new RuntimeException('CSV file not found.');
        }

        $handle = fopen($path, 'r');

        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');

        if (! is_array($headers)) {
            throw new RuntimeException('Invalid CSV header.');
        }

        $grouped = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $mapped = $this->mapRow($headers, $row);
            $productHandle = trim((string) $this->value($mapped, ['Handle', 'Product Handle']));

            if ($productHandle === '') {
                continue;
            }

            $grouped[$productHandle][] = $mapped;
        }

        fclose($handle);

        $this->variantCatalog = $this->loadVariantCatalog($grouped);

        $stats = [
            'screen_products' => 0,
            'camera_products' => 0,
            'installation_products' => 0,
            'variants' => 0,
        ];

        DB::transaction(function () use ($grouped, &$stats): void {
            DB::table('configurator_variants')->delete();
            DB::table('configurator_products')->delete();

            foreach ($grouped as $handle => $rows) {
                $product = $this->buildProduct($handle, $rows);

                if ($product === null) {
                    continue;
                }

                $configProduct = ConfiguratorProduct::create($product['product']);
                $configProduct->variants()->createMany($product['variants']);

                $stats[$product['product']['category'].'_products']++;
                $stats['variants'] += count($product['variants']);
            }
        });

        return $stats;
    }

    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            $mapped[$this->sanitizeCsvValue($header)] = $this->sanitizeCsvValue($row[$index] ?? null);
        }

        return $mapped;
    }

    private function buildProduct(string $handle, array $rows): ?array
    {
        $primaryRow = $this->findPrimaryRow($rows);
        $title = trim((string) $this->value($primaryRow, ['Title', 'Product Title']));
        $type = strtoupper(trim((string) $this->value($primaryRow, ['Type', 'Product Type'])));
        $tags = trim((string) $this->value($primaryRow, ['Tags', 'Product Tags']));

        $category = $this->detectCategory($handle, $title, $type, $tags);

        if ($category === null) {
            return null;
        }

        $brand = $this->resolveBrand($primaryRow, $category);
        $vehicleData = $category === 'screen' && $brand !== null
            ? VehicleTitleParser::parse($title, $brand)
            : ['model' => null, 'year_from' => null, 'year_to' => null];

        $variants = array_values(array_filter(array_map(function (array $row) {
            $variantId = $this->normalizeShopifyVariantId($this->value($row, ['Variant ID', 'Variant Id']));
            $enriched = $variantId ? ($this->variantCatalog[$variantId] ?? []) : [];
            $price = trim((string) ($this->value($row, ['Variant Price']) ?? ($enriched['price'] ?? '')));
            $sku = trim((string) ($this->value($row, ['Variant SKU']) ?? ($enriched['sku'] ?? '')));
            $optionValue = trim((string) ($this->value($row, ['Option1 Value']) ?? ''));
            $title = trim((string) ($this->value($row, ['Title', 'Product Title']) ?? ''));
            $variantTitle = trim((string) ($this->value($row, ['Variant Title']) ?? ''));
            $enrichedVariantTitle = trim((string) ($enriched['variant_title'] ?? ''));
            $imageUrl = $this->extractPrimaryImage(
                $this->value($row, ['Image Src', 'Product Image']) ?? ($enriched['image_url'] ?? $enriched['featured_image'] ?? null)
            );

            if ($price === '' && $sku === '' && $optionValue === '' && $variantId === null && $variantTitle === '') {
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
                'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                'image_url' => $imageUrl,
                'meta' => [
                    'option2' => $this->value($row, ['Option2 Value']),
                    'variant_image' => $this->value($row, ['Variant Image']),
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
                'model' => $vehicleData['model'],
                'year_from' => $vehicleData['year_from'],
                'year_to' => $vehicleData['year_to'],
                'option_name' => trim((string) $this->value($primaryRow, ['Option1 Name'])) ?: null,
                'price_min' => $prices !== [] ? min($prices) : null,
                'image_url' => $this->extractPrimaryImage($this->value($primaryRow, ['Image Src', 'Product Image'])),
                'tags' => $tags !== '' ? $tags : null,
                'meta' => [
                    'type' => $this->value($primaryRow, ['Type', 'Product Type']),
                    'screen_size' => $this->value($primaryRow, ['PULGADAS (product.metafields.custom.pulgadas)']),
                    'din' => $this->value($primaryRow, ['DIN (product.metafields.custom.dimensioni_schermo)']),
                    'cam' => $this->value($primaryRow, ['CAM (product.metafields.custom.cam)']),
                ],
            ],
            'variants' => $variants,
        ];
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

        if (
            str_contains($needle, 'instalacion base') ||
            str_contains($needle, 'instalación base') ||
            str_contains($needle, 'instalacion de pantalla') ||
            str_contains($needle, 'instalación de pantalla')
        ) {
            return 'installation';
        }

        if ($type === 'CAM' || str_contains($needle, 'camara') || str_contains($needle, 'cámara')) {
            return 'camera';
        }

        if (in_array($type, ['RADIO AM/FM', 'OEM'], true)) {
            return 'screen';
        }

        if (str_contains($needle, 'instalacion') || str_contains($needle, 'instalación')) {
            return 'installation';
        }

        return null;
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

        if ($category === 'installation') {
            if (str_contains($needle, 'sin cam') || str_contains($needle, 'sin c')) {
                return 'screen_only';
            }

            if (str_contains($needle, 'camara') && str_contains($needle, 'pantalla')) {
                return 'screen_camera';
            }

            if (str_contains($needle, 'camara')) {
                return 'camera_only';
            }

            return 'general';
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
        $explicitBrand = $this->normalizeBrand($this->value($row, ['MARCA DE COCHE (product.metafields.custom.radio_type)']));

        if ($explicitBrand !== null) {
            return $explicitBrand;
        }

        if ($category === 'installation') {
            return null;
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
                    $this->value($row, ['Variant Price']) === null ||
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
}
