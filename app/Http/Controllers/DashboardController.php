<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Services\StripePayments;
use App\Services\VehicleImageGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, VehicleImageGenerator $vehicleImageGenerator): Response
    {
        if ($request->session()->pull('italian_checkout_check_requested', false)) {
            try {
                $exitCode = Artisan::call('italian-payments:check', [
                    '--stripe' => StripePayments::configured(),
                    '--no-interaction' => true,
                ]);
                $request->session()->put('italian_checkout_report', [
                    'passed' => $exitCode === 0,
                    'lines' => preg_split('/\R/', trim(Artisan::output())),
                    'checkedAt' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
                $request->session()->put('italian_checkout_report', [
                    'passed' => false,
                    'lines' => ['Verifica non completata. Riprova con il pulsante del checkout Italia.'],
                    'checkedAt' => now()->toIso8601String(),
                ]);
            }
        }

        $translationTasks = ConfiguratorProduct::query()
            ->whereIn('category', ['screen', 'camera', 'speaker'])
            ->where(function ($query) {
                $query->where(fn ($query) => $query
                ->whereNull('title_it')
                ->orWhere('title_it', '')
                ->orWhereNull('title_en')
                ->orWhere('title_en', ''))
                ->orWhere(function ($query) {
                    $query->whereNotNull('body_html')->where('body_html', '!=', '')
                        ->where(fn ($query) => $query->whereNull('body_html_it')->orWhere('body_html_it', '')
                            ->orWhereNull('body_html_en')->orWhere('body_html_en', ''));
                });
            })
            ->orderBy('category')
            ->orderBy('handle')
            ->get(['handle', 'category', 'title', 'title_it', 'title_en', 'body_html', 'body_html_it', 'body_html_en', 'brand', 'model', 'year_from', 'year_to']);
        $titleTranslationCount = $translationTasks->filter(fn ($product) => blank($product->title_it) || blank($product->title_en))->count();
        $descriptionTranslationCount = $translationTasks->filter(fn ($product) => filled($product->body_html)
            && (blank($product->body_html_it) || blank($product->body_html_en)))->count();
        $imageTasks = $vehicleImageGenerator->missingVehicles();
        $vehicleDataIssues = $vehicleImageGenerator->unresolvedVehicleProducts();
        $prompt = $this->postImportPrompt($translationTasks, $imageTasks, $vehicleDataIssues);
        $fingerprint = hash('sha256', $prompt);
        $hasTasks = $translationTasks->isNotEmpty() || $imageTasks->isNotEmpty() || $vehicleDataIssues->isNotEmpty();
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
                'titleTranslationCount' => $titleTranslationCount,
                'descriptionTranslationCount' => $descriptionTranslationCount,
                'imageCount' => $imageTasks->count(),
                'vehicleDataIssueCount' => $vehicleDataIssues->count(),
                'prompt' => $prompt,
                'fingerprint' => $fingerprint,
                'dismissed' => $isDismissed,
            ],
            'flashStatus' => session('status'),
            'italianCheckoutReport' => $request->session()->get('italian_checkout_report'),
        ]);
    }

    private function postImportPrompt($translationTasks, $imageTasks, $vehicleDataIssues): string
    {
        $lines = [
            'Nel progetto autoradio-configurator completa queste attività post-importazione.',
            '',
            'TRADUZIONI TITOLI',
            'Per ogni prodotto elencato traduci il titolo spagnolo nelle lingue mancanti. Conserva marche, modelli, anni, pollici, RAM, memoria e sigle tecniche. Crea o aggiorna resources/data/{category}-titles-{locale}.json usando l’handle come chiave e il formato {"source":"titolo ES","translation":"titolo tradotto"}. Se il prodotto esiste anche nel database locale, aggiorna title_it/title_en. Non modificare mai il titolo spagnolo originale.',
            'TRADUZIONI DESCRIZIONI',
            'Traduci in italiano e inglese ogni descrizione HTML spagnola mancante. Mantieni struttura e tag HTML, link, specifiche, dati tecnici e significato; non aggiungere caratteristiche. Crea o aggiorna resources/data/{category}-descriptions-{locale}.json usando l’handle e il formato {"source":"descrizione HTML ES","translation":"descrizione HTML tradotta"}. Aggiorna body_html_it/body_html_en nel database locale senza modificare body_html originale.',
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
                    '- [%s] %s | lingue mancanti titolo: %s | titolo ES: %s',
                    $product->category,
                    $product->handle,
                    $missing,
                    $product->title,
                );
                if (filled($product->body_html) && (blank($product->body_html_it) || blank($product->body_html_en))) {
                    $descriptionMissing = collect(['it' => $product->body_html_it, 'en' => $product->body_html_en])
                        ->filter(fn ($description) => blank($description))->keys()->map(fn ($locale) => mb_strtoupper($locale))->implode(', ');
                    $lines[] = sprintf('- descrizione lingue mancanti: %s | descrizione HTML ES: %s', $descriptionMissing, $product->body_html);
                }
            }
        }

        $lines[] = '';
        $lines[] = 'IMMAGINI AUTO';
        $lines[] = 'Per ogni veicolo elencato genera con la skill imagegen una fotografia realistica dell’auto corretta per marca, generazione di carrozzeria e anni: vista anteriore a tre quarti, automobile intera e centrata, ombra naturale, sfondo uniforme #121212, nessun testo, nessuna persona. Salva in formato WEBP nel percorso indicato. Genera una sola immagine per la stessa generazione e riusala per facelift o differenze minime; crea una nuova immagine solo quando cambia radicalmente la carrozzeria. Le sigle di telaio servono esclusivamente a identificare la generazione. I veicoli estratti da prodotti multimarche sono elencati singolarmente e richiedono un’immagine per ciascuna carrozzeria.';

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

        if ($vehicleDataIssues->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'DATI VEICOLO NON INTERPRETABILI';
            $lines[] = 'Correggi gli abbinamenti marca/modello di questi prodotti prima di generare immagini; non inventare associazioni.';
            foreach ($vehicleDataIssues as $product) {
                $lines[] = sprintf(
                    '- %s | marca: %s | modello: %s | anni: %s–%s',
                    $product['handle'],
                    $product['brand'],
                    $product['model'],
                    $product['year_from'] ?? '?',
                    $product['year_to'] ?? '?',
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Per la prima traduzione massiva traduci tutte le descrizioni presenti nel database, non solo quelle dell’ultimo import; prepara i cataloghi JSON italiano e inglese. Verifica anche che gli import successivi salvino la descrizione originale e conservino le traduzioni se il testo sorgente non cambia. Alla fine verifica JSON e immagini, esegui i controlli pertinenti e indicami esattamente quali file devo caricare su Aruba. Dopo il caricamento premerò “Aggiorna database” per importare titoli e descrizioni tradotti senza SSH; le attività completate dovranno sparire dalla Dashboard.';

        return implode("\n", $lines);
    }
}
