<?php

namespace App\Services;

use App\Jobs\GenerateVehicleImage;
use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VehicleImageGenerator
{
    /**
     * Detect missing vehicle images, persist a compact manifest and enqueue only
     * images that can actually be generated with the configured provider.
     *
     * @return array{missing: int, queued: int}
     */
    public function syncAfterImport(): array
    {
        $existingRanges = [];

        foreach (File::glob(public_path('images/vehicles-dark/*')) ?: [] as $path) {
            $stem = mb_strtolower(pathinfo($path, PATHINFO_FILENAME));

            if (! preg_match('/^(.*)-(19\d{2}|20\d{2})-(19\d{2}|20\d{2})$/', $stem, $matches)) {
                continue;
            }

            $existingRanges[$matches[1]][] = [(int) $matches[2], (int) $matches[3]];
        }

        $missing = ConfiguratorProduct::query()
            ->where('category', 'screen')
            ->whereNotNull('brand')
            ->whereNotNull('model')
            ->whereNotNull('year_from')
            ->whereNotNull('year_to')
            ->get(['brand', 'model', 'year_from', 'year_to'])
            ->map(fn (ConfiguratorProduct $product) => [
                'brand' => trim((string) $product->brand),
                'model' => trim((string) $product->model),
                'year_from' => (int) $product->year_from,
                'year_to' => (int) $product->year_to,
                'stem' => $this->stem(
                    (string) $product->brand,
                    (string) $product->model,
                    (int) $product->year_from,
                    (int) $product->year_to,
                ),
            ])
            ->unique('stem')
            ->reject(function (array $vehicle) use ($existingRanges) {
                $ranges = collect($this->imageBases(
                    $vehicle['brand'],
                    $vehicle['model'],
                    $vehicle['year_from'],
                ))
                    ->flatMap(fn (string $base) => $existingRanges[$base] ?? [])
                    ->all();

                return $this->isCovered($ranges, $vehicle['year_from'], $vehicle['year_to']);
            })
            ->values();

        File::ensureDirectoryExists(storage_path('app'));
        File::put(
            storage_path('app/vehicle-images-pending.json'),
            json_encode($missing->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        $queued = 0;

        if (filled(config('services.openai.api_key'))) {
            foreach ($missing as $vehicle) {
                GenerateVehicleImage::dispatch($vehicle);
                $queued++;
            }
        }

        return ['missing' => $missing->count(), 'queued' => $queued];
    }

    public function stem(string $brand, string $model, int $yearFrom, int $yearTo): string
    {
        return Str::slug($brand.'-'.$model).'-'.$yearFrom.'-'.$yearTo;
    }

    /**
     * Include the same chassis aliases already understood by the configurator.
     *
     * @return array<int, string>
     */
    private function imageBases(string $brand, string $model, int $yearFrom): array
    {
        $brandSlug = Str::slug($brand);
        $modelSlug = Str::slug($model);
        $bases = [$brandSlug.'-'.$modelSlug];
        $familySlug = preg_replace('/-\d{2,3}$/', '', $modelSlug);

        if (is_string($familySlug) && $familySlug !== $modelSlug) {
            $bases[] = $brandSlug.'-'.$familySlug;

            $familyName = Str::afterLast($familySlug, '-');
            if ($familyName !== $familySlug) {
                $bases[] = $brandSlug.'-'.$familyName;
            }
        }

        if ($brandSlug !== 'bmw') {
            return $bases;
        }

        $alias = match ($modelSlug) {
            'm3' => 'e46',
            'serie-3' => 'e90',
            'serie-5' => 'e60',
            'serie-7' => 'e28',
            'serie-1' => $yearFrom >= 2011 ? 'f20' : 'e81',
            default => null,
        };

        if ($alias !== null) {
            $bases[] = $brandSlug.'-'.$alias;
        }

        return $bases;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    private function isCovered(array $ranges, int $yearFrom, int $yearTo): bool
    {
        usort($ranges, fn (array $left, array $right) => $left[0] <=> $right[0]);
        $nextUncoveredYear = $yearFrom;

        foreach ($ranges as [$rangeFrom, $rangeTo]) {
            if ($rangeTo < $nextUncoveredYear) {
                continue;
            }

            if ($rangeFrom > $nextUncoveredYear) {
                return false;
            }

            $nextUncoveredYear = max($nextUncoveredYear, $rangeTo + 1);

            if ($nextUncoveredYear > $yearTo) {
                return true;
            }
        }

        return $nextUncoveredYear > $yearTo;
    }
}
