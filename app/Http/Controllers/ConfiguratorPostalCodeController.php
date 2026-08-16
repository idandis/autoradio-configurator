<?php

namespace App\Http\Controllers;

use App\Models\InstallationZone;
use App\Models\SpanishPostalCode;
use Illuminate\Http\JsonResponse;

class ConfiguratorPostalCodeController extends Controller
{
    public function __invoke(string $postalCode): JsonResponse
    {
        abort_unless(preg_match('/^\d{5}$/', $postalCode), 404);

        $configuredZone = InstallationZone::query()
            ->where('active', true)
            ->whereHas('postalCodes', fn ($query) => $query
                ->where('postal_code_from', '<=', $postalCode)
                ->where('postal_code_to', '>=', $postalCode))
            ->with('services')
            ->first();

        if ($configuredZone) {
            return response()->json([
                'found' => true,
                'postalCode' => $postalCode,
                'installationArea' => [
                    'name' => $configuredZone->name,
                    'productHandles' => $configuredZone->services->map(fn ($service) => 'zone-service-'.$service->id)->values(),
                    'productPrices' => $configuredZone->services->mapWithKeys(fn ($service) => ['zone-service-'.$service->id => (float) $service->price]),
                    'productTitles' => $configuredZone->services->mapWithKeys(fn ($service) => ['zone-service-'.$service->id => $service->name]),
                ],
            ]);
        }

        $postalCodeRecord = SpanishPostalCode::query()
            ->where('postal_code', $postalCode)
            ->first();

        $location = $postalCodeRecord?->island
            ?? $this->canaryIsland($postalCode);

        if (! $postalCodeRecord && ! $location) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'postalCode' => $postalCode,
            'placeName' => $postalCodeRecord?->place_name,
            'province' => $postalCodeRecord?->province,
            'island' => $location,
            'installationArea' => null,
        ]);
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
