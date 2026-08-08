<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class VisitorGeolocation
{
    public function locate(Request $request): array
    {
        $ip = $this->publicIp($request);

        if ($ip === null) {
            return [];
        }

        $cacheKey = 'visitor-geolocation:'.hash_hmac('sha256', $ip, (string) config('app.key'));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->retry(1, 100, throw: false)
                ->get('https://ipwho.is/'.rawurlencode($ip), [
                    'fields' => 'success,country_code,region,city',
                ]);

            if (! $response->successful() || $response->json('success') !== true) {
                return [];
            }

            $geography = [
                'country_code' => $this->value($response->json('country_code'), 2),
                'region' => $this->value($response->json('region')),
                'city' => $this->value($response->json('city')),
            ];
            Cache::put($cacheKey, $geography, now()->addDays(30));

            return $geography;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function publicIp(Request $request): ?string
    {
        $forwardedFor = explode(',', (string) $request->header('X-Forwarded-For'));
        $candidates = [
            $request->header('CF-Connecting-IP'),
            $request->header('X-Real-IP'),
            ...$forwardedFor,
            $request->ip(),
        ];

        foreach ($candidates as $candidate) {
            $ip = trim((string) $candidate);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return null;
    }

    private function value(mixed $value, int $maxLength = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
