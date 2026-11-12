<?php

namespace App\Http\Middleware;

use App\Services\ProductSheetProcessing;
use Closure;
use Illuminate\Http\Request;

class ControlProductSheetProcessing
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || !preg_match('~^/(autogenesi|imported-products)/(\d+)(/sheet)?(?:/(.*))?$~', $request->getPathInfo(), $m)) return $next($request);
        $catalog = $m[1] === 'imported-products'; $suffix = $m[4] ?? '';
        if (($catalog && empty($m[3])) || $suffix === 'stop-processing' || (!$suffix && !$request->isMethod('PATCH'))) return $next($request);
        $control = app(ProductSheetProcessing::class);
        try {
            $control->begin($catalog ? 'catalog' : 'draft', (int) $m[2], $request->header('X-Sheet-Epoch'));
            $response = $next($request);
            $control->check();
            return $response;
        } finally { $control->clear(); }
    }
}
