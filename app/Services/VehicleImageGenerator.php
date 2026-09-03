<?php

namespace App\Services;

use App\Jobs\GenerateVehicleImage;
use App\Models\ConfiguratorProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class VehicleImageGenerator
{
    public function __construct(private readonly VehicleImageResolver $resolver)
    {
    }

    /**
     * Detect missing vehicle images, persist a compact manifest and enqueue only
     * images that can actually be generated with the configured provider.
     *
     * @return array{missing: int, queued: int}
     */
    public function syncAfterImport(): array
    {
        $missing = $this->missingVehicles();

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

    /** @return Collection<int, array{brand: string, model: string, year_from: int, year_to: int, stem: string}> */
    public function missingVehicles(): Collection
    {
        $filenames = $this->resolver->imageFilenames();
        $generations = ConfiguratorProduct::query()
            ->where('category', 'screen')
            ->whereNotNull('brand')
            ->where('brand', 'not like', '%|%')
            ->whereNotNull('model')
            ->whereNotNull('year_from')
            ->whereNotNull('year_to')
            ->get(['brand', 'model', 'year_from', 'year_to'])
            ->flatMap(function (ConfiguratorProduct $product) {
                $yearFrom = (int) $product->year_from;
                $yearTo = (int) $product->year_to;

                return collect($this->resolver->vehicleEntries($product->brand, $product->model))
                    ->map(function (array $vehicle) use ($yearFrom, $yearTo) {
                        $generation = $this->resolver->generation(
                            $vehicle['brand'],
                            $vehicle['model'],
                            $yearFrom,
                            $yearTo,
                        );
                        $generation['source_model'] = $vehicle['model'];

                        return $generation;
                    });
            })
            ->unique('key')
            ->values();

        return $generations
            ->reject(function (array $generation) use ($filenames) {
                for ($year = $generation['year_from']; $year <= $generation['year_to']; $year++) {
                    if ($this->resolver->resolveFilename(
                        $generation['brand'],
                        $generation['source_model'],
                        $year,
                        $filenames,
                    ) === null) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (array $generation) => collect($generation)
                ->only(['brand', 'model', 'year_from', 'year_to', 'stem'])
                ->all())
            ->values();
    }
}
