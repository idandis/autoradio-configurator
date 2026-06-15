<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BrandsController extends Controller
{
    public function __invoke(): Response
    {
        $brands = ConfiguratorProduct::query()
            ->where('category', 'screen')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->select([
                'brand',
                DB::raw('COUNT(*) as products_count'),
                DB::raw('COUNT(DISTINCT model) as models_count'),
                DB::raw('MIN(year_from) as min_year'),
                DB::raw('MAX(year_to) as max_year'),
                DB::raw('MIN(price_min) as min_price'),
            ])
            ->groupBy('brand')
            ->orderBy('brand')
            ->get()
            ->map(fn ($brand) => [
                'brand' => $brand->brand,
                'products_count' => (int) $brand->products_count,
                'models_count' => (int) $brand->models_count,
                'min_year' => $brand->min_year ? (int) $brand->min_year : null,
                'max_year' => $brand->max_year ? (int) $brand->max_year : null,
                'min_price' => $brand->min_price,
            ]);

        return Inertia::render('Brands', [
            'brands' => $brands,
        ]);
    }
}
