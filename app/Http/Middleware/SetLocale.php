<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['es', 'it', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requestedLocale = $request->query('lang');

        if (is_string($requestedLocale) && in_array($requestedLocale, self::SUPPORTED_LOCALES, true)) {
            $request->session()->put('locale', $requestedLocale);
        } elseif ($referrerLocale = $this->localeFromStorefrontReferrer($request->headers->get('referer'))) {
            $request->session()->put('locale', $referrerLocale);
        } elseif (! in_array($request->session()->get('locale'), self::SUPPORTED_LOCALES, true)) {
            $request->session()->put(
                'locale',
                $request->getPreferredLanguage(self::SUPPORTED_LOCALES) ?? 'es',
            );
        }

        $locale = $request->session()->get('locale', 'es');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'es';
            $request->session()->put('locale', $locale);
        }

        App::setLocale($locale);

        return $next($request);
    }

    private function localeFromStorefrontReferrer(?string $referrer): ?string
    {
        if (! $referrer || ! filter_var($referrer, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if (! in_array($host, ['autoradiocanario.com', 'www.autoradiocanario.com'], true)) {
            return null;
        }

        $path = '/'.ltrim((string) parse_url($referrer, PHP_URL_PATH), '/');

        if (preg_match('#^/it(?:/|$)#i', $path)) {
            return 'it';
        }

        if (preg_match('#^/en(?:/|$)#i', $path)) {
            return 'en';
        }

        return null;
    }
}
