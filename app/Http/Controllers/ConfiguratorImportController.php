<?php

namespace App\Http\Controllers;

use App\Services\ConfiguratorCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ConfiguratorImportController extends Controller
{
    public function store(Request $request, ConfiguratorCsvImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'catalog' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:65536'],
            'mode' => ['nullable', 'in:replace,add'],
        ], [
            'catalog.uploaded' => 'Il catalogo non è stato caricato. Il file supera il limite consentito dal server.',
            'catalog.max' => 'Il catalogo non può superare 64 MB.',
            'catalog.mimes' => 'Il catalogo deve essere un file CSV, XLS o XLSX.',
        ]);

        $mode = $validated['mode'] ?? 'replace';

        try {
            $stats = $importer->import(
                $validated['catalog'],
                replaceExistingDataset: $mode === 'replace',
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors(['catalog' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', sprintf(
            'Import %s completato: %d schermi, %d camere, %d altoparlanti, %d varianti. Foto veicoli mancanti: %d; messe in coda: %d.',
            $mode === 'replace' ? 'con sostituzione completa' : 'in aggiunta/aggiornamento',
            $stats['screen_products'],
            $stats['camera_products'],
            $stats['speaker_products'],
            $stats['variants'],
            $stats['vehicle_images_missing'],
            $stats['vehicle_images_queued'],
        ));
    }
}
