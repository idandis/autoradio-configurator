<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstallationZonesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('InstallationZones', [
            'zones' => InstallationZone::with(['postalCodes', 'products'])
                ->orderBy('name')
                ->get()
                ->map(fn (InstallationZone $zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'active' => $zone->active,
                    'postal_ranges' => $zone->postalCodes->map(fn ($range) => [
                        'from' => $range->postal_code_from,
                        'to' => $range->postal_code_to,
                    ])->values(),
                    'product_handles' => $zone->products->pluck('product_handle')->values(),
                ]),
            'installationProducts' => ConfiguratorProduct::query()
                ->where('category', 'installation')
                ->orderBy('title')
                ->get(['handle', 'title', 'subtype', 'price_min'])
                ->map(fn ($product) => [
                    'handle' => $product->handle,
                    'title' => $product->title,
                    'subtype' => $product->subtype,
                    'price' => (float) $product->price_min,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $zone = InstallationZone::create(['name' => $data['name'], 'active' => $data['active']]);
            $this->replaceRelations($zone, $data);
        });

        return back()->with('status', 'Zona di installazione creata.');
    }

    public function update(Request $request, InstallationZone $installationZone): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($installationZone, $data) {
            $installationZone->update(['name' => $data['name'], 'active' => $data['active']]);
            $this->replaceRelations($installationZone, $data);
        });

        return back()->with('status', 'Zona di installazione aggiornata.');
    }

    public function destroy(InstallationZone $installationZone): RedirectResponse
    {
        $installationZone->delete();

        return back()->with('status', 'Zona di installazione eliminata.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
            'postal_ranges' => ['required', 'string'],
            'product_handles' => ['required', 'array', 'min:1'],
            'product_handles.*' => ['required', 'string', 'exists:configurator_products,handle'],
        ]);

        $ranges = collect(preg_split('/\R/', $data['postal_ranges']))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(function (string $line) {
                if (! preg_match('/^(\d{5})(?:\s*-\s*(\d{5}))?$/', $line, $matches)) {
                    throw ValidationException::withMessages([
                        'postal_ranges' => "Il valore {$line} non è valido. Usa 35000 oppure 35000-35999.",
                    ]);
                }

                $from = $matches[1];
                $to = $matches[2] ?? $from;

                if ($from > $to) {
                    throw ValidationException::withMessages([
                        'postal_ranges' => "L'intervallo {$line} è invertito.",
                    ]);
                }

                return ['from' => $from, 'to' => $to];
            })->values()->all();

        if ($ranges === []) {
            throw ValidationException::withMessages(['postal_ranges' => 'Inserisci almeno un CAP.']);
        }

        $data['name'] = trim($data['name']);
        $data['ranges'] = $ranges;

        return $data;
    }

    private function replaceRelations(InstallationZone $zone, array $data): void
    {
        $zone->postalCodes()->delete();
        $zone->products()->delete();
        $zone->postalCodes()->createMany(collect($data['ranges'])->map(fn ($range) => [
            'postal_code_from' => $range['from'],
            'postal_code_to' => $range['to'],
        ])->all());
        $zone->products()->createMany(collect($data['product_handles'])->unique()->map(fn ($handle) => [
            'product_handle' => $handle,
        ])->values()->all());
    }
}
