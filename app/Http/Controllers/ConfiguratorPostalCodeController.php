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

        if (! $postalCodeRecord) {
            return response()->json(['found' => false]);
        }

        $location = $postalCodeRecord->island;
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
            'postalCode' => $postalCodeRecord->postal_code,
            'placeName' => $postalCodeRecord->place_name,
            'province' => $postalCodeRecord->province,
            'island' => $postalCodeRecord->island,
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
}
