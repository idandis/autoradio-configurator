<?php

namespace App\Http\Controllers;

use App\Services\ConfiguratorCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfiguratorImportController extends Controller
{
    public function store(Request $request, ConfiguratorCsvImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'catalog' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $stats = $importer->import($validated['catalog']);

        return back()->with('status', sprintf(
            'Import completato: %d schermi, %d camere, %d installazioni, %d varianti.',
            $stats['screen_products'],
            $stats['camera_products'],
            $stats['installation_products'],
            $stats['variants'],
        ));
    }
}
