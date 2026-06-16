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
    private ?bool $supportsShopifyVariantId = null;

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
            $productHandle = trim((string) ($mapped['Handle'] ?? ''));

            if ($productHandle === '') {
                continue;
            }

            $grouped[$productHandle][] = $mapped;
        }

        fclose($handle);

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
        $title = trim((string) ($primaryRow['Title'] ?? ''));
        $type = strtoupper(trim((string) ($primaryRow['Type'] ?? '')));

        $category = $this->detectCategory($handle, $title, $type);

        if ($category === null) {
            return null;
        }

        $brand = $this->normalizeBrand($primaryRow['MARCA DE COCHE (product.metafields.custom.radio_type)'] ?? null);
        $vehicleData = $category === 'screen' && $brand !== null
            ? VehicleTitleParser::parse($title, $brand)
            : ['model' => null, 'year_from' => null, 'year_to' => null];

        $variants = array_values(array_filter(array_map(function (array $row) {
            $price = trim((string) ($row['Variant Price'] ?? ''));
            $sku = trim((string) ($row['Variant SKU'] ?? ''));
            $optionValue = trim((string) ($row['Option1 Value'] ?? ''));

            if ($price === '' && $sku === '' && $optionValue === '') {
                return null;
            }

            $variant = [
                'title' => $optionValue !== '' ? $optionValue : ($row['Title'] ?: $sku),
                'sku' => $sku !== '' ? $sku : null,
                'option_value' => $optionValue !== '' ? $optionValue : null,
                'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                'image_url' => trim((string) ($row['Image Src'] ?? '')) ?: null,
                'meta' => [
                    'option2' => $row['Option2 Value'] ?? null,
                    'variant_image' => $row['Variant Image'] ?? null,
                ],
            ];

            if ($this->supportsShopifyVariantId()) {
                $variant['shopify_variant_id'] = $this->normalizeShopifyVariantId($row['Variant ID'] ?? null);
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
                'option_name' => trim((string) ($primaryRow['Option1 Name'] ?? '')) ?: null,
                'price_min' => $prices !== [] ? min($prices) : null,
                'image_url' => trim((string) ($primaryRow['Image Src'] ?? '')) ?: null,
                'tags' => trim((string) ($primaryRow['Tags'] ?? '')) ?: null,
                'meta' => [
                    'type' => $primaryRow['Type'] ?? null,
                    'screen_size' => $primaryRow['PULGADAS (product.metafields.custom.pulgadas)'] ?? null,
                    'din' => $primaryRow['DIN (product.metafields.custom.dimensioni_schermo)'] ?? null,
                    'cam' => $primaryRow['CAM (product.metafields.custom.cam)'] ?? null,
                ],
            ],
            'variants' => $variants,
        ];
    }

    private function findPrimaryRow(array $rows): array
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['Title'] ?? '')) !== '') {
                return $row;
            }
        }

        return $rows[0];
    }

    private function detectCategory(string $handle, string $title, string $type): ?string
    {
        $needle = mb_strtolower($handle.' '.$title);

        if (in_array($type, ['RADIO AM/FM', 'OEM'], true)) {
            return 'screen';
        }

        if ($type === 'CAM' || str_contains($needle, 'camara') || str_contains($needle, 'cámara')) {
            return 'camera';
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
}
