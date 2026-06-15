<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use App\Support\VehicleTitleParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfiguratorCsvImporter
{
    private const CAMERA_STANDARD_HANDLE = 'camara-trasera-estandar';
    private const CAMERA_SPECIFIC_HANDLE = 'camara-trasera-especifica';

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
            $mapped[$header] = $row[$index] ?? null;
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
        $vehicleData = $category === 'screen'
            ? VehicleTitleParser::parse($title, $brand)
            : ['model' => null, 'year_from' => null, 'year_to' => null];

        $variants = array_values(array_filter(array_map(function (array $row) {
            $price = trim((string) ($row['Variant Price'] ?? ''));
            $sku = trim((string) ($row['Variant SKU'] ?? ''));
            $optionValue = trim((string) ($row['Option1 Value'] ?? ''));

            if ($price === '' && $sku === '' && $optionValue === '') {
                return null;
            }

            return [
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
}
