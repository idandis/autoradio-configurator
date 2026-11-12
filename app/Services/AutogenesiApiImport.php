<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AutogenesiApiImport
{
    public function importUrl(int $user, string $url): AutogenesiProduct
    {
        abort_unless(app()->environment('local'), 403);
        $url = app(AutogenesiSearch::class)->sourceUrl($url);
        if (!$url) throw new RuntimeException('Inserisci un link prodotto AliExpress valido.');
        $id = AutogenesiSource::id($url);
        if ($existing = AutogenesiProduct::where('source_id', $id)->first()) return $existing;

        $control = app(AutogenesiRunControl::class);
        $control->activate();
        $run = $control->state()['run_id'];
        $product = app(AutogenesiAliExpressApi::class)->product($user, $id);
        $vehicle = app(AutogenesiProductVehicle::class)->infer($product);
        if (!$this->headUnit($product, $vehicle)) throw new RuntimeException('Il link non contiene un’autoradio specifica per il veicolo rilevato.');
        $variants = $this->variants($product);
        if (!$variants) throw new RuntimeException('Nessuna variante radio con prezzo EUR disponibile dal link.');
        usort($variants, fn ($a, $b) => ($b['eu'] <=> $a['eu']) ?: ($a['supplier_price'] <=> $b['supplier_price']));
        $dir = Storage::disk('local')->path('autogenesi/captures/api-'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        try {
            $control->assertActive($run);
            $manifest = $this->manifest($id, $product, $variants, $dir, (bool) $variants[0]['eu'], $run);
            file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX);
            $draft = app(AutogenesiImport::class)->import($dir.'/manifest.json', $vehicle);
            $meta = $draft->metadata;
            $meta['acquisition_method'] = 'api_direct_url';
            $draft->update(['metadata' => $meta]);
            return $draft;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function importBest(int $user, string $query, array $vehicle, ?callable $selected = null, array $options = []): ?AutogenesiProduct
    {
        abort_unless(app()->environment('local'), 403);
        $control = app(AutogenesiRunControl::class);
        $run = $control->state()['run_id'];
        $control->assertActive($run);
        $api = app(AutogenesiAliExpressApi::class);
        $sort = $options['sort_by'] ?? 'price';
        $origin = 'prefer_eu';
        if (!in_array($sort, ['price', 'sales', 'best_match'], true)) throw new RuntimeException('Criteri di selezione API non validi.');
        $result = $api->search($user, $query, $sort);
        $candidates = array_values(array_filter($result['products'], fn ($p) => $this->relevant($p['title'], $vehicle)));
        if ($sort !== 'best_match') usort($candidates, fn ($a, $b) => ($sort === 'sales' ? (($b['sales'] ?? -1) <=> ($a['sales'] ?? -1)) : 0) ?: (($a['price'] ?? INF) <=> ($b['price'] ?? INF)));
        $offers = []; $failures = [];
        foreach (array_slice($candidates, 0, 8) as $rank => $candidate) {
            $control->assertActive($run);
            try {
                $product = $api->product($user, $candidate['id']);
                $title = data_get($product, 'ae_item_base_info_dto.subject', '');
                if (!$this->headUnit($product, $vehicle) || !$this->yearOverlap($title, $vehicle)) continue;
                $variants = $this->variants($product);
                if (!$variants) continue;
                $eu = array_values(array_filter($variants, fn ($v) => $v['eu']));
                $pool = $eu ?: $variants;
                if (!$pool) continue;
                usort($pool, fn ($a, $b) => $a['supplier_price'] <=> $b['supplier_price']);
                $offers[] = ['id' => $candidate['id'], 'product' => $product, 'variants' => $pool,
                    'eu' => $pool[0]['eu'], 'price' => $pool[0]['supplier_price'], 'sales' => $candidate['sales'] ?? null, 'orders' => $candidate['orders'] ?? '', 'match_rank' => $rank];
            } catch (RuntimeException $e) {
                if (preg_match('/autorizzazione|InvalidSession|InvalidAccessToken|IllegalAccessToken|NGSELECTION_SEARCH_ERROR/i', $e->getMessage())) throw $e;
                $failures[] = $e->getMessage();
            }
        }
        if (!$offers) {
            if ($failures && count($failures) === count(array_slice($candidates, 0, 8))) throw new RuntimeException($failures[0]);
            return null;
        }
        usort($offers, fn ($a, $b) => [$a['eu'] ? 0 : 1, $sort === 'sales' ? -($a['sales'] ?? -1) : ($sort === 'best_match' ? $a['match_rank'] : 0), $a['price']]
            <=> [$b['eu'] ? 0 : 1, $sort === 'sales' ? -($b['sales'] ?? -1) : ($sort === 'best_match' ? $b['match_rank'] : 0), $b['price']]);
        $best = $offers[0];
        $url = 'https://es.aliexpress.com/item/'.$best['id'].'.html';
        if ($selected) $selected($url);
        $control->assertActive($run);
        if ($existing = AutogenesiProduct::where('source_id', $best['id'])->first()) {
            if ($existing->status !== 'draft') throw new RuntimeException('Il prodotto selezionato esiste già e non è una bozza.');
            return $existing;
        }
        $dir = Storage::disk('local')->path('autogenesi/captures/api-'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        $saved = false;
        try {
            $manifest = $this->manifest($best['id'], $best['product'], $best['variants'], $dir, $best['eu'], $run);
            file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX);
            $control->assertActive($run);
            $p = app(AutogenesiImport::class)->import($dir.'/manifest.json', $vehicle);
            $saved = true;
            $meta = $p->metadata;
            $meta['acquisition_method'] = 'api';
            $meta['api_selection'] = ['query' => $query, 'candidates_examined' => min(8, count($candidates)),
                'offers_compared' => count($offers), 'origin' => $best['eu'] ? 'EU' : 'all', 'observed_price' => $best['price'],
                'sort_by' => $sort, 'origin_policy' => $origin, 'orders' => $best['orders']];
            if ($sort === 'sales') $meta['warnings'][] = 'Ordine per vendite basato sugli ordini indicativi restituiti dall’API; se assenti si confrontano i prezzi.';
            $meta['warnings'][] = 'Selezione API tra i candidati esaminati: verificare compatibilità e copertura effettiva dell’intervallo richiesto. Il prezzo osservato non conferma il costo di spedizione.';
            $p->update(['metadata' => $meta]);
            return $p;
        } finally {
            if (!$saved) File::deleteDirectory($dir);
        }
    }

    public function relevant(string $title, array $vehicle): bool
    {
        $text = ' '.$this->norm($title).' ';
        if (preg_match('/\b(?:universal|universale|universales|portatil|portable|marco|frame|fascia|cable|cableado|adaptador|adapter|dashcam|limpiaparabrisas|soporte|protector|replacement lcd|antena|antenas|antenna|aerial|gasket|junta|seal|varilla|mastil|techo|roof)\b/u', $text)
            || preg_match('/^(?:camara|camera)\b/u', trim($text))) return false;
        if (!preg_match('/\b(?:android|carplay|android auto|head unit)\b/u', $text)
            || !preg_match('/\b(?:radio|autoradio|stereo|estereo|head unit|multimedia|pantalla|screen)\b/u', $text)) return false;
        $brand = $this->norm($vehicle['brand']);
        if ($brand === 'vw') $brand = 'volkswagen';
        if ($brand === 'mercedes benz') $brand = 'mercedes';
        if (!str_contains($text, ' '.$brand.' ') && !($brand === 'volkswagen' && str_contains($text, ' vw '))) return false;
        foreach (explode(' ', $this->norm($vehicle['model'])) as $word) {
            if (!str_contains($text, ' '.$word.' ')) return false;
        }
        return true;
    }

    public function headUnit(array $product, array $vehicle): bool
    {
        $title = data_get($product, 'ae_item_base_info_dto.subject', '');
        if (!$this->relevant($title, $vehicle)) return false;
        $props = data_get($product, 'ae_item_properties.ae_item_property', []);
        if (isset($props['attr_name'])) $props = [$props];
        $hardware = false;
        foreach ($props as $prop) {
            $name = $this->norm($prop['attr_name'] ?? ''); $value = $this->norm($prop['attr_value'] ?? '');
            if (preg_match('/^(?:tipo de articulo|item type|product type|tipo de producto|tipo)$/', $name)
                && preg_match('/\b(?:antenas?|antenna|aerial|junta|gasket|seal|camara|camera|marco|frame|fascia|cables?|harness|adaptador|adapter|protector)\b/', $value)) return false;
            if ($value && preg_match('/\b(?:sistema operativo|operating system|memoria ram|ram|rom|cpu|procesador|processor|screen size|tamano de la pantalla|tamano pantalla)\b/', $name)) $hardware = true;
        }
        return $hardware;
    }

    private function yearOverlap(string $title, array $vehicle): bool
    {
        preg_match_all('/\b((?:19|20)\d{2})\s*[-–—]\s*((?:19|20)\d{2})\b/u', $title, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $r) if ((int) $r[1] <= $vehicle['year_to'] && (int) $r[2] >= $vehicle['year_from']) return true;
        preg_match_all('/\b(?:19|20)\d{2}\b/', $title, $years);
        return (bool) array_filter($years[0], fn ($y) => $y >= $vehicle['year_from'] && $y <= $vehicle['year_to']);
    }

    public function variants(array $product): array
    {
        $items = data_get($product, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
        if (isset($items['sku_id'])) $items = [$items];
        $out = [];
        foreach ($items as $v) {
            if (($v['currency_code'] ?? '') !== 'EUR' || (isset($v['sku_available_stock']) && (int) $v['sku_available_stock'] <= 0)
                || in_array($v['sku_stock'] ?? true, [false, 'false', 0, '0'], true)) continue;
            $price = $v['offer_sale_price'] ?? $v['sku_price'] ?? null;
            if (!is_numeric($price) || $price <= 0 || $price > 999999) continue;
            $props = data_get($v, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
            if (isset($props['sku_property_id'])) $props = [$props];
            $labels = []; $eu = false; $options = [];
            foreach ($props as $prop) {
                $value = trim($prop['property_value_definition_name'] ?? '') ?: trim($prop['sku_property_value'] ?? '');
                if (!$value) continue;
                $name = (string) ($prop['sku_property_name'] ?? 'Opzione');
                $options[] = ['name' => $name, 'value' => $value];
                $labels[] = $value;
                if ((string) ($prop['sku_property_id'] ?? '') === '200007763') $eu = $this->eu($value);
            }
            $label = implode(' · ', $labels) ?: trim($v['sku_attr'] ?? '');
            if (!$label || strlen($label) > 500 || str_contains($label.' '.($v['sku_attr'] ?? ''), '360')) continue;
            if (preg_match('/\b(?:frame only|only frame|solo marco|marco solo|accessories only|solo accesorios|cable only|camera only|only camera|solo camara|harness only|universal)\b/', $this->norm($label))) continue;
            $out[] = ['label' => $label, 'supplier_price' => (float) $price, 'currency' => 'EUR', 'options' => $options,
                'observed_at' => now()->toIso8601String(), 'eu' => $eu, 'source_sku_id' => (string) ($v['sku_id'] ?? '')];
        }
        return $out;
    }

    private function eu(string $country): bool
    {
        return in_array($this->norm($country), ['eu', 'union europea', 'european union', 'es', 'espana', 'spain', 'it', 'italia', 'italy', 'fr', 'francia', 'france', 'de', 'alemania', 'germany',
            'pl', 'polonia', 'poland', 'cz', 'czech republic', 'republica checa', 'nl', 'netherlands', 'paises bajos', 'be', 'belgium', 'belgica',
            'pt', 'portugal', 'at', 'austria', 'ro', 'romania', 'rumania', 'hu', 'hungary', 'hungria', 'se', 'sweden', 'suecia', 'dk', 'denmark', 'dinamarca',
            'fi', 'finland', 'finlandia', 'ie', 'ireland', 'irlanda', 'gr', 'greece', 'grecia', 'bg', 'bulgaria', 'hr', 'croatia', 'croacia',
            'si', 'slovenia', 'eslovenia', 'sk', 'slovakia', 'eslovaquia', 'lt', 'lithuania', 'lituania', 'lv', 'latvia', 'letonia', 'ee', 'estonia', 'lu', 'luxembourg', 'luxemburgo', 'mt', 'malta', 'cy', 'cyprus', 'chipre'], true);
    }

    private function manifest(string $id, array $p, array $variants, string $dir, bool $eu, ?string $run): array
    {
        $html = data_get($p, 'ae_item_base_info_dto.detail', '');
        if (!is_string($html) || !trim($html) || strlen($html) > 250000) throw new RuntimeException('Descrizione API assente o troppo grande.');
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.($html ?: '<div></div>'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $descriptionUrls = [];
        foreach ($dom->getElementsByTagName('img') as $image) {
            $url = $image->getAttribute('src') ?: $image->getAttribute('data-src');
            if ($url) $descriptionUrls[] = $url;
        }
        $specs = [];
        $properties = data_get($p, 'ae_item_properties.ae_item_property', []);
        if (isset($properties['attr_name'])) $properties = [$properties];
        foreach ($properties as $prop) {
            $name = trim((string) ($prop['attr_name'] ?? '')); $value = trim((string) ($prop['attr_value'] ?? ''));
            if ($name && $value) $specs[] = ['name' => $name, 'value' => $value];
        }
        if (!$specs) throw new RuntimeException('Specifiche tecniche non disponibili nella scheda API.');
        $urls = array_values(array_filter(explode(';', data_get($p, 'ae_multimedia_info_dto.image_urls', ''))));
        $skuRows = data_get($p, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
        if (isset($skuRows['sku_id'])) $skuRows = [$skuRows];
        foreach ($skuRows as $skuRow) {
            $skuProps = data_get($skuRow, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
            if (isset($skuProps['sku_property_id'])) $skuProps = [$skuProps];
            foreach ($skuProps as $prop) if (!empty($prop['sku_image'])) $urls[] = $prop['sku_image'];
        }
        $budget = 0; $hashes = []; $warnings = [];
        $gallery = $this->images(array_slice(array_values(array_unique(array_merge($urls, $descriptionUrls))), 0, 12), 'image', $dir, $budget, $hashes, $warnings, $run);
        if (count($gallery) < 3) throw new RuntimeException('La scheda API non fornisce almeno tre immagini valide e distinte.');
        $descriptionImages = $this->images(array_slice(array_values(array_unique($descriptionUrls)), 0, 30), 'description', $dir, $budget, $hashes, $warnings, $run);
        if (count(array_unique($descriptionUrls)) > 30) $warnings[] = 'Acquisite al massimo 30 immagini della descrizione API: verificare le specifiche nelle immagini rimanenti.';
        $total = count($variants); $variants = array_slice($variants, 0, 20);
        if ($total > 20) $warnings[] = 'Non tutte le varianti sono state prese: introdurle manualmente.';
        if (!$eu) $warnings[] = 'Variante selezionata con origine extra UE o non confermata: verificare origine e spedizione.';
        return ['source_id' => $id, 'source_url' => 'https://es.aliexpress.com/item/'.$id.'.html',
            'source_title' => data_get($p, 'ae_item_base_info_dto.subject'), 'seller' => data_get($p, 'ae_store_info.store_name'),
            'captured_at' => now()->toIso8601String(), 'variants' => $variants, 'images' => $gallery, 'warnings' => $warnings,
            'variant_acquisition' => ['complete' => $total <= 20, 'detected' => $total, 'captured' => count($variants), 'skipped' => []],
            'description' => ['complete' => true, 'html' => $html, 'text' => html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8').' '.implode(' ', array_map(fn ($s) => $s['name'].': '.$s['value'], $specs)),
                'specifications' => $specs, 'images' => $descriptionImages]];
    }

    public function regenerateDescription(AutogenesiProduct $product, int $user): array
    {
        $parts = parse_url($product->source_url); $host = strtolower($parts['host'] ?? '');
        if (!in_array($parts['scheme'] ?? '', ['https', 'http'], true) || !preg_match('/(?:^|\.)aliexpress\.com$/D', $host)
            || !preg_match('~/item/(\d+)\.html~', $parts['path'] ?? '', $m)) throw new RuntimeException('Serve un link prodotto AliExpress valido.');
        $data = app(AutogenesiAliExpressApi::class)->product($user, $m[1]);
        $html = data_get($data, 'ae_item_base_info_dto.detail', '');
        if (!is_string($html) || !trim($html) || strlen($html) > 250000) throw new RuntimeException('Descrizione originale assente o troppo grande: testo attuale conservato.');
        $props = data_get($data, 'ae_item_properties.ae_item_property', []);
        if (isset($props['attr_name'])) $props = [$props];
        $specs = [];
        foreach ($props as $prop) if (trim((string) ($prop['attr_name'] ?? '')) !== '' && trim((string) ($prop['attr_value'] ?? '')) !== '') $specs[] = ['name' => (string) $prop['attr_name'], 'value' => (string) $prop['attr_value']];
        if (!$specs) throw new RuntimeException('Specifiche tecniche non disponibili dal fornitore: testo attuale conservato.');
        $dom = new \DOMDocument; $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $urls = [];
        foreach ($dom->getElementsByTagName('img') as $im) if ($url = $im->getAttribute('src') ?: $im->getAttribute('data-src')) $urls[] = $url;
        $dir = Storage::disk('local')->path('autogenesi/captures/description-'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        try {
            $budget = 0; $hashes = []; $warnings = [];
            $descriptionImages = $this->images(array_slice(array_values(array_unique($urls)), 0, 30), 'description', $dir, $budget, $hashes, $warnings, null, false);
            $galleryUrls = array_values(array_filter(explode(';', data_get($data, 'ae_multimedia_info_dto.image_urls', ''))));
            $gallery = $this->images(array_slice($galleryUrls, 0, 6), 'image', $dir, $budget, $hashes, $warnings, null, false);
            if (count(array_unique($urls)) > 30) $warnings[] = 'Lettura limitata alle prime 30 immagini della descrizione.';
            $source = ['complete' => true, 'text' => trim(html_entity_decode(strip_tags(preg_replace('~<(?:br|/p|/div|/li)\b[^>]*>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'specifications' => $specs, 'images' => $descriptionImages];
            $manifest = ['source_title' => data_get($data, 'ae_item_base_info_dto.subject', ''), 'description' => $source,
                'images' => $gallery, 'variants' => $this->variants($data)];
            $copy = app(AutogenesiDescription::class)->generate($manifest, $product->only(['brand', 'model', 'year_from', 'year_to']), $dir, false);
            $body = ProductHtml::sanitize($copy['body_html']);
            if (!trim(strip_tags($body))) throw new RuntimeException('La generazione non contiene una descrizione valida: testo precedente conservato.');
            $changed = $body !== $product->body_html;
            \Illuminate\Support\Facades\DB::transaction(function () use ($product, $copy, $source, $warnings, $body, $changed) {
                $current = $product->locked();
                if ($current->body_html !== $product->body_html) throw new RuntimeException('La descrizione è stata modificata durante la generazione: riapri la scheda e riprova.');
                $meta = $current->metadata;
                if ($changed) $meta['previous_copy'] = ['title' => $current->title, 'body_html' => $current->body_html];
                $meta['description_source'] = $source;
                $meta['description_generation'] = ['id' => (string) Str::uuid(), 'generated_at' => now()->toIso8601String(), 'source_url' => $product->source_url, 'changed' => $changed];
                $meta['description_notes'] = array_values(array_unique(array_merge($warnings, $copy['notes'] ?? [])));
                $current->update(['body_html' => $body, 'metadata' => $meta]);
            });
            return ['changed' => $changed, 'notes' => array_values(array_unique(array_merge($warnings, $copy['notes'] ?? [])))];
        } finally { File::deleteDirectory($dir); }
    }

    public function reimportImages(AutogenesiProduct $product, int $user): array
    {
        $url = $product->source_url;
        $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
        if (!in_array($parts['scheme'] ?? '', ['https', 'http'], true) || !preg_match('/(?:^|\.)aliexpress\.com$/D', $host)
            || !preg_match('~/item/(\d+)\.html~', $parts['path'] ?? '', $m)) throw new RuntimeException('Serve un link prodotto AliExpress valido.');
        $data = app(AutogenesiAliExpressApi::class)->product($user, $m[1]);
        $urls = explode(';', data_get($data, 'ae_multimedia_info_dto.image_urls', ''));
        $html = data_get($data, 'ae_item_base_info_dto.detail', '');
        if ($html) {
            $dom = new \DOMDocument; $previous = libxml_use_internal_errors(true);
            try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            foreach ($dom->getElementsByTagName('img') as $im) $urls[] = $im->getAttribute('src') ?: $im->getAttribute('data-src');
        }
        $rows = data_get($data, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
        if (isset($rows['sku_id'])) $rows = [$rows];
        $variantUrls = [];
        foreach ($rows as $row) {
            $props = data_get($row, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
            if (isset($props['sku_property_id'])) $props = [$props];
            foreach ($props as $prop) if (!empty($prop['sku_image'])) $variantUrls[] = $prop['sku_image'];
        }
        $urls = array_merge($variantUrls, $urls);
        $normalize = fn ($url) => preg_replace('~^(?:http:)?//~', 'https://', trim($url));
        $urls = array_values(array_unique(array_filter(array_map($normalize, $urls))));
        if (!$urls) throw new RuntimeException('La scheda API non contiene immagini recuperabili.');
        $known = collect($product->images)->flatMap(fn ($im) => [$im['source_url'] ?? '', $im['original']['source_url'] ?? ''])->filter()->map($normalize)->all();
        $urls = array_values(array_diff($urls, $known));
        if (!$urls) return ['added' => 0, 'warnings' => []];
        $dir = Storage::disk('local')->path('autogenesi/captures/photos-'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        $budget = 0; $hashes = []; $warnings = []; $written = [];
        try {
            $downloaded = $this->images(array_slice($urls, 0, 30), 'photo', $dir, $budget, $hashes, $warnings, null, false);
            if (!$downloaded) throw new RuntimeException('Nessuna immagine valida scaricata dal fornitore. Le foto esistenti sono rimaste invariate.');
            $picked = app(AutogenesiImageSelection::class)->select($downloaded, $dir, $product->only(['brand', 'model', 'year_from', 'year_to']));
            if (!$picked) throw new RuntimeException('Nessuna foto dei tipi richiesti riconosciuta. Le immagini esistenti sono conservate.');
            $added = \Illuminate\Support\Facades\DB::transaction(function () use ($product, $picked, $dir, &$written, $normalize) {
                $current = $product->locked(); $images = $current->images; $added = 0;
                $hashes = collect($images)->flatMap(fn ($im) => [$im['sha256'], $im['original']['sha256'] ?? ''])->all();
                $sources = collect($images)->flatMap(fn ($im) => [$im['source_url'] ?? '', $im['original']['source_url'] ?? ''])->filter()->map($normalize)->all();
                $counts = ['prodotto' => 0, 'accessori' => 0, 'cruscotto_prima_dopo' => 0];
                foreach ($picked as $im) {
                    $role = $im['role'];
                    if ($counts[$role] >= ($role === 'prodotto' ? 3 : 1)) continue;
                    $photo = app(AutogenesiSquareImage::class)->original($dir.'/'.$im['file']);
                    if (in_array($photo['sha256'], $hashes, true) || in_array($normalize($im['source_url']), $sources, true)) continue;
                    $path = 'autogenesi/products/'.$current->source_id.'/reimport-'.Str::uuid().'.'.$photo['extension'];
                    if (!Storage::disk('local')->put($path, $photo['bytes'])) throw new RuntimeException('Salvataggio foto non riuscito.');
                    $written[] = $path; $hashes[] = $photo['sha256']; $sources[] = $normalize($im['source_url']);
                    $images[] = ['path' => $path, 'sha256' => $photo['sha256'], 'source_url' => $im['source_url'], 'role' => $role,
                        'width' => $photo['width'], 'height' => $photo['height'], 'square_cropped' => false, 'dashboard_selected' => false];
                    $added++; $counts[$role]++;
                }
                if ($added) $current->update(['images' => $images]);
                return $added;
            });
            if (count($urls) > 30) $warnings[] = 'Selezione effettuata sulle prime 30 immagini candidate.';
            return ['added' => $added, 'warnings' => array_unique($warnings)];
        } catch (Throwable $e) {
            Storage::disk('local')->delete($written); throw $e;
        } finally { File::deleteDirectory($dir); }
    }

    private function images(array $urls, string $prefix, string $dir, int &$budget, array &$hashes, array &$warnings, ?string $run, bool $guard = true): array
    {
        $out = [];
        foreach ($urls as $url) {
            app(ProductSheetProcessing::class)->check();
            if ($guard) app(AutogenesiRunControl::class)->assertActive($run);
            if (str_starts_with($url, '//')) $url = 'https:'.$url;
            if (str_starts_with($url, 'http://')) $url = 'https://'.substr($url, 7);
            $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
                || !preg_match('/(?:^|\.)(?:alicdn\.com|aliexpress-media\.com)$/D', $host)) continue;
            $file = $dir.'/'.$prefix.'-'.count($out).'.bin';
            try {
                $r = Http::connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false, 'sink' => $file,
                    'on_headers' => function ($response) { if ((int) $response->getHeaderLine('Content-Length') > 8 * 1024 * 1024) throw new RuntimeException('Immagine troppo grande.'); },
                    'progress' => function ($total, $downloaded) { app(ProductSheetProcessing::class)->check(); if ($downloaded > 8 * 1024 * 1024) throw new RuntimeException('Immagine troppo grande.'); },
                ])->get($url);
                if (!is_file($file) && $r->successful()) file_put_contents($file, $r->body());
                if (!$r->successful() || !is_file($file) || filesize($file) > 8 * 1024 * 1024) throw new RuntimeException('Immagine non scaricabile.');
                $info = @getimagesize($file);
                if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || min($info[0], $info[1]) < 200 || $info[0] * $info[1] > 25000000) throw new RuntimeException('Formato immagine API non valido.');
                $size = filesize($file);
                if ($budget + $size > 35 * 1024 * 1024) throw new RuntimeException('Limite immagini API raggiunto.');
                $hash = hash_file('sha256', $file);
                if (isset($hashes[$hash])) { unlink($file); continue; }
                $hashes[$hash] = true; $budget += $size;
                $out[] = ['file' => basename($file), 'source_url' => $url];
            } catch (Throwable) {
                if (is_file($file)) unlink($file);
                $warnings[] = 'Una o più immagini API non sono state acquisite: verificare le foto e le specifiche della bozza.';
            }
        }
        app(ProductSheetProcessing::class)->check();
        return $out;
    }

    private function norm(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));
    }
}
