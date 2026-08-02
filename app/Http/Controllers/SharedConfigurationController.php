<?php

namespace App\Http\Controllers;

use App\Models\SharedConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedConfigurationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'configuration' => ['required', 'array'],
            'configuration.brand' => ['nullable', 'string', 'max:255'],
            'configuration.model' => ['nullable', 'string', 'max:255'],
            'configuration.year' => ['nullable', 'integer', 'between:1900,2100'],
            'configuration.screens' => ['present', 'array'],
            'configuration.screens.*.product' => ['required', 'string', 'max:255'],
            'configuration.screens.*.variant' => ['required', 'string', 'max:255'],
            'configuration.cameras' => ['present', 'array'],
            'configuration.cameras.*' => ['string', 'max:255'],
            'configuration.speakers' => ['present', 'array'],
            'configuration.speakers.*' => ['string', 'max:255'],
            'configuration.customProducts' => ['present', 'array'],
            'configuration.customProducts.*' => ['string', 'max:255'],
            'configuration.installation' => ['nullable', 'string', 'max:255'],
            'configuration.postalCode' => ['nullable', 'string', 'max:5'],
            'configuration.serviceZone' => ['nullable', 'string', 'max:50'],
            'configuration.precheck' => ['nullable', 'string', 'max:50'],
        ]);

        $sharedConfiguration = SharedConfiguration::create([
            'uuid' => (string) Str::uuid(),
            'configuration' => $validated['configuration'],
        ]);

        return response()->json([
            'uuid' => $sharedConfiguration->uuid,
        ], 201);
    }
}
