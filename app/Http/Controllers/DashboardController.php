<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Services\VehicleImageGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, VehicleImageGenerator $vehicleImageGenerator): Response
    {
        $translationTasks = ConfiguratorProduct::query()
            ->whereIn('category', ['screen', 'camera', 'speaker'])
            ->where(fn ($query) => $query
                ->whereNull('title_it')
                ->orWhere('title_it', '')
                ->orWhereNull('title_en')
                ->orWhere('title_en', ''))
            ->orderBy('category')
            ->orderBy('handle')
            ->get(['handle', 'category', 'title', 'title_it', 'title_en', 'brand', 'model', 'year_from', 'year_to']);
        $imageTasks = $vehicleImageGenerator->missingVehicles();
        $prompt = $this->postImportPrompt($translationTasks, $imageTasks);
        $fingerprint = hash('sha256', $prompt);
        $hasTasks = $translationTasks->isNotEmpty() || $imageTasks->isNotEmpty();
        $isDismissed = $hasTasks && Cache::has('post-import-tasks:dismissed:'.$fingerprint);

        return Inertia::render('Dashboard', [
            'stats' => [
                'screens' => ConfiguratorProduct::where('category', 'screen')->count(),
                'cameras' => ConfiguratorProduct::where('category', 'camera')->count(),
                'speakers' => ConfiguratorProduct::where('category', 'speaker')->count(),
                'vehicles' => ConfiguratorProduct::where('category', 'screen')
                    ->select('brand', 'model')
                    ->distinct()
                    ->count(),
            ],
            'postImportTasks' => [
                'translationCount' => $translationTasks->count(),
                'imageCount' => $imageTasks->count(),
                'prompt' => $prompt,
                'fingerprint' => $fingerprint,
                'dismissed' => $isDismissed,
            ],
            'flashStatus' => session('status'),
        ]);
    }

    private function postImportPrompt($translationTasks, $imageTasks): string
    {
        $lines = [
            'Nel progetto autoradio-configurator completa queste attività post-importazione.',
            '',
            'TRADUZIONI TITOLI',
            'Per ogni prodotto elencato traduci il titolo spagnolo nelle lingue mancanti. Conserva marche, modelli, anni, pollici, RAM, memoria e sigle tecniche. Crea o aggiorna resources/data/{category}-titles-{locale}.json usando l’handle come chiave e il formato {"source":"titolo ES","translation":"titolo tradotto"}. Se il prodotto esiste anche nel database locale, aggiorna title_it/title_en. Non modificare mai il titolo spagnolo originale.',
        ];

        if ($translationTasks->isEmpty()) {
            $lines[] = '- Nessuna traduzione mancante.';
        } else {
            foreach ($translationTasks as $product) {
                $missing = collect(['it' => $product->title_it, 'en' => $product->title_en])
                    ->filter(fn ($title) => blank($title))
                    ->keys()
                    ->map(fn ($locale) => mb_strtoupper($locale))
                    ->implode(', ');
                $lines[] = sprintf(
                    '- [%s] %s | lingue mancanti: %s | titolo ES: %s',
                    $product->category,
                    $product->handle,
                    $missing,
                    $product->title,
                );
            }
        }

        $lines[] = '';
        $lines[] = 'IMMAGINI AUTO';
        $lines[] = 'Per ogni veicolo elencato genera con la skill imagegen una fotografia realistica dell’auto corretta per marca, generazione di carrozzeria e anni: vista anteriore a tre quarti, automobile intera e centrata, ombra naturale, sfondo uniforme #121212, nessun testo, nessuna persona. Salva in formato WEBP nel percorso indicato. Genera una sola immagine per la stessa generazione e riusala per facelift o differenze minime; crea una nuova immagine solo quando cambia radicalmente la carrozzeria. Le sigle di telaio servono esclusivamente a identificare la generazione; non creare immagini per prodotti con più marche.';

        if ($imageTasks->isEmpty()) {
            $lines[] = '- Nessuna immagine auto mancante.';
        } else {
            foreach ($imageTasks as $vehicle) {
                $lines[] = sprintf(
                    '- %s %s — %d–%d | public/images/vehicles-dark/%s.webp',
                    $vehicle['brand'],
                    $vehicle['model'],
                    $vehicle['year_from'],
                    $vehicle['year_to'],
                    $vehicle['stem'],
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Alla fine verifica JSON e immagini, esegui i controlli pertinenti e indicami esattamente quali file devo caricare su Aruba. Dopo il caricamento dovrò premere “Aggiorna database” per importare le traduzioni; le attività completate dovranno quindi sparire dalla Dashboard.';

        return implode("\n", $lines);
    }
}
