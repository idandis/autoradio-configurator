<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\SpanishPostalCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ConfiguratorPostalCodeController extends Controller
{
    public function __invoke(string $postalCode): JsonResponse
    {
        abort_unless(preg_match('/^\d{5}$/', $postalCode), 404);

        $postalCodeRecord = SpanishPostalCode::query()
            ->where('postal_code', $postalCode)
            ->first();

        $location = $postalCodeRecord?->island
            ?? $this->canaryIsland($postalCode)
            ?? $this->installationLocation($postalCodeRecord?->province);

        if (! $postalCodeRecord && ! $location) {
            return response()->json(['found' => false]);
        }

        $productHandles = [];

        if ($location) {
            $normalizedLocation = $this->normalize($location);
            $productHandles = ConfiguratorProduct::query()
                ->where('category', 'installation')
                ->get(['handle', 'meta'])
                ->filter(fn (ConfiguratorProduct $product) => $this->normalize($product->meta['installation']['location'] ?? '') === $normalizedLocation
                )
                ->pluck('handle')
                ->values()
                ->all();
        }

        return response()->json([
            'found' => true,
            'postalCode' => $postalCode,
            'placeName' => $postalCodeRecord?->place_name,
            'province' => $postalCodeRecord?->province,
            'island' => $location,
            'installationArea' => $location ? [
                'name' => $location,
                'productHandles' => $productHandles,
            ] : null,
        ]);
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
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

    private function installationLocation(?string $province): ?string
    {
        if (! $province) {
            return null;
        }

        $normalizedProvince = $this->normalize($province);

        return ConfiguratorProduct::query()
            ->where('category', 'installation')
            ->get(['meta'])
            ->map(fn (ConfiguratorProduct $product) => $product->meta['installation']['location'] ?? null)
            ->filter()
            ->unique(fn (string $location) => $this->normalize($location))
            ->first(fn (string $location) => $this->normalize($location) === $normalizedProvince);
    }
}
