<?php

namespace App\Http\Middleware;

use App\Models\ExtraEuVisitor;
use App\Services\VisitorGeolocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BlockOutsideEurope
{
    private const EUROPEAN_COUNTRIES = [
        'AD', 'AL', 'AM', 'AT', 'AZ', 'BA', 'BE', 'BG', 'BY', 'CH', 'CY', 'CZ',
        'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GE', 'GG', 'GI', 'GR',
        'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC',
        'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE',
        'SI', 'SK', 'SM', 'TR', 'UA', 'VA', 'XK',
    ];

    public function __construct(private readonly VisitorGeolocation $visitorGeolocation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $countryCode = $this->visitorGeolocation->countryCode($request);

        if ($countryCode !== null && ! in_array($countryCode, self::EUROPEAN_COUNTRIES, true)) {
            $this->recordExtraEuVisitor($request, $countryCode);

            // Keep extra-EU traffic out of the regular visitor/conversion statistics.
            if ($request->routeIs('configurator.statistics.store')) {
                return response()->noContent();
            }

            // The blocking page is retained for possible future use, but disabled by default.
            if (config('geography.block_outside_europe', false)) {
                return response()->view('area-not-served', status: 451);
            }
        }

        return $next($request);
    }

    private function recordExtraEuVisitor(Request $request, string $countryCode): void
    {
        try {
            $geography = $this->visitorGeolocation->locate($request);
            $fingerprint = hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
            $visitor = ExtraEuVisitor::query()->firstOrNew(['fingerprint' => $fingerprint]);

            $visitor->fill([
                'country_code' => $countryCode,
                'region' => $geography['region'] ?? $visitor->region,
                'city' => $geography['city'] ?? $visitor->city,
                'device_type' => $this->deviceType((string) $request->userAgent()),
                'browser_language' => mb_substr((string) $request->header('Accept-Language'), 0, 100) ?: null,
                'referrer' => mb_substr((string) $request->header('Referer'), 0, 2000) ?: null,
                'requested_path' => mb_substr($request->getPathInfo(), 0, 500),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000) ?: null,
                'hits' => $visitor->exists ? $visitor->hits + 1 : 1,
                'first_seen_at' => $visitor->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deviceType(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        if (preg_match('/ipad|tablet|kindle|silk/i', $userAgent)) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|ipod|android/i', $userAgent) ? 'mobile' : 'desktop';
    }
}
