<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VehicleImageResolver
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $catalog = null;

    /**
     * @return array<int, array{brand: string, model: string}>
     */
    public function vehicleEntries(?string $brandList, ?string $modelList): array
    {
        $brands = $this->fieldValues($brandList);
        $models = $this->fieldValues($modelList);

        // An indexed model that points past the available brands comes from an
        // incomplete multibrand import. Do not invent a wrong brand/model pair
        // (for example CITROEN 208 when brand 1 was actually PEUGEOT).
        $hasInvalidBrandIndex = collect($models)->contains(function (string $modelValue) use ($brands) {
            return preg_match('/^\s*(\d+)\s*[:：]\s*(.+?)\s*$/u', $modelValue, $matches)
                && ! isset($brands[(int) $matches[1] - 1]);
        });

        if ($hasInvalidBrandIndex) {
            return [];
        }

        return collect($models)
            ->flatMap(function (string $modelValue) use ($brands) {
                if (preg_match('/^\s*(\d+)\s*[:：]\s*(.+?)\s*$/u', $modelValue, $matches)) {
                    $brand = $brands[(int) $matches[1] - 1] ?? null;
                    $model = trim($matches[2]);

                    return $brand && $model ? [['brand' => $brand, 'model' => $model]] : [];
                }

                $model = trim((string) preg_replace('/^\s*\d+\s*[:：]\s*/u', '', $modelValue));

                return $model === '' ? [] : collect($brands)
                    ->map(fn (string $brand) => ['brand' => $brand, 'model' => $model]);
            })
            ->unique(fn (array $entry) => $this->normalize($entry['brand']).'|'.$this->normalize($entry['model']))
            ->values()
            ->all();
    }

    /**
     * Resolve one shared image per actual body generation. An explicit catalog
     * image deliberately ignores narrower product/facelift year ranges.
     *
     * @param array<int, string>|null $filenames
     */
    public function resolveFilename(string $brand, string $model, int $year, ?array $filenames = null): ?string
    {
        $filenames ??= $this->imageFilenames();
        $generation = $this->generation($brand, $model, $year, $year);
        $configuredImage = $generation['image'] ?? null;

        if (is_string($configuredImage) && in_array($configuredImage, $filenames, true)) {
            return $configuredImage;
        }

        $bases = $this->imageBases($brand, $model, $generation);
        $candidates = collect($filenames)->flatMap(function (string $filename) use ($bases, $year) {
            $stem = Str::slug(pathinfo($filename, PATHINFO_FILENAME));

            if (! preg_match('/^(.*)-(19\d{2}|20\d{2})-(19\d{2}|20\d{2})$/', $stem, $matches)) {
                return [];
            }

            $basePosition = array_search($matches[1], $bases, true);
            if ($basePosition === false || $year < (int) $matches[2] || $year > (int) $matches[3]) {
                return [];
            }

            return [[
                'filename' => $filename,
                'base_position' => $basePosition,
                'span' => (int) $matches[3] - (int) $matches[2],
            ]];
        });

        $candidate = $candidates
            ->sortBy(fn (array $candidate) => sprintf('%03d-%04d', $candidate['base_position'], $candidate['span']))
            ->first();

        return is_array($candidate) ? $candidate['filename'] : null;
    }

    /**
     * @return array{key: string, brand: string, model: string, year_from: int, year_to: int, stem: string, image: ?string, configured: bool}
     */
    public function generation(string $brand, string $model, int $fallbackYearFrom, int $fallbackYearTo): array
    {
        $brand = trim($brand);
        $model = trim((string) preg_replace('/^\s*\d+\s*[:：]\s*/u', '', $model));
        $configuredCandidates = collect($this->catalog())->filter(function (array $entry) use ($brand, $model) {
            if ($this->normalize((string) ($entry['brand'] ?? '')) !== $this->normalize($brand)) {
                return false;
            }

            return collect($entry['aliases'] ?? [])
                ->contains(fn ($alias) => $this->normalize((string) $alias) === $this->normalize($model));
        });
        $configured = $configuredCandidates->first(fn (array $entry) =>
            $fallbackYearFrom <= (int) $entry['year_to'] && $fallbackYearTo >= (int) $entry['year_from']
        ) ?? $configuredCandidates->first();

        if (is_array($configured)) {
            $key = (string) $configured['key'];
            $yearFrom = (int) $configured['year_from'];
            $yearTo = (int) $configured['year_to'];

            return [
                'key' => $key,
                'brand' => (string) ($configured['brand'] ?? $brand),
                'model' => (string) ($configured['model'] ?? $model),
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'stem' => $key.'-'.$yearFrom.'-'.$yearTo,
                'image' => filled($configured['image'] ?? null) ? (string) $configured['image'] : null,
                'configured' => true,
            ];
        }

        $key = Str::slug($brand.'-'.$model);

        return [
            'key' => $key,
            'brand' => $brand,
            'model' => $model,
            'year_from' => $fallbackYearFrom,
            'year_to' => $fallbackYearTo,
            'stem' => $key.'-'.$fallbackYearFrom.'-'.$fallbackYearTo,
            'image' => null,
            'configured' => false,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>>|array<int, array<string, mixed>> $options
     * @param array<int, string>|null $filenames
     * @return array<int, array{brand: string, model: string, yearFrom: int, yearTo: int, image: string}>
     */
    public function mappings(Collection|array $options, ?array $filenames = null): array
    {
        $filenames ??= $this->imageFilenames();
        $resolved = [];

        foreach ($options as $option) {
            $yearFrom = (int) ($option['yearFrom'] ?? 0);
            $yearTo = (int) ($option['yearTo'] ?? 0);
            if ($yearFrom <= 0 || $yearTo < $yearFrom) {
                continue;
            }

            foreach ($this->vehicleEntries($option['brand'] ?? null, $option['model'] ?? null) as $vehicle) {
                $rangeStart = null;
                $rangeImage = null;

                for ($year = $yearFrom; $year <= $yearTo; $year++) {
                    $image = $this->resolveFilename($vehicle['brand'], $vehicle['model'], $year, $filenames);

                    if ($image === $rangeImage) {
                        continue;
                    }

                    if ($rangeImage !== null && $rangeStart !== null) {
                        $resolved[] = $this->mapping($vehicle, $rangeStart, $year - 1, $rangeImage);
                    }

                    $rangeStart = $image === null ? null : $year;
                    $rangeImage = $image;
                }

                if ($rangeImage !== null && $rangeStart !== null) {
                    $resolved[] = $this->mapping($vehicle, $rangeStart, $yearTo, $rangeImage);
                }
            }
        }

        return collect($resolved)
            ->unique(fn (array $mapping) => implode('|', $mapping))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function imageFilenames(): array
    {
        return collect(File::glob(public_path('images/vehicles-dark/*')) ?: [])
            ->map(fn (string $path) => basename($path))
            ->values()
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $path = resource_path('data/vehicle-image-generations.json');
        $decoded = File::exists($path)
            ? json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR)
            : [];

        return $this->catalog = collect(is_array($decoded) ? $decoded : [])
            ->map(function (array $entry, string $key) {
                $entry['key'] = $key;

                return $entry;
            })
            ->all();
    }

    /** @return array<int, string> */
    private function fieldValues(?string $value): array
    {
        return collect(explode('|', (string) $value))
            ->map(fn (string $part) => trim($part))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $generation */
    private function imageBases(string $brand, string $model, array $generation): array
    {
        $brandSlug = Str::slug($brand);
        $modelSlug = Str::slug($model);
        $familySlug = preg_replace('/-(?:[a-z]{1,3}\d{1,3}|mk\d+)$/i', '', $modelSlug) ?? $modelSlug;
        $bases = [
            $brandSlug.'-'.$modelSlug,
            (string) $generation['key'],
        ];

        if (! ($generation['configured'] ?? false)) {
            $bases[] = $brandSlug.'-'.$familySlug;
        }

        if ($brandSlug === 'bmw') {
            $alias = match ($modelSlug) {
                'm3' => 'e46',
                'serie-3' => 'e90',
                'serie-5' => 'e60',
                'serie-7' => 'e28',
                'serie-1' => ((int) $generation['year_from']) >= 2011 ? 'f20' : 'e81',
                default => null,
            };

            if ($alias !== null) {
                $bases[] = $brandSlug.'-'.$alias;
            }
        }

        return array_values(array_unique(array_filter($bases)));
    }

    /** @param array{brand: string, model: string} $vehicle */
    private function mapping(array $vehicle, int $yearFrom, int $yearTo, string $image): array
    {
        return [
            'brand' => $vehicle['brand'],
            'model' => $vehicle['model'],
            'yearFrom' => $yearFrom,
            'yearTo' => $yearTo,
            'image' => $image,
        ];
    }

    private function normalize(string $value): string
    {
        return Str::slug($value);
    }
}
