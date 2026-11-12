<?php

namespace App\Http\Controllers;

use App\Services\AutogenesiPrice;
use App\Services\AutogenesiPriceRules;
use Illuminate\Http\Request;

class AutogenesiPriceRulesController extends Controller
{
    public function show(AutogenesiPriceRules $rules)
    {
        abort_unless(app()->environment('local'), 403);
        return response()->json($rules->current())->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, AutogenesiPriceRules $rules)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['ranges' => 'required|array|min:1|max:50', 'vat_rate' => 'sometimes|required|numeric|min:0|max:100|decimal:0,2']);
        return response()->json($rules->save($data['ranges'], $request->user()->id, $data['vat_rate'] ?? null));
    }

    public function preview(Request $request, AutogenesiPriceRules $rules, AutogenesiPrice $price)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['cost' => 'required|numeric|min:0.01|max:999999|decimal:0,2', 'ranges' => 'required|array|min:1|max:50', 'vat_rate' => 'sometimes|required|numeric|min:0|max:100|decimal:0,2']);
        return response()->json($price->quote(number_format((float) $data['cost'], 2, '.', ''), ['version' => 'preview', 'vat_rate' => $rules->vat($data['vat_rate'] ?? $rules->current()['vat_rate']), 'ranges' => $rules->validate($data['ranges'])]));
    }
}
