<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $islandZones = ['gran canaria', 'tenerife', 'fuerteventura', 'lanzarote'];
        $cityZones = ['madrid', 'barcelona', 'malaga', 'murcia'];
        $postalCodes = DB::table('spanish_postal_codes')
            ->get(['postal_code', 'place_name', 'island']);

        DB::table('installation_zones')->orderBy('id')->get(['id', 'name'])->each(
            function ($zone) use ($postalCodes, $islandZones, $cityZones): void {
                $zoneName = $this->normalize($zone->name);

                if (! in_array($zoneName, [...$islandZones, ...$cityZones], true)) {
                    return;
                }

                $matchingCodes = $postalCodes
                    ->filter(function ($postal) use ($zoneName, $islandZones): bool {
                        $candidate = in_array($zoneName, $islandZones, true)
                            ? $postal->island
                            : $postal->place_name;

                        return $this->normalize($candidate) === $zoneName;
                    })
                    ->pluck('postal_code')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                $matchingCodes->each(function (string $postalCode) use ($zone): void {
                    $alreadyCovered = DB::table('installation_zone_postal_codes')
                        ->where('installation_zone_id', $zone->id)
                        ->where('postal_code_from', '<=', $postalCode)
                        ->where('postal_code_to', '>=', $postalCode)
                        ->exists();

                    if (! $alreadyCovered) {
                        DB::table('installation_zone_postal_codes')->insert([
                            'installation_zone_id' => $zone->id,
                            'postal_code_from' => $postalCode,
                            'postal_code_to' => $postalCode,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
            },
        );
    }

    public function down(): void
    {
        // Data migration: keep postal-code assignments to avoid deleting later manual corrections.
    }

    private function normalize(?string $value): string
    {
        return Str::lower(Str::ascii(trim((string) $value)));
    }
};
