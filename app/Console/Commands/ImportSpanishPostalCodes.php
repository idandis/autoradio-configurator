<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class ImportSpanishPostalCodes extends Command
{
    protected $signature = 'postal-codes:import-geonames {file=database/data/ES.zip}';

    protected $description = 'Import and aggregate Spanish postal codes from the GeoNames ES dataset';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $contents = str_ends_with(mb_strtolower($path), '.zip')
            ? $this->readZip($path)
            : file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read the GeoNames dataset.');
        }

        $postalCodes = [];

        foreach (preg_split('/\R/u', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $columns = explode("\t", $line);

            if (count($columns) < 12 || $columns[0] !== 'ES' || ! preg_match('/^\d{5}$/', $columns[1])) {
                continue;
            }

            $postalCode = $columns[1];
            $latitude = filter_var($columns[9], FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($columns[10], FILTER_VALIDATE_FLOAT);

            if ($latitude === false || $longitude === false) {
                continue;
            }

            $postalCodes[$postalCode] ??= [
                'postal_code' => $postalCode,
                'place_name' => trim($columns[2]) ?: null,
                'province' => trim($columns[5]) ?: null,
                'autonomous_community' => trim($columns[3]) ?: null,
                'localities' => [],
                'latitude_sum' => 0.0,
                'longitude_sum' => 0.0,
                'coordinates_count' => 0,
                'accuracy' => null,
            ];

            if (trim($columns[2]) !== '') {
                $postalCodes[$postalCode]['localities'][] = trim($columns[2]);
            }

            $postalCodes[$postalCode]['latitude_sum'] += (float) $latitude;
            $postalCodes[$postalCode]['longitude_sum'] += (float) $longitude;
            $postalCodes[$postalCode]['coordinates_count']++;
            $postalCodes[$postalCode]['accuracy'] = max(
                $postalCodes[$postalCode]['accuracy'] ?? 0,
                is_numeric($columns[11]) ? (int) $columns[11] : 0,
            ) ?: null;
        }

        $now = now();
        $rows = collect($postalCodes)->map(function (array $postalCode) use ($now) {
            $count = max(1, $postalCode['coordinates_count']);

            return [
                'postal_code' => $postalCode['postal_code'],
                'place_name' => $postalCode['place_name'],
                'province' => $postalCode['province'],
                'autonomous_community' => $postalCode['autonomous_community'],
                'island' => $this->canaryIsland($postalCode['postal_code']),
                'localities' => json_encode(array_values(array_unique($postalCode['localities'])), JSON_UNESCAPED_UNICODE),
                'latitude' => round($postalCode['latitude_sum'] / $count, 7),
                'longitude' => round($postalCode['longitude_sum'] / $count, 7),
                'accuracy' => $postalCode['accuracy'],
                'source' => 'GeoNames CC BY 4.0',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values();

        DB::transaction(function () use ($rows): void {
            DB::table('spanish_postal_codes')->delete();
            $rows->chunk(500)->each(fn ($chunk) => DB::table('spanish_postal_codes')->insert($chunk->all()));
        });

        $this->info(sprintf('Imported %d unique Spanish postal codes.', $rows->count()));

        return self::SUCCESS;
    }

    private function readZip(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the GeoNames ZIP archive.');
        }

        $contents = $zip->getFromName('ES.txt');
        $zip->close();

        if (! is_string($contents)) {
            throw new RuntimeException('ES.txt was not found in the GeoNames ZIP archive.');
        }

        return $contents;
    }

    private function canaryIsland(string $postalCode): ?string
    {
        return match (substr($postalCode, 0, 3)) {
            '350', '351', '352', '353', '354' => 'Gran Canaria',
            '355' => 'Lanzarote',
            '356' => 'Fuerteventura',
            '380', '381', '382', '383', '384', '385', '386' => 'Tenerife',
            '387' => 'La Palma',
            '388' => 'La Gomera',
            '389' => 'El Hierro',
            default => null,
        };
    }
}
