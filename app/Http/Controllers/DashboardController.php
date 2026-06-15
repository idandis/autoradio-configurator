<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'screens' => ConfiguratorProduct::where('category', 'screen')->count(),
                'cameras' => ConfiguratorProduct::where('category', 'camera')->count(),
                'installations' => ConfiguratorProduct::where('category', 'installation')->count(),
                'vehicles' => ConfiguratorProduct::where('category', 'screen')
                    ->select('brand', 'model')
                    ->distinct()
                    ->count(),
            ],
            'flashStatus' => session('status'),
        ]);
    }
}
