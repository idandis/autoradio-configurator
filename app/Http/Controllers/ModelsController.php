<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ModelsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $brand = trim((string) $request->query('brand')) ?: null;
        $year = filter_var($request->query('year'), FILTER_VALIDATE_INT) ?: null;

        $baseQuery = ConfiguratorProduct::query()
            ->where('category', 'screen')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->whereNotNull('model')
            ->where('model', '!=', '');

        $brands = (clone $baseQuery)
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values();

        $yearBounds = (clone $baseQuery)
            ->selectRaw('MIN(year_from) as min_year, MAX(year_to) as max_year')
            ->first();

        $models = (clone $baseQuery)
            ->when($brand, fn ($query) => $query->where('brand', $brand))
            ->when($year, fn ($query) => $query
                ->where('year_from', '<=', $year)
                ->where('year_to', '>=', $year))
            ->select([
                'brand',
                'model',
                DB::raw('COUNT(*) as products_count'),
                DB::raw('MIN(year_from) as min_year'),
                DB::raw('MAX(year_to) as max_year'),
                DB::raw('MIN(price_min) as min_price'),
            ])
            ->groupBy('brand', 'model')
            ->orderBy('brand')
            ->orderBy('model')
            ->get()
            ->map(fn ($model) => [
                'brand' => $model->brand,
                'model' => $model->model,
                'products_count' => (int) $model->products_count,
                'min_year' => $model->min_year ? (int) $model->min_year : null,
                'max_year' => $model->max_year ? (int) $model->max_year : null,
                'min_price' => $model->min_price,
            ]);

        return Inertia::render('Models', [
            'models' => $models,
            'brands' => $brands,
            'years' => ($yearBounds?->min_year && $yearBounds?->max_year)
                ? range((int) $yearBounds->min_year, (int) $yearBounds->max_year)
                : [],
            'filters' => [
                'brand' => $brand,
                'year' => $year,
            ],
        ]);
    }

    public function edit(Request $request): Response
    {
        $brand = trim((string) $request->query('brand'));
        $model = trim((string) $request->query('model'));

        abort_if($brand === '' || $model === '', 404);

        $record = ConfiguratorProduct::query()
            ->where('category', 'screen')
            ->where('brand', $brand)
            ->where('model', $model)
            ->selectRaw('brand, model, MIN(year_from) as year_from, MAX(year_to) as year_to, COUNT(*) as products_count')
            ->groupBy('brand', 'model')
            ->firstOrFail();

        return Inertia::render('Models/Edit', [
            'vehicleModel' => [
                'brand' => $record->brand,
                'model' => $record->model,
                'year_from' => (int) $record->year_from,
                'year_to' => (int) $record->year_to,
                'products_count' => (int) $record->products_count,
                'image_url' => $this->findVehicleImage(
                    $record->brand,
                    $record->model,
                    (int) $record->year_from,
                    (int) $record->year_to,
                ),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_brand' => ['required', 'string', 'max:255'],
            'source_model' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'year_from' => ['required', 'integer', 'between:1900,2100'],
            'year_to' => ['required', 'integer', 'between:1900,2100', 'gte:year_from'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $products = ConfiguratorProduct::query()
            ->where('brand', $validated['source_brand'])
            ->where('model', $validated['source_model']);

        abort_if(! $products->exists(), 404);

        DB::transaction(fn () => $products->update([
            'brand' => trim($validated['brand']),
            'model' => trim($validated['model']),
            'year_from' => $validated['year_from'],
            'year_to' => $validated['year_to'],
            'updated_at' => now(),
        ]));

        if ($request->hasFile('image')) {
            $filename = sprintf(
                '%s-%s-%d-%d.%s',
                Str::slug($validated['brand']),
                Str::slug($validated['model']),
                $validated['year_from'],
                $validated['year_to'],
                $request->file('image')->getClientOriginalExtension(),
            );

            $request->file('image')->move(public_path('images/vehicles'), $filename);
        }

        return redirect()->route('models.index')->with('status', 'Modello aggiornato correttamente.');
    }

    private function findVehicleImage(string $brand, string $model, int $yearFrom, int $yearTo): ?string
    {
        $stem = Str::slug($brand).'-'.Str::slug($model).'-'.$yearFrom.'-'.$yearTo;

        foreach (glob(public_path('images/vehicles/'.$stem.'.{png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [] as $path) {
            return '/images/vehicles/'.rawurlencode(basename($path));
        }

        return null;
    }
}
