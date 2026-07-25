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
        }

        $locale = $request->session()->get('locale', 'es');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'es';
            $request->session()->put('locale', $locale);
        }

        App::setLocale($locale);

        return $next($request);
    }
}
