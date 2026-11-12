<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAutogenesiBatch;
use App\Models\AutogenesiBatch;
use App\Models\AutogenesiBatchItem;
use App\Models\AutogenesiProduct;
use App\Services\AutogenesiCatalogImport;
use App\Services\AutogenesiChromeImport;
use App\Services\AutogenesiDashboardImage;
use App\Services\AutogenesiExport;
use App\Services\AutogenesiImageCrop;
use App\Services\AutogenesiImageEditor;
use App\Services\AutogenesiImageUpload;
use App\Services\AutogenesiImport;
use App\Services\AutogenesiList;
use App\Services\AutogenesiRunControl;
use App\Services\AutogenesiSearch;
use App\Services\AutogenesiTemplateExport;
use App\Services\AutogenesiVariants;
use App\Services\ProductHtml;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AutogenesiController extends Controller
{
    public function index()
    {
        return Inertia::render('Autogenesi', ['reimportImagesResult' => session('reimport_images_result'), 'local' => app()->environment('local'), 'workerError' => session('errors')?->first('worker'), 'detection' => session('autogenesi_detection'), 'detectionMaxYear' => now()->year - 1, 'search' => session('autogenesi_search'), 'directImport' => session('autogenesi_direct_import'),
            'apiConnection' => app()->environment('local') ? app(\App\Services\AutogenesiAliExpressAuth::class)->status(auth()->id()) : null,
            'batches' => fn () => AutogenesiBatch::with('items')->latest()->limit(10)->get(),
            'products' => fn () => AutogenesiProduct::where('status', 'draft')->latest()->get()->map(function ($p) {
                $data = $p->toArray();
                $data['processing_epoch'] = app(\App\Services\ProductSheetProcessing::class)->epoch('draft', $p->id);
                $entries = $p->metadata['vehicle_compatibilities'] ?? [];
                if (!$entries) $entries = array_map(fn ($e) => $e + ['year_from' => $p->year_from, 'year_to' => $p->year_to], app(\App\Services\VehicleImageResolver::class)->vehicleEntries($p->brand, $p->model));
                $data['vehicle_groups'] = app(\App\Services\AutogenesiVehicleTransform::class)->groups($entries);
                unset($data['metadata']['source_text']);
                unset($data['metadata']['description_source']['html'], $data['metadata']['previous_copy'], $data['metadata']['capture_directory']);
                $data['images'] = array_map(fn ($image, $i) => ['url' => route('autogenesi.image', [$p, $i, 'v' => $image['sha256']]), 'id' => $image['sha256'],
                    'ai_edited' => (bool) ($image['ai_edited'] ?? false), 'image_reframed' => (bool) ($image['image_reframed'] ?? false), 'resolution_upscaled' => (bool) ($image['resolution_upscaled'] ?? false), 'manually_cropped' => (bool) (($image['manually_cropped'] ?? false) || ($image['manually_painted'] ?? false)),
                    'dashboard_selected' => in_array($image['sha256'], array_column(\App\Services\AutogenesiImageLayout::dashboards($p), 'sha256'), true), 'shopify_file_reference' => $image['shopify_file_reference'] ?? '',
                    'ai_generated' => (bool) ($image['ai_generated'] ?? false), 'web_sources' => $image['web_sources'] ?? [],
                    'local_only' => (bool) (($image['uploaded'] ?? false) || ($image['imported_at'] ?? false) || ($image['ai_generated'] ?? false) || ($image['ai_edited'] ?? false) || ($image['image_reframed'] ?? false) || ($image['resolution_upscaled'] ?? false) || ($image['square_cropped'] ?? false) || ($image['manually_painted'] ?? false)),
                    'role' => $image['role'], 'source_url' => $image['source_url'], 'width' => $image['width'], 'height' => $image['height']], $p->images, array_keys($p->images));

                return $data;
            })]);
    }

    public function detectMissing(Request $request, \App\Services\AutogenesiDetection $detector)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['mode' => 'sometimes|in:year,brand', 'brand' => ['required_if:mode,brand', 'nullable', 'string', 'max:80', 'not_regex:/[;|\r\n]/'], 'year' => 'required_unless:mode,brand|nullable|integer|min:1900|max:'.(now()->year - 1)]);
        try {
            $result = ($data['mode'] ?? 'year') === 'brand' ? $detector->detectBrand($data['brand']) : $detector->detect($data['year']);
            $request->session()->put('autogenesi_detection', $result);
            return back()->with('status', 'Rilevati '.count($result['cars']).' modelli: '.$result['counts']['missing'].' mancanti, pronti in Importa lista.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors([($data['mode'] ?? 'year') === 'brand' ? 'brand' : 'year' => $e instanceof \RuntimeException && !$e instanceof RequestException
                ? $e->getMessage() : 'Rilevamento non completato. Verifica la connessione e la configurazione OpenAI e riprova.']);
        }
    }

    public function importLog()
    {
        abort_unless(app()->environment('local'), 403);
        $disk = Storage::disk('local');
        abort_unless($disk->exists('autogenesi/diagnostics/import.log'), 404, 'Nessuna importazione registrata.');
        return response()->download($disk->path('autogenesi/diagnostics/import.log'), 'autogenesi-ultima-importazione.log', ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function store(Request $request, AutogenesiImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['source_url' => 'required|url|max:2000', 'brand' => 'required|string|max:80',
            'model' => 'required|string|max:120', 'year_from' => 'required|integer|min:1', 'year_to' => 'required|integer|gte:year_from']);
        try {
            app(AutogenesiRunControl::class)->activate();
            $p = $importer->capture($data['source_url'], $data);
            app(AutogenesiExport::class)->writeFiles($p);

            return back()->with('status', 'Bozza #'.$p->id.' importata.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['source_url' => str_contains($e->getMessage(), 'Network traffic blocked')
                ? 'AliExpress ha rilevato traffico insolito. Importazione fermata: riprova più tardi.'
                : (str_contains($e->getMessage(), 'Verification required')
                ? 'AliExpress richiede una verifica umana: importazione interrotta.'
                : 'Importazione non completata. Scarica il log dell’ultima importazione per vedere la fase e l’errore preciso.')]);
        }
    }

    public function search(Request $request, AutogenesiSearch $searcher)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['query' => 'required|string|max:2048', 'mode' => 'sometimes|in:links,best_price']);
        if ($url = $searcher->sourceUrl($data['query'])) {
            $request->session()->forget('autogenesi_search');
            $request->session()->put('autogenesi_direct_import', ['source_url' => $url]);

            return back();
        }
        $request->session()->forget('autogenesi_direct_import');
        try {
            app(AutogenesiRunControl::class)->activate();
            $result = $searcher->search($data['query'], $data['mode'] ?? 'links');
            $request->session()->put('autogenesi_search', $result);

            return back();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['query' => str_contains($e->getMessage(), 'Network traffic blocked')
                ? 'AliExpress ha rilevato traffico insolito. Ricerca fermata: riprova più tardi.'
                : (str_contains($e->getMessage(), 'Verification required')
                ? 'AliExpress richiede una verifica umana: ricerca automatica interrotta.'
                : 'Ricerca non completata: verifica marca, modello e anni (es. radio Ford Escort 2019 2020). La pagina potrebbe essere bloccata.')]);
        }
    }

    public function stopAll(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        app(AutogenesiRunControl::class)->stop();
        DB::transaction(function () {
            AutogenesiBatch::whereNotIn('status', ['completed', 'completed_with_errors'])->update(['status' => 'stopped']);
            AutogenesiBatchItem::whereIn('status', ['queued', 'searching', 'importing'])->update([
                'status' => 'stopped', 'error' => 'Interrotta manualmente.', 'finished_at' => now(),
            ]);
            DB::table('jobs')->where('queue', 'autogenesi')->delete();
        });
        $result = Process::timeout(12)->run(['node', base_path('scripts/autogenesi/stop-browser.cjs')]);
        if (! $result->successful()) {
            return $request->expectsJson() ? response()->json(['message' => 'Ricerche fermate. Chiudi manualmente Chrome AutoGenesi.'], 503) : back()->withErrors(['stop' => 'Ricerche fermate. Chiudi manualmente la finestra Chrome AutoGenesi.']);
        }

        return $request->expectsJson() ? response()->json(['stopped' => true]) : back()->with('status', 'Ricerche fermate e browser AutoGenesi chiuso.');
    }

    public function chromeExtension()
    {
        abort_unless(app()->environment('local'), 403);
        $disk = Storage::disk('local');
        $disk->makeDirectory('autogenesi/downloads');
        $path = $disk->path('autogenesi/downloads/autogenesi-chrome.zip');
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Creazione archivio non riuscita.');
        }
        foreach (['manifest.json', 'popup.html', 'popup.js', 'background.js', 'capture.js', 'generic.js', 'dsers.js', 'ISTRUZIONI.txt'] as $file) {
            $zip->addFile(base_path('scripts/autogenesi/chrome-extension/'.$file), $file);
        }
        $zip->close();

        return response()->download($path, 'autogenesi-chrome.zip');
    }

    public function importChrome(Request $request, AutogenesiChromeImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['package' => 'required|file|max:53248', 'brand' => 'required|string|max:80',
            'model' => 'required|string|max:120', 'year_from' => 'required|integer|min:1',
            'year_to' => 'required|integer|gte:year_from']);
        try {
            app(AutogenesiRunControl::class)->activate();
            $product = $importer->import($request->file('package'), Arr::except($data, ['package']));

            return back()->with('status', 'Bozza #'.$product->id.' importata dal tuo Chrome.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['package' => 'Pacchetto non importato: '.$e->getMessage()]);
        }
    }

    public function clearSearch(Request $request)
    {
        $request->session()->forget(['autogenesi_search', 'autogenesi_direct_import']);

        return back();
    }

    public function importList(Request $request, AutogenesiList $lists)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['list' => 'required|string|max:30000', 'engine' => 'sometimes|in:api,browser',
            'sort_by' => 'sometimes|in:price,sales,best_match', 'origin_policy' => 'sometimes|in:prefer_eu,eu,all']);
        if (($data['engine'] ?? 'browser') === 'api') {
            try { app(\App\Services\AutogenesiAliExpressAuth::class)->accessToken($request->user()->id); }
            catch (\RuntimeException $e) { return back()->withErrors(['engine' => $e->getMessage()]); }
        }
        app(AutogenesiRunControl::class)->activate();
        $batch = $lists->create($data['list'], $request->user()->id, $data['engine'] ?? 'browser', array_intersect_key($data, array_flip(['sort_by', 'origin_policy'])));
        app(\App\Services\AutogenesiWorker::class)->ensure();

        return back()->with('status', 'Lista #'.$batch->id.' avviata in background: '.$batch->total.' vetture.');
    }

    public function openBrowser()
    {
        abort_unless(app()->environment('local'), 403);
        $item = AutogenesiBatchItem::where('status', 'blocked')->latest()->first();
        $url = $item?->source_url ?: ($item ? 'https://es.aliexpress.com/w/wholesale-'.rawurlencode(str_replace(' ', '-', $item->query)).'.html?'.http_build_query(['SearchText' => $item->query, 'g' => 'y', 'sortType' => 'price_asc', 'shpf_co' => 'EU']) : 'https://es.aliexpress.com/');
        try {
            app(AutogenesiRunControl::class)->activate();
            Process::timeout(35)->run(['node', base_path('scripts/autogenesi/open-browser.cjs'), $url])->throw();

            return back()->with('status', 'Browser AutoGenesi aperto. Completa la verifica e lascia la finestra aperta.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['browser' => 'Non riesco ad aprire il browser AutoGenesi su questo Mac. Verifica che Chrome sia installato.']);
        }
    }

    public function retryList(AutogenesiBatch $batch)
    {
        abort_unless(app()->environment('local'), 403);
        DB::transaction(function () use ($batch) {
            $current = AutogenesiBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($current->status, ['blocked', 'completed_with_errors', 'paused', 'stopped'], true), 409);
            $current->items()->where('status', '!=', 'completed')->update(['status' => 'queued', 'error' => null, 'source_url' => null, 'started_at' => null, 'finished_at' => null]);
            $current->update(['status' => 'queued']);
            app(AutogenesiRunControl::class)->activate();
            ProcessAutogenesiBatch::dispatch($current->id)->afterCommit();
        });

        app(\App\Services\AutogenesiWorker::class)->ensure();
        return back()->with('status', 'Riprese le righe non completate.');
    }

    public function deleteBatchItems(AutogenesiBatch $batch, ?AutogenesiBatchItem $item = null)
    {
        abort_unless(app()->environment('local'), 403);
        abort_if($item && $item->batch_id !== $batch->id, 404);
        DB::transaction(function () use ($batch, $item) {
            $current = AutogenesiBatch::lockForUpdate()->findOrFail($batch->id);
            abort_if(in_array($current->status, ['queued', 'running', 'queued_single', 'running_single'], true), 409, 'Ferma la ricerca prima di cancellare le righe.');
            $current->items()->when($item, fn ($q) => $q->whereKey($item->id), fn ($q) => $q->where('status', 'completed'))->delete();
            $total = $current->items()->count();
            if (! $total) {
                $current->delete();
                return;
            }
            $status = $current->status;
            if (! $current->items()->where('status', '!=', 'completed')->exists()) $status = 'completed';
            elseif ($status === 'blocked' && ! $current->items()->where('status', 'blocked')->exists()) $status = 'paused';
            $current->update(['total' => $total, 'status' => $status]);
        });

        return back()->with('status', 'Righe eliminate dallo storico. I prodotti importati sono conservati.');
    }

    public function retryItem(AutogenesiBatch $batch, AutogenesiBatchItem $item)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless($item->batch_id === $batch->id, 404);
        DB::transaction(function () use ($batch, $item) {
            $current = AutogenesiBatch::lockForUpdate()->findOrFail($batch->id);
            abort_if(in_array($current->status, ['queued', 'running', 'queued_single', 'running_single'], true), 409, 'Una richiesta è già in elaborazione.');
            $row = $current->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($row->status, ['queued', 'failed', 'not_found', 'blocked', 'stopped'], true), 409);
            $row->update(['status' => 'queued', 'error' => null, 'source_url' => null, 'started_at' => null, 'finished_at' => null]);
            $current->update(['status' => 'queued_single']);
            app(AutogenesiRunControl::class)->activate();
            ProcessAutogenesiBatch::dispatch($current->id, $row->id)->afterCommit();
        });

        app(\App\Services\AutogenesiWorker::class)->ensure();
        return back()->with('status', 'Avviata solo la richiesta selezionata.');
    }

    public function destroy(AutogenesiProduct $product)
    {
        $paths = collect($product->images)->flatMap(fn ($image) => [$image['path'], $image['uncropped_path'] ?? null,
            $image['original']['path'] ?? null, $image['original']['uncropped_path'] ?? null])->filter()->unique()->all();
        $id = $product->id;
        $product->delete();
        Storage::disk('local')->delete($paths);
        Storage::disk('local')->deleteDirectory('autogenesi/exports/'.$id);

        return back()->with('status', 'Prodotto eliminato. Puoi importarlo nuovamente.');
    }

    public function regenerate(AutogenesiProduct $product, AutogenesiImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            app(AutogenesiRunControl::class)->activate();
            app(AutogenesiExport::class)->writeFiles($importer->regenerate($product));

            return back()->with('status', 'Descrizione originale riletta e scheda ricreata.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['description' => 'Lettura o generazione non completata: la scheda precedente è conservata.']);
        }
    }

    public function variantPrice(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['cost' => 'required|numeric|gt:0|max:999999|decimal:0,2']);
        return response()->json(app(\App\Services\AutogenesiPrice::class)->quote(number_format((float) $data['cost'], 2, '.', '')));
    }

    public function update(Request $request, AutogenesiProduct $product)
    {
        $catalog = $product instanceof \App\Models\CatalogProductSheet;
        $data = $request->validate(['title' => 'required|string|max:'.($catalog ? 255 : 2000), 'body_html' => ($catalog ? 'nullable' : 'required').'|string|max:150000',
            'additional_source_urls' => 'sometimes|array|max:30', 'additional_source_urls.*' => ['nullable', 'url:http,https', 'max:4096', function ($attribute, $value, $fail) { if ($value && (parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_PASS))) $fail('Inserisci un link senza credenziali.'); }],
            'vehicles' => 'sometimes|required|array|min:1|max:100', 'vehicles.*.brand' => 'required|string|max:255', 'vehicles.*.model' => 'required|string|max:4000',
            'vehicles.*.year_from' => 'required|integer|min:1|max:65535', 'vehicles.*.year_to' => 'required|integer|gte:vehicles.*.year_from|max:65535',
            'variants' => 'required|array|size:'.count($product->variants), 'variants.*.sku' => 'sometimes|required|string', 'variants.*.label' => 'sometimes|required|string|max:500',
            'variants.*.supplier_price' => 'sometimes|'.($catalog ? 'nullable' : 'required').'|numeric|gt:0|max:999999|decimal:0,2', 'variants.*.selling_price' => 'nullable|numeric|gt:0|max:999999']);
        $variants = $product->variants;
        foreach ($variants as $i => &$variant) {
            if (isset($data['variants'][$i]['sku']) && $data['variants'][$i]['sku'] !== $variant['sku']) {
                throw ValidationException::withMessages(['variants' => 'Le varianti sono cambiate: riapri la scheda prima di salvare.']);
            }
            if (isset($data['variants'][$i]['label']) && $data['variants'][$i]['label'] !== $variant['label']) {
                $variant['label'] = trim($data['variants'][$i]['label']);
                $variant['label_edited'] = true;
            }
            $price = $data['variants'][$i]['selling_price'] ?? null;
            $cost = isset($data['variants'][$i]['supplier_price']) ? number_format((float) $data['variants'][$i]['supplier_price'], 2, '.', '') : $variant['supplier_price'];
            if ($cost !== $variant['supplier_price'] && $cost !== null) {
                $variant['observed_supplier_price'] ??= $variant['supplier_price'];
                $variant['supplier_price'] = $cost;
                $variant['supplier_price_edited'] = true;
                $variant['supplier_price_edited_at'] = now()->toIso8601String();
                $useRule = !$catalog || app(\App\Services\CatalogPriceUpdater::class)->isRadio(\App\Models\ConfiguratorProduct::findOrFail($product->id));
                $variant['pricing'] = $useRule ? app(\App\Services\AutogenesiPrice::class)->quote($cost) : null;
                if ($useRule && ($price === null || (isset($variant['selling_price']) && (float) $price === (float) $variant['selling_price']))) {
                    $price = $variant['pricing']['selling_price'];
                }
            }
            $variant['selling_price'] = $price !== null ? ($catalog ? number_format((float) $price, 2, '.', '') : number_format(ceil((float) $price), 0, '.', '')) : null;
        }
        unset($variant);
        $variants = $catalog ? $variants : app(AutogenesiVariants::class)->normalize($variants);
        $vehicleData = [];
        if (isset($data['vehicles'])) {
            $entries = [];
            foreach ($data['vehicles'] as $v) {
                $brandCount = count(array_unique(array_filter(array_map(fn ($b) => mb_strtoupper(trim($b)), explode('|', $v['brand'])))));
                if ($brandCount > 1 && collect(explode('|', $v['model']))->contains(fn ($m) => !preg_match('/^\s*\d+\s*[:：]\s*.+$/u', $m))) {
                    throw ValidationException::withMessages(['vehicles' => 'Con più marche numera ogni modello: 1:Ducato | 2:Jumper | 3:Boxer.']);
                }
                $pairs = app(\App\Services\VehicleImageResolver::class)->vehicleEntries($v['brand'], $v['model']);
                if (!$pairs) throw ValidationException::withMessages(['vehicles' => 'Associazioni marca/modello non valide.']);
                foreach ($pairs as $pair) {
                    $pair = ['brand' => mb_strtoupper(trim($pair['brand'])), 'model' => app(\App\Services\AutogenesiVehicleTransform::class)->model(trim($pair['model']), true)];
                    if (mb_strlen($pair['model']) > 120) throw ValidationException::withMessages(['vehicles' => 'Nome modello troppo lungo.']);
                    $key = implode('|', $pair).'|'.$v['year_from'].'|'.$v['year_to'];
                    $entries[$key] = $pair + ['year_from' => (int) $v['year_from'], 'year_to' => (int) $v['year_to'], 'origin' => 'manual'];
                }
            }
            $entries = array_values($entries);
            $fields = app(\App\Services\AutogenesiVehicleTransform::class)->fields($entries);
            if (mb_strlen($fields['brand']) > 255 || mb_strlen($fields['model']) > 255) throw ValidationException::withMessages(['vehicles' => 'Elenco marche/modelli troppo lungo.']);
            $meta = $product->metadata;
            $meta['vehicle_compatibilities'] = $entries;
            $vehicleData = $fields + [
                'year_from' => min(array_column($entries, 'year_from')), 'year_to' => max(array_column($entries, 'year_to')), 'metadata' => $meta];
        }
        if (array_key_exists('additional_source_urls', $data)) {
            $meta = $vehicleData['metadata'] ?? $product->metadata ?? [];
            $meta['additional_source_urls'] = array_values(array_unique(array_filter($data['additional_source_urls'], fn ($url) => $url && $url !== $product->source_url)));
            $vehicleData['metadata'] = $meta;
        }
        $product->update($vehicleData + ['title' => $data['title'], 'body_html' => ProductHtml::sanitize($data['body_html'] ?? ''), 'variants' => $variants]);
        app(AutogenesiExport::class)->writeFiles($product->fresh());

        return back()->with('status', $catalog ? 'Scheda prodotto aggiornata nel catalogo.' : 'Bozza aggiornata.');
    }

    public function storeVariant(Request $request, AutogenesiProduct $product)
    {
        abort_unless(app()->environment('local'), 403);
        $catalog = $product instanceof \App\Models\CatalogProductSheet;
        $useRule = !$catalog || app(\App\Services\CatalogPriceUpdater::class)->isRadio(\App\Models\ConfiguratorProduct::findOrFail($product->id));
        $data = $request->validate(['label' => ['required', 'string', 'max:500', 'not_regex:/360/u'],
            'supplier_price' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,2'],
            'selling_price' => ($useRule ? 'nullable' : 'required').'|numeric|gt:0|max:999999']);
        DB::transaction(function () use ($product, $data, $useRule, $catalog) {
            $current = $product->locked();
            abort_unless($current->status === 'draft', 409);
            $variants = $current->variants;
            if (count($variants) >= 100) throw ValidationException::withMessages(['label' => 'Massimo 100 varianti per bozza.']);
            $label = trim($data['label']);
            if (collect($variants)->contains(fn ($v) => mb_strtolower(trim($v['label'])) === mb_strtolower($label))) {
                throw ValidationException::withMessages(['label' => 'Esiste già una variante con questo nome.']);
            }
            $cost = number_format((float) $data['supplier_price'], 2, '.', '');
            $pricing = $useRule ? app(\App\Services\AutogenesiPrice::class)->quote($cost) : null;
            $prefix = ($current->metadata['source_platform'] ?? '') === 'web' ? 'WEB-' : 'AE-';
            do { $sku = $prefix.$current->source_id.'-M'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)); }
            while (in_array($sku, array_column($variants, 'sku'), true));
            $variants[] = ['label' => $label, 'source_label' => $label, 'label_edited' => true, 'sku' => $sku,
                'supplier_price' => $cost, 'currency' => 'EUR', 'selling_price' => isset($data['selling_price']) ? ($catalog ? number_format((float) $data['selling_price'], 2, '.', '') : (string) (int) ceil((float) $data['selling_price'])) : $pricing['selling_price'],
                'pricing' => $pricing, 'source_options' => [], 'observed_at' => now()->toIso8601String(), 'manual' => true];
            $current->update(['variants' => $current instanceof \App\Models\CatalogProductSheet ? $variants : app(AutogenesiVariants::class)->normalize($variants)]);
        });
        app(AutogenesiExport::class)->writeFiles($product->fresh());
        return back()->with('status', 'Variante aggiunta.');
    }

    public function destroyVariant(Request $request, AutogenesiProduct $product)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['sku' => 'required|string|max:160']);
        DB::transaction(function () use ($product, $data) {
            $current = $product->locked();
            $variants = $current->variants;
            $index = array_search($data['sku'], array_column($variants, 'sku'), true);
            abort_if($index === false, 404);
            if (count($variants) < 2) {
                throw ValidationException::withMessages(['variant' => 'Deve rimanere almeno una variante.']);
            }
            array_splice($variants, $index, 1);
            $current->update(['variants' => $variants]);
        });
        app(AutogenesiExport::class)->writeFiles($product->fresh());

        return back()->with('status', 'Variante eliminata.');
    }

    public function image(Request $request, AutogenesiProduct $product, int $index)
    {
        $image = $request->query('v') ? collect($product->images)->firstWhere('sha256', $request->query('v')) : ($product->images[$index] ?? null);
        abort_unless($image && Storage::disk('local')->exists($image['path']), 404);

        $path = Storage::disk('local')->path($image['path']);
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $response = $request->boolean('download') ? response()->download($path, $product->handle.'-foto-'.($index + 1).'.'.pathinfo($path, PATHINFO_EXTENSION), $headers) : response()->file($path, $headers);
        $response->setPrivate();

        return $response;
    }

    public function editImage(Request $request, AutogenesiProduct $product, int $index, AutogenesiImageEditor $editor)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'sharpness' => 'sometimes|integer|min:0|max:100', 'points' => 'sometimes|array|min:3|max:100', 'points.*' => 'array|size:2', 'points.*.*' => 'required|integer|min:0', 'cutout_mode' => 'sometimes|in:manual,auto', 'mask' => $request->routeIs('autogenesi.clean-ai-image', 'imported-products.sheet.clean-ai-image') ? 'required|file|mimetypes:image/png|max:8192' : 'required_if:cutout_mode,auto|file|mimetypes:image/png|max:8192']);
        abort_unless(collect($product->images)->contains('sha256', $data['image_id']), 404);
        try {
            if ($request->routeIs('autogenesi.restore-image', 'imported-products.sheet.restore-image')) {
                $editor->restore($product, $data['image_id']);
            } elseif ($request->routeIs('autogenesi.upscale-image', 'imported-products.sheet.upscale-image')) {
                app(\App\Services\AutogenesiImageUpscale::class)->upscale($product, $data['image_id'], $data['sharpness'] ?? 35);
            } else {
                $editor->edit($product, $data['image_id'], match (true) {
                    $request->routeIs('autogenesi.white-image', 'imported-products.sheet.white-image') => 'white',
                    $request->routeIs('autogenesi.clean-ai-image', 'imported-products.sheet.clean-ai-image') => 'dashboard_clean',
                    default => 'scene'
                }, $data['points'] ?? [], $request->file('mask'));
            }
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Foto aggiornata.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['image_ai' => $e instanceof \RuntimeException ? $e->getMessage() : 'Modifica foto non completata. Verifica la configurazione AI e riprova.']);
        }
    }

    public function generateDashboard(AutogenesiProduct $product, AutogenesiDashboardImage $generator)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            $generator->generate($product);
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Foto reale del cruscotto importata. Verifica modello e fonte prima di pubblicarla.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['dashboard' => $e instanceof \RuntimeException && ! $e instanceof RequestException
                ? $e->getMessage() : 'Ricerca foto non completata. Riprova.']);
        }
    }

    public function stopProcessing(AutogenesiProduct $product)
    {
        $kind = $product instanceof \App\Models\CatalogProductSheet ? 'catalog' : 'draft';
        $epoch = app(\App\Services\ProductSheetProcessing::class)->stop($kind, $product->id);
        return response()->json(['epoch' => $epoch, 'message' => 'Elaborazioni della scheda interrotte. Le modifiche già salvate restano conservate.']);
    }

    public function descriptionFromLink(Request $request, AutogenesiProduct $product, \App\Services\AutogenesiApiImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        set_time_limit(900);
        try {
            $result = $importer->regenerateDescription($product, $request->user()->id);
            $current = $product->fresh();
            app(AutogenesiExport::class)->writeFiles($current);
            if ($request->expectsJson()) return response()->json(['body_html' => $current->body_html, 'changed' => $result['changed'],
                'notes' => $result['notes'], 'generation' => $current->metadata['description_generation'], 'source' => $current->metadata['description_source']])->header('Cache-Control', 'no-store');
            return back();
        } catch (\Throwable $e) {
            report($e);
            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'Generazione non completata: descrizione precedente conservata.';
            if ($request->expectsJson()) return response()->json(['message' => $message], 422);
            return back()->withErrors(['description_link' => $message]);
        }
    }

    public function reimportImages(Request $request, AutogenesiProduct $product, \App\Services\AutogenesiApiImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        set_time_limit(420);
        try {
            $result = $importer->reimportImages($product, $request->user()->id);
            app(AutogenesiExport::class)->writeFiles($product->fresh());
            return back()->with('reimport_images_result', ($result['added'] ? $result['added'].' immagini aggiunte.' : 'Nessuna nuova foto aggiunta: le immagini selezionate sono già presenti o non utilizzabili.').' '.implode(' ', $result['warnings']));
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['reimport_images' => $e instanceof \RuntimeException ? $e->getMessage() : 'Reimportazione immagini non completata. Le foto esistenti sono conservate.']);
        }
    }

    public function uploadImages(Request $request, AutogenesiProduct $product, AutogenesiImageUpload $uploader)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['photos' => 'required|array|min:1|max:10', 'photos.*' => 'required|file|max:8192',
            'role' => 'required|in:prodotto,accessori,cruscotto_prima_dopo']);
        if (array_sum(array_map(fn ($f) => $f->getSize(), $request->file('photos'))) > 60 * 1024 * 1024) {
            throw ValidationException::withMessages(['photos' => 'Massimo 60 MB per caricamento.']);
        }
        try {
            $count = $uploader->add($product, $request->file('photos'), $data['role']);
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', $count ? $count.' foto aggiunte alla bozza.' : 'Queste foto sono già presenti.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['photos' => $e->getMessage()]);
        }
    }

    public function cleanupText(Request $request, AutogenesiProduct $product, int $index, \App\Services\AutogenesiMaskedBlur $editor)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'mask' => 'required|file|mimetypes:image/png|max:8192', 'strength' => 'sometimes|integer|min:1|max:100']);
        abort_unless(collect($product->images)->contains('sha256', $data['image_id']), 404);
        try {
            $editor->edit($product, $data['image_id'], $request->file('mask'), $data['strength'] ?? 12, $request->routeIs('autogenesi.clean-image', 'imported-products.sheet.clean-image'));
            app(AutogenesiExport::class)->writeFiles($product->fresh());
            return back()->with('status', $request->routeIs('autogenesi.clean-image', 'imported-products.sheet.clean-image') ? 'Nitidezza migliorata senza rigenerazione. Sfocatura applicata solo sulle eventuali scritte selezionate.' : 'Sfocatura applicata solo alle zone selezionate.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['mask' => $e instanceof \RuntimeException && !$e instanceof RequestException ? $e->getMessage() : 'Sfocatura non completata. Riprova.']);
        }
    }

    public function paintImage(Request $request, AutogenesiProduct $product, int $index, \App\Services\AutogenesiImagePaint $painter)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'photo' => 'required|file|mimetypes:image/png,image/webp|max:16384']);
        abort_unless(collect($product->images)->contains('sha256', $data['image_id']), 404);
        try {
            $painter->save($product, $data['image_id'], $request->file('photo'));
            app(AutogenesiExport::class)->writeFiles($product->fresh());
            return back()->with('status', 'Foto Paint salvata.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['photo' => 'Salvataggio Paint non completato. '.$e->getMessage()]);
        }
    }

    public function cropImage(Request $request, AutogenesiProduct $product, int $index, AutogenesiImageCrop $cropper)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0', 'size' => 'required_without:width|integer|min:32|max:16000', 'width' => 'sometimes|required|integer|min:32|max:16000', 'height' => 'required_with:width|integer|min:32|max:16000']);
        abort_unless(collect($product->images)->contains('sha256', $data['image_id']), 404);
        try {
            $cropper->crop($product, $data['image_id'], $data['x'], $data['y'], $data['width'] ?? $data['size'], $data['height'] ?? null);
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Foto ritagliata.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['image_crop' => $e->getMessage()]);
        }
    }

    public function imageLayout(Request $request, AutogenesiProduct $product, \App\Services\AutogenesiImageLayout $layout)
    {
        $data = $request->validate([
            'order' => 'sometimes|required|array|min:1|max:200', 'order.*' => 'required|distinct|regex:/^[a-f0-9]{64}$/',
            'dashboard_image_ids' => 'sometimes|array|max:200', 'dashboard_image_ids.*' => 'required|distinct|regex:/^[a-f0-9]{64}$/',
            'reference_image_id' => 'sometimes|required|regex:/^[a-f0-9]{64}$/',
            'dashboard_image_id' => 'sometimes|nullable|regex:/^[a-f0-9]{64}$/',
            'shopify_file_reference' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:~^(?:gid://shopify/(?:MediaImage|GenericFile)/[0-9]+|[^/\\\\:\r\n]+\.(?:png|jpe?g|webp|avif))$~i'],
        ]);
        abort_if(! $data, 422);
        $layout->update($product, $data);
        app(AutogenesiExport::class)->writeFiles($product->fresh());

        return back()->with('status', 'Ordine e foto del cruscotto aggiornati.');
    }

    public function removeImage(Request $request, AutogenesiProduct $product, int $index)
    {
        $images = $product->images;
        if ($request->has('image_id')) {
            $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/']);
            $index = array_search($request->input('image_id'), array_column($images, 'sha256'), true);
            abort_if($index === false, 404);
        }
        abort_unless(isset($images[$index]), 404);
        $path = $images[$index]['path'];
        $paths = array_filter([$path, $images[$index]['uncropped_path'] ?? null, $images[$index]['original']['path'] ?? null, $images[$index]['original']['uncropped_path'] ?? null]);
        array_splice($images, $index, 1);
        $product->update(['images' => $images]);
        Storage::disk('local')->delete($paths);
        app(AutogenesiExport::class)->writeFiles($product->fresh());

        return back()->with('status', 'Immagine rimossa dalla bozza.');
    }

    public function importAllConfigurator(AutogenesiCatalogImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            $count = $importer->importAll();

            return back()->with('status', $count.' prodotti importati nel configuratore. Tutte le bozze importate sono state eliminate.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['catalog_all' => $e->getMessage()]);
        }
    }

    public function importConfigurator(AutogenesiProduct $product, AutogenesiCatalogImport $importer)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            $catalog = $importer->import($product);
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Prodotto #'.$catalog->id.' importato nel configuratore con varianti e foto.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['catalog' => $e->getMessage()]);
        }
    }

    public function exportAll(AutogenesiTemplateExport $exporter)
    {
        $drafts = AutogenesiProduct::where('status', 'draft')->orderBy('id')->get();
        abort_if($drafts->isEmpty(), 409, 'Nessuna bozza da esportare.');
        $disk = Storage::disk('local');
        $disk->makeDirectory('autogenesi/exports/catalogo');
        $name = 'autogenesi-catalogo-bozze-'.now('Europe/Rome')->format('Y-m-d').'.xlsx';
        $path = $disk->path('autogenesi/exports/catalogo/'.$name);
        $exporter->writeMany($drafts, $path);

        return response()->download($path, $name);
    }

    public function export(AutogenesiProduct $product, AutogenesiExport $exporter, Request $request)
    {
        $files = $exporter->writeFiles($product);

        return response()->download($files['excel'], $product->handle.'-autogenesi.xlsx');
    }
}
