<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationStatistic;
use App\Services\VisitorGeolocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConfigurationStatisticController extends Controller
{
    public function __construct(private readonly VisitorGeolocation $visitorGeolocation) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => ['nullable', 'uuid'],
            'event_type' => ['required', 'in:configurator_entered,quote_downloaded,checkout_clicked'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'product_type' => ['nullable', 'string', 'max:50'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'variant_title' => ['nullable', 'string', 'max:255'],
            'product_price' => ['nullable', 'numeric', 'between:0,99999999.99'],
            'configuration_value' => ['nullable', 'numeric', 'between:0,99999999.99'],
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

        if ($data['event_type'] === 'configurator_entered') {
            if (! filled($data['session_uuid'] ?? null)) {
                return response()->json(['message' => 'A visitor identifier is required.'], 422);
            }

            if ($this->isAutomatedTraffic($request)) {
                return response()->json(status: 204);
            }

            $existingVisitor = ConfigurationStatistic::query()
                ->where('session_uuid', $data['session_uuid'])
                ->where('event_type', 'configurator_entered')
                ->first();

            if ($existingVisitor) {
                if (! $existingVisitor->visit_key) {
                    $existingVisitor->visit_key = $this->visitKey($data['session_uuid']);
                }

                if (! $existingVisitor->country_code || ! $existingVisitor->region || ! $existingVisitor->city) {
                    $existingVisitor->fill(array_filter(
                        $this->geography($request),
                        fn ($value, string $key) => blank($existingVisitor->{$key}) && filled($value),
                        ARRAY_FILTER_USE_BOTH,
                    ));
                }

                $existingVisitor->save();

                return response()->json(status: 204);
            }

            // A browser session can occasionally issue the mount request twice
            // with two freshly generated UUIDs. Collapse that short burst before
            // doing an external geolocation lookup.
            $browserSessionKey = 'configuration-statistic:entry-session:'.hash_hmac(
                'sha256',
                $request->session()->getId(),
                (string) config('app.key'),
            );
            if (! Cache::add($browserSessionKey, true, now()->addSeconds(30))) {
                return response()->json(status: 204);
            }

            $visitKey = $this->visitKey($data['session_uuid']);
            $data = array_merge($data, $this->geography($request), ['visit_key' => $visitKey]);
            $visitor = ConfigurationStatistic::firstOrCreate(['visit_key' => $visitKey], $data);

            return response()->json(status: $visitor->wasRecentlyCreated ? 201 : 204);
        }

        $configurationKey = hash('sha256', json_encode([
            'session_uuid' => $data['session_uuid'] ?? null,
            'event_type' => $data['event_type'],
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'variant_id' => $data['variant_id'] ?? null,
            'product_type' => $data['product_type'] ?? null,
            'configuration_value' => $data['configuration_value'] ?? null,
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
            ->where('product_type', $data['product_type'] ?? null)
            ->where('configuration_value', $data['configuration_value'] ?? null)
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

    private function geography(Request $request): array
    {
        $geography = $this->visitorGeolocation->locate($request);

        return [
            'country_code' => $geography['country_code'] ?? null,
            'region' => $geography['region'] ?? null,
            'city' => $geography['city'] ?? null,
        ];
    }

    private function visitKey(string $sessionUuid): string
    {
        return hash_hmac('sha256', $sessionUuid, (string) config('app.key'));
    }

    private function isAutomatedTraffic(Request $request): bool
    {
        $userAgent = strtolower((string) $request->userAgent());

        if ($userAgent === '') {
            return true;
        }

        return preg_match(
            '/(?:bot|crawler|spider|slurp|headless|lighthouse|pagespeed|uptime|monitoring|healthcheck|curl|wget|python-requests|guzzlehttp|go-http-client|postmanruntime|facebookexternalhit|whatsapp|telegrambot|discordbot)/i',
            $userAgent,
        ) === 1;
    }
}
