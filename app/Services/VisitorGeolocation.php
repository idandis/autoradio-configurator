<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class VisitorGeolocation
{
    public function countryCode(Request $request): ?string
    {
        $cloudflareCountry = $this->countryCodeFromHeaders($request);

        if ($cloudflareCountry !== null) {
            return $cloudflareCountry;
        }

        $countryCode = strtoupper(trim((string) ($this->locate($request)['country_code'] ?? '')));

        return preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null;
    }

    public function countryCodeFromHeaders(Request $request): ?string
    {
        $cloudflareCountry = strtoupper(trim((string) $request->header('CF-IPCountry')));

        if (preg_match('/^[A-Z]{2}$/', $cloudflareCountry) && ! in_array($cloudflareCountry, ['XX', 'T1'], true)) {
            return $cloudflareCountry;
        }

        return null;
    }

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

    public function publicIp(Request $request): ?string
    {
        $remoteAddress = trim((string) $request->server('REMOTE_ADDR'));
        $fromCloudflare = $this->isCloudflareIp($remoteAddress);

        // Cloudflare owns CF-Connecting-IP. Never accept it from an arbitrary
        // client, otherwise the analytics endpoint could be trivially spoofed.
        $candidates = $fromCloudflare
            ? [$request->header('CF-Connecting-IP'), ...$request->getClientIps()]
            : [$request->ip()];

        foreach ($candidates as $candidate) {
            $ip = trim((string) $candidate);
            if ($this->isPublicIp($ip) && ! $this->isCloudflareIp($ip)) {
                return $ip;
            }
        }

        return null;
    }

    public function isCloudflareIp(?string $ip): bool
    {
        if (! $this->isValidIp((string) $ip)) {
            return false;
        }

        foreach (config('cloudflare.proxies', []) as $network) {
            [$subnet, $prefix] = array_pad(explode('/', $network, 2), 2, null);
            $address = inet_pton((string) $ip);
            $subnetAddress = inet_pton($subnet);

            if ($address === false || $subnetAddress === false || strlen($address) !== strlen($subnetAddress)) {
                continue;
            }

            $bits = (int) ($prefix ?? (strlen($address) * 8));
            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if (substr($address, 0, $bytes) !== substr($subnetAddress, 0, $bytes)) {
                continue;
            }

            if ($remainder === 0 || ((ord($address[$bytes]) ^ ord($subnetAddress[$bytes])) & (0xff << (8 - $remainder))) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function value(mixed $value, int $maxLength = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
