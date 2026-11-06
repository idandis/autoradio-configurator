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
        return Inertia::render('Autogenesi', ['local' => app()->environment('local'), 'search' => session('autogenesi_search'), 'directImport' => session('autogenesi_direct_import'),
            'batches' => fn () => AutogenesiBatch::with('items')->latest()->limit(10)->get(),
            'products' => fn () => AutogenesiProduct::where('status', 'draft')->latest()->get()->map(function ($p) {
                $data = $p->toArray();
                unset($data['metadata']['source_text']);
                unset($data['metadata']['description_source']['html'], $data['metadata']['previous_copy'], $data['metadata']['capture_directory']);
                $data['images'] = array_map(fn ($image, $i) => ['url' => route('autogenesi.image', [$p, $i, 'v' => $image['sha256']]), 'id' => $image['sha256'],
                    'ai_edited' => (bool) ($image['ai_edited'] ?? false), 'manually_cropped' => (bool) (($image['manually_cropped'] ?? false) || ($image['manually_painted'] ?? false)),
                    'dashboard_selected' => in_array($image['sha256'], array_column(\App\Services\AutogenesiImageLayout::dashboards($p), 'sha256'), true), 'shopify_file_reference' => $image['shopify_file_reference'] ?? '',
                    'ai_generated' => (bool) ($image['ai_generated'] ?? false), 'web_sources' => $image['web_sources'] ?? [],
                    'local_only' => (bool) (($image['uploaded'] ?? false) || ($image['ai_generated'] ?? false) || ($image['ai_edited'] ?? false) || ($image['square_cropped'] ?? false) || ($image['manually_painted'] ?? false)),
                    'role' => $image['role'], 'source_url' => $image['source_url'], 'width' => $image['width'], 'height' => $image['height']], $p->images, array_keys($p->images));

                return $data;
            })]);
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
        foreach (['manifest.json', 'popup.html', 'popup.js', 'background.js', 'capture.js', 'generic.js', 'ISTRUZIONI.txt'] as $file) {
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
        $data = $request->validate(['list' => 'required|string|max:30000']);
        app(AutogenesiRunControl::class)->activate();
        $batch = $lists->create($data['list'], $request->user()->id);

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

    public function update(Request $request, AutogenesiProduct $product)
    {
        $data = $request->validate(['title' => 'required|string|max:2000', 'body_html' => 'required|string|max:150000',
            'additional_source_urls' => 'sometimes|array|max:30', 'additional_source_urls.*' => ['nullable', 'url:http,https', 'max:4096', function ($attribute, $value, $fail) { if ($value && (parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_PASS))) $fail('Inserisci un link senza credenziali.'); }],
            'vehicles' => 'sometimes|required|array|min:1|max:100', 'vehicles.*.brand' => 'required|string|max:80', 'vehicles.*.model' => 'required|string|max:120',
            'vehicles.*.year_from' => 'required|integer|min:1|max:65535', 'vehicles.*.year_to' => 'required|integer|gte:vehicles.*.year_from|max:65535',
            'variants' => 'required|array|size:'.count($product->variants), 'variants.*.sku' => 'sometimes|required|string', 'variants.*.label' => 'sometimes|required|string|max:500', 'variants.*.selling_price' => 'nullable|numeric|gt:0|max:999999']);
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
            $variant['selling_price'] = $price !== null ? number_format(ceil((float) $price), 0, '.', '') : null;
        }
        unset($variant);
        $variants = app(AutogenesiVariants::class)->normalize($variants);
        $vehicleData = [];
        if (isset($data['vehicles'])) {
            $entries = array_values(array_map(fn ($v) => ['brand' => mb_strtoupper(trim($v['brand'])), 'model' => mb_strtoupper(trim($v['model'])),
                'year_from' => (int) $v['year_from'], 'year_to' => (int) $v['year_to'], 'origin' => 'manual'], $data['vehicles']));
            $brands = array_values(array_unique(array_column($entries, 'brand')));
            $models = array_values(array_unique(array_map(fn ($v) => (array_search($v['brand'], $brands, true) + 1).':'.$v['model'], $entries)));
            $brand = implode(' | ', $brands);
            if (mb_strlen($brand) > 255) throw ValidationException::withMessages(['vehicles' => 'Elenco marche troppo lungo.']);
            $meta = $product->metadata;
            $meta['vehicle_compatibilities'] = $entries;
            $vehicleData = ['brand' => $brand, 'model' => count($models) === 1 ? $entries[0]['model'] : implode(' | ', $models),
                'year_from' => min(array_column($entries, 'year_from')), 'year_to' => max(array_column($entries, 'year_to')), 'metadata' => $meta];
        }
        if (array_key_exists('additional_source_urls', $data)) {
            $meta = $vehicleData['metadata'] ?? $product->metadata ?? [];
            $meta['additional_source_urls'] = array_values(array_unique(array_filter($data['additional_source_urls'], fn ($url) => $url && $url !== $product->source_url)));
            $vehicleData['metadata'] = $meta;
        }
        $product->update($vehicleData + ['title' => $data['title'], 'body_html' => ProductHtml::sanitize($data['body_html']), 'variants' => $variants]);
        app(AutogenesiExport::class)->writeFiles($product->fresh());

        return back()->with('status', 'Bozza aggiornata.');
    }

    public function destroyVariant(Request $request, AutogenesiProduct $product)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['sku' => 'required|string|max:160']);
        DB::transaction(function () use ($product, $data) {
            $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
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
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'points' => 'sometimes|array|min:3|max:100', 'points.*' => 'array|size:2', 'points.*.*' => 'required|integer|min:0', 'cutout_mode' => 'sometimes|in:manual,auto', 'mask' => 'required_if:cutout_mode,auto|file|mimetypes:image/png|max:8192']);
        abort_unless(collect($product->images)->contains('sha256', $data['image_id']), 404);
        try {
            if ($request->routeIs('autogenesi.restore-image')) {
                $editor->restore($product, $data['image_id']);
            } else {
                $editor->edit($product, $data['image_id'], match (true) {
                    $request->routeIs('autogenesi.white-image') => 'white', $request->routeIs('autogenesi.clean-image') => 'clean', $request->routeIs('autogenesi.text-image') => 'text', default => 'scene'
                }, $data['points'] ?? [], $request->file('mask'));
            }
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Foto aggiornata.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['image_ai' => 'Modifica foto non completata. Verifica la configurazione AI e riprova.']);
        }
    }

    public function generateDashboard(AutogenesiProduct $product, AutogenesiDashboardImage $generator)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            $generator->generate($product);
            app(AutogenesiExport::class)->writeFiles($product->fresh());

            return back()->with('status', 'Cruscotto generato con AI e aggiunto alla bozza. Verifica la corrispondenza con la vettura.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['dashboard' => $e instanceof \RuntimeException && ! $e instanceof RequestException
                ? $e->getMessage() : 'Generazione non completata. Verifica la configurazione AI e riprova.']);
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

    public function paintImage(Request $request, AutogenesiProduct $product, int $index, \App\Services\AutogenesiImagePaint $painter)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['image_id' => 'required|regex:/^[a-f0-9]{64}$/', 'photo' => 'required|file|mimetypes:image/png|max:16384']);
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
