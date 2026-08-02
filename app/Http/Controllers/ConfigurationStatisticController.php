<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConfigurationStatisticController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => ['nullable', 'uuid'],
            'event_type' => ['required', 'in:quote_downloaded,checkout_clicked'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'variant_title' => ['nullable', 'string', 'max:255'],
            'product_price' => ['nullable', 'numeric', 'between:0,99999999.99'],
            'installation_selected' => ['required', 'boolean'],
            'installation_type' => ['nullable', 'string', 'max:255'],
            'camera_selected' => ['required', 'boolean'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'service_zone' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'in:desktop,tablet,mobile'],
        ]);

        $configurationKey = hash('sha256', json_encode([
            'session_uuid' => $data['session_uuid'] ?? null,
            'event_type' => $data['event_type'],
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'variant_id' => $data['variant_id'] ?? null,
            'installation_selected' => $data['installation_selected'],
            'installation_type' => $data['installation_type'] ?? null,
            'camera_selected' => $data['camera_selected'],
            'postal_code' => $data['postal_code'] ?? null,
            'service_zone' => $data['service_zone'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $hasSession = filled($data['session_uuid'] ?? null);

        if ($hasSession && ! Cache::add("configuration-statistic:{$configurationKey}", true, 10)) {
            return response()->json(status: 204);
        }

        $duplicateExists = $hasSession && ConfigurationStatistic::query()
            ->where('session_uuid', $data['session_uuid'] ?? null)
            ->where('event_type', $data['event_type'])
            ->where('brand', $data['brand'] ?? null)
            ->where('model', $data['model'] ?? null)
            ->where('year', $data['year'] ?? null)
            ->where('product_id', $data['product_id'] ?? null)
            ->where('variant_id', $data['variant_id'] ?? null)
            ->where('installation_selected', $data['installation_selected'])
            ->where('installation_type', $data['installation_type'] ?? null)
            ->where('camera_selected', $data['camera_selected'])
            ->where('postal_code', $data['postal_code'] ?? null)
            ->where('service_zone', $data['service_zone'] ?? null)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->exists();

        if ($duplicateExists) {
            return response()->json(status: 204);
        }

        ConfigurationStatistic::create($data);

        return response()->json(status: 201);
    }
}
