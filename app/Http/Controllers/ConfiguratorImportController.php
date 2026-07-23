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
            'catalog' => ['required', 'file', 'mimes:csv,txt,xls,xlsx'],
            'mode' => ['nullable', 'in:replace,add'],
        ]);

        $mode = $validated['mode'] ?? 'replace';

        $stats = $importer->import(
            $validated['catalog'],
            replaceExistingDataset: $mode === 'replace',
        );

        return back()->with('status', sprintf(
            'Import %s completato: %d schermi, %d camere, %d altoparlanti, %d installazioni, %d varianti.',
            $mode === 'replace' ? 'con sostituzione completa' : 'in aggiunta/aggiornamento',
            $stats['screen_products'],
            $stats['camera_products'],
            $stats['speaker_products'],
            $stats['installation_products'],
            $stats['variants'],
        ));
    }
}
