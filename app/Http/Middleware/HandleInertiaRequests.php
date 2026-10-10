<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $host = mb_strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Host', $request->getHost()))[0]));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $storeBrand = match ($host) {
            'autoradioitaliano.it', 'www.autoradioitaliano.it' => 'italiano',
            'autoradiocanario.com', 'www.autoradiocanario.com', 'config.autoradiocanario.com' => 'canario',
            default => app()->getLocale() === 'it' ? 'italiano' : 'canario',
        };

        return [
            ...parent::share($request),
            'storeBrand' => $storeBrand,
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state')
                || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
