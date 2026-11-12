<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\ConfiguratorVariant;
use App\Services\AutogenesiAliExpressApi;
use App\Services\CatalogPriceUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogVariantMatchController extends Controller
{
    public function show(Request $request, ConfiguratorProduct $product, AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local'), 403);
        $input = $request->validate(['source_url' => 'nullable|string|url|max:2048']);
        $sources = !empty($input['source_url']) ? [$this->sourceId($input['source_url'], $updater)] : array_slice($updater->sources($product), 0, 3);
        $suppliers = []; $errors = [];
        foreach ($sources as $source) {
            try {
                $data = $api->product(auth()->id(), $source);
                $rows = data_get($data, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
                if (isset($rows['sku_id'])) $rows = [$rows];
                $available = array_column($updater->offers($data, $source), null, 'sku_id');
                foreach ($rows as $row) {
                    $props = data_get($row, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
                    if (isset($props['sku_property_id'])) $props = [$props];
                    $names = array_filter(array_map(fn ($p) => trim($p['property_value_definition_name'] ?? '') ?: trim($p['sku_property_value'] ?? ''), $props));
                    if ($updater->excludedVariant(implode(' · ', $names).' '.($row['sku_attr'] ?? ''))) continue;
                    $id = (string) ($row['sku_id'] ?? '');
                    if (!$id) continue;
                    $label = implode(' · ', $names) ?: ($row['sku_attr'] ?? $id);
                    $suppliers[] = ['source_id' => $source, 'sku_id' => $id, 'label' => $label,
                        'cost' => $available[$id]['cost'] ?? null, 'minimum' => isset($available[$id]) ? app(\App\Services\AutogenesiPrice::class)->quote($available[$id]['cost'])['selling_price'] : null,
                        'available' => isset($available[$id]), 'recreate_name' => $updater->recreatedVariantName($label, $available[$id]['configuration_profile'] ?? null),
                        'url' => 'https://es.aliexpress.com/item/'.$source.'.html'];
                }
            } catch (\RuntimeException $e) { $errors[] = $source.': '.$e->getMessage(); }
        }
        return response()->json(['app' => $product->variants->sortBy(fn ($v) => $updater->variantOrder($v))->values()->map(fn ($v) => [
            'id' => $v->id, 'label' => $v->title ?: $v->option_value ?: 'Variante '.$v->id, 'sku' => $v->sku,
            'price' => $v->price, 'source_id' => $v->meta['source_product_id'] ?? null, 'sku_id' => $v->meta['source_sku_id'] ?? null,
            'rule_price' => $v->meta['price_update']['pricing']['selling_price'] ?? null,
            'supplier_label' => $v->meta['supplier_variant_label'] ?? null, 'supplier_cost' => $v->meta['variant_cost'] ?? null,
        ]), 'aliexpress' => $suppliers, 'errors' => $errors])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, ConfiguratorProduct $product, ConfiguratorVariant $variant, AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless((int) $variant->configurator_product_id === $product->id, 404);
        abort_unless($updater->isRadio($product), 422, 'La regola prezzo si applica soltanto alle autoradio.');
        $data = $request->validate(['source_id' => ['required', 'string', 'regex:/^\d{1,30}$/'], 'sku_id' => ['required', 'string', 'regex:/^\d{1,40}$/'], 'replacement_url' => 'nullable|string|url|max:2048']);
        $replacement = !empty($data['replacement_url']) ? $this->sourceId($data['replacement_url'], $updater) : null;
        abort_unless($replacement ? $replacement === $data['source_id'] : in_array($data['source_id'], $updater->sources($product), true), 422, 'Il fornitore non appartiene a questo prodotto.');
        try {
            $offers = $updater->offers($api->product($request->user()->id, $data['source_id']), $data['source_id']);
        } catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        $matches = array_values(array_filter($offers, fn ($o) => $o['sku_id'] === $data['sku_id']));
        if (count($matches) !== 1) throw ValidationException::withMessages(['sku_id' => 'Variante non disponibile con un costo EUR certo. Ricarica le varianti.']);
        $result = DB::transaction(function () use ($product, $variant, $data, $request, $updater, $matches, $replacement) {
            $product = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            abort_unless($updater->isRadio($product), 422);
            if ($replacement) $this->replaceLink($product, $replacement, $variant->id, $updater);
            abort_unless(in_array($data['source_id'], $updater->sources($product), true), 422);
            $current = $variant->newQuery()->lockForUpdate()->findOrFail($variant->id);
            $meta = $current->meta ?? [];
            $meta['supplier_match_previous'] = ['source_product_id' => $meta['source_product_id'] ?? null, 'source_sku_id' => $meta['source_sku_id'] ?? null];
            $meta['source_product_id'] = $data['source_id']; $meta['source_sku_id'] = $data['sku_id'];
            unset($meta['supplier_match_required']);
            $meta['supplier_match_manual'] = ['user_id' => $request->user()->id, 'matched_at' => now()->toIso8601String()];
            $current->update(['meta' => $meta]);
            return $updater->apply($product->id, $matches, null, $variant->id, true);
        });
        return response()->json(['message' => ($replacement ? 'Nuovo link e abbinamento salvati nel DB.' : 'Abbinamento salvato.') . ' Costo aggiornato e prezzo di vendita reimpostato secondo la regola attiva.', 'result' => $result]);
    }

    private function sourceId(string $url, CatalogPriceUpdater $updater): string
    {
        $ids = $updater->sources(new ConfiguratorProduct(['handle' => '', 'meta' => ['source_url' => $url]]));
        if (count($ids) !== 1 || strlen($ids[0]) > 30) throw ValidationException::withMessages(['source_url' => 'Inserisci un link prodotto AliExpress valido: https://es.aliexpress.com/item/123.html']);
        return $ids[0];
    }

    public function attempt(Request $request, ConfiguratorProduct $product, AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local') && $updater->isRadio($product), 403);
        $input = $request->validate(['source_url' => 'nullable|string|url|max:2048']);
        $replacement = !empty($input['source_url']) ? $this->sourceId($input['source_url'], $updater) : null;
        $sources = $replacement ? [$replacement] : array_slice($updater->sources($product), 0, 3);
        $offers = [];
        try {
            foreach ($sources as $source) $offers = [...$offers, ...$updater->offers($api->product($request->user()->id, $source), $source)];
        } catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        $result = DB::transaction(function () use ($request, $product, $updater, $replacement, $offers) {
            $p = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            abort_unless($updater->isRadio($p), 403);
            if ($replacement) $this->replaceLink($p, $replacement, 0, $updater);
            $result = $updater->apply($p->id, $offers, null, null, true, true);
            $matched = array_filter($result['variants'], fn ($v) => $v['status'] !== 'skipped');
            if (!$matched) throw ValidationException::withMessages(['variants' => 'Nessun abbinamento univoco per nome: link e prezzi non modificati. Abbina manualmente.']);
            foreach ($matched as $row) {
                $v = $p->variants()->findOrFail($row['id']); $meta = $v->meta;
                $meta['supplier_match_manual'] = ['user_id' => $request->user()->id, 'matched_at' => now()->toIso8601String(), 'method' => 'assisted_name'];
                $v->update(['meta' => $meta]);
            }
            return $result;
        });
        $matched = count($result['variants']) - $result['skipped'];
        $app = $product->fresh()->variants->sortBy(fn ($v) => $updater->variantOrder($v))->values()->map(fn ($v) => [
            'id' => $v->id, 'label' => $v->title ?: $v->option_value, 'sku' => $v->sku, 'price' => $v->price,
            'source_id' => $v->meta['source_product_id'] ?? null, 'sku_id' => $v->meta['source_sku_id'] ?? null,
            'rule_price' => $v->meta['price_update']['pricing']['selling_price'] ?? null,
            'supplier_label' => $v->meta['supplier_variant_label'] ?? null, 'supplier_cost' => $v->meta['variant_cost'] ?? null,
        ]);
        return response()->json(['message' => "Abbinamento per nome riuscito: {$matched} varianti salvate; {$result['skipped']} da verificare.", 'app' => $app, 'result' => $result]);
    }

    public function recreate(Request $request, ConfiguratorProduct $product, AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless($updater->isRadio($product), 422, 'Ricrea varianti è disponibile solo per le autoradio.');
        $input = $request->validate(['source_url' => 'nullable|string|url|max:2048']);
        $replacement = !empty($input['source_url']) ? $this->sourceId($input['source_url'], $updater) : null;
        $source = $replacement ?: ($updater->sources($product)[0] ?? null);
        if (!$source) throw ValidationException::withMessages(['source_url' => 'Manca un link AliExpress valido.']);
        try { $raw = $api->product($request->user()->id, $source); $offers = $updater->offers($raw, $source); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        $rows = data_get($raw, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
        if (isset($rows['sku_id'])) $rows = [$rows];
        $eligible = [];
        foreach ($offers as $offer) {
            $name = $updater->recreatedVariantName($offer['label'], $offer['configuration_profile'] ?? null);
            if ($offer['sku_id'] === '' || $name === null) continue;
            if (!isset($eligible[$name]) || (float) $offer['cost'] < (float) $eligible[$name]['cost'])
                $eligible[$name] = [...$offer, 'clean_name' => $name];
        }
        $eligible = array_values($eligible);
        if (!$eligible) throw ValidationException::withMessages(['variants' => 'Nessuna variante con RAM, ROM e costo EUR verificabili. Le varianti attuali non sono state modificate.']);
        foreach ($eligible as &$offer) $offer['quote'] = app(\App\Services\AutogenesiPrice::class)->quote($offer['cost']);
        unset($offer);
        usort($eligible, fn ($a, $b) => ((int) $a['quote']['selling_price'] <=> (int) $b['quote']['selling_price'])
            ?: ((float) $a['cost'] <=> (float) $b['cost']) ?: strcmp($a['sku_id'], $b['sku_id']));
        $result = DB::transaction(function () use ($product, $updater, $replacement, $source, $rows, $eligible, $request) {
            $p = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            abort_unless($updater->isRadio($p), 422);
            $old = $p->variants()->lockForUpdate()->get();
            $shopify = $old->filter(fn ($v) => ($v->meta['source_product_id'] ?? null) === $source && !empty($v->meta['source_sku_id']))
                ->keyBy(fn ($v) => (string) $v->meta['source_sku_id']);
            if ($replacement) $this->replaceLink($p, $source, 0, $updater);
            $meta = $p->meta ?? [];
            $meta['variant_recreation_history'][] = ['at' => now()->toIso8601String(), 'source_id' => $source, 'variants' => $old->map->toArray()->all()];
            $p->variants()->delete();
            foreach ($eligible as $offer) {
                $quote = $offer['quote'];
                $label = $offer['clean_name'];
                $p->variants()->create(['title' => $label, 'option_value' => $label, 'sku' => 'AE-'.$source.'-'.$offer['sku_id'],
                    'shopify_variant_id' => $shopify->get($offer['sku_id'])?->shopify_variant_id,
                    'price' => $quote['selling_price'], 'image_url' => $p->image_url,
                    'meta' => ['variant_cost' => $quote['supplier_cost'], 'source_product_id' => $source, 'source_sku_id' => $offer['sku_id'],
                        'recreated_from_supplier' => true,
                        'supplier_variant_label' => $offer['label'],
                        'supplier_match_manual' => ['user_id' => $request->user()->id, 'matched_at' => now()->toIso8601String(), 'method' => 'recreated'],
                        'price_update' => ['checked_at' => now()->toIso8601String(), 'source_product_id' => $source,
                            'source_sku_id' => $offer['sku_id'], 'old_price' => null, 'new_price' => $quote['selling_price'],
                            'currency' => 'EUR', 'pricing' => $quote]]]);
            }
            $p->update(['meta' => $meta, 'price_min' => $p->variants()->min('price')]);
            return ['created' => count($eligible), 'excluded' => count($rows) - count($eligible), 'previous' => $old->count()];
        });
        $app = $product->fresh()->variants->sortBy(fn ($v) => $updater->variantOrder($v))->values()->map(fn ($v) => [
            'id' => $v->id, 'label' => $v->title ?: $v->option_value, 'sku' => $v->sku, 'price' => $v->price,
            'source_id' => $v->meta['source_product_id'] ?? null, 'sku_id' => $v->meta['source_sku_id'] ?? null,
            'rule_price' => $v->meta['price_update']['pricing']['selling_price'] ?? null,
            'supplier_label' => $v->meta['supplier_variant_label'] ?? null, 'supplier_cost' => $v->meta['variant_cost'] ?? null,
        ]);
        return response()->json(['message' => "Varianti ricreate: {$result['created']}. Escluse: {$result['excluded']}. Prezzi ricalcolati dalla regola attiva.", 'app' => $app, ...$result]);
    }

    public function destroy(ConfiguratorProduct $product, ConfiguratorVariant $variant)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless((int) $variant->configurator_product_id === $product->id, 404);
        DB::transaction(function () use ($product, $variant) {
            $current = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            $v = $current->variants()->lockForUpdate()->findOrFail($variant->id);
            if ($current->variants()->count() < 2) throw ValidationException::withMessages(['variant' => 'Deve rimanere almeno una variante.']);
            $meta = $current->meta ?? [];
            $meta['deleted_variants'][] = ['deleted_at' => now()->toIso8601String(), 'variant' => $v->toArray()];
            $v->delete();
            $current->update(['meta' => $meta, 'price_min' => $current->variants()->min('price')]);
        });
        return response()->json(['message' => 'Variante eliminata dal database locale.']);
    }

    public function rename(Request $request, ConfiguratorProduct $product, ConfiguratorVariant $variant)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless((int) $variant->configurator_product_id === $product->id, 404);
        $data = $request->validate(['title' => 'required|string|max:255']);
        $title = trim($data['title']);
        if ($title === '') throw ValidationException::withMessages(['title' => 'Inserisci il nome variante.']);
        DB::transaction(function () use ($product, $variant, $title) {
            $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            $variant->newQuery()->whereKey($variant->id)->update(['title' => $title, 'option_value' => $title]);
        });
        return response()->json(['message' => 'Nome variante salvato nel DB.', 'title' => $title]);
    }

    public function createManual(Request $request, ConfiguratorProduct $product, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local') && $updater->isRadio($product), 403);
        $data = $request->validate(['title' => 'required|string|max:255', 'price' => 'nullable|numeric|min:0|max:999999.99']);
        $title = trim($data['title']);
        if ($title === '') throw ValidationException::withMessages(['title' => 'Inserisci il nome variante.']);
        $variant = DB::transaction(function () use ($product, $title, $data) {
            $p = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            $v = $p->variants()->create(['title' => $title, 'option_value' => $title,
                'sku' => 'MANUAL-'.$p->id.'-'.Str::uuid(), 'price' => $data['price'] ?? null,
                'image_url' => $p->image_url, 'meta' => ['created_manually' => true]]);
            $p->update(['price_min' => $p->variants()->min('price')]);
            return $v;
        });
        return response()->json(['message' => 'Variante aggiunta nel DB. Puoi abbinarla al fornitore in seguito.',
            'app_variant' => ['id' => $variant->id, 'label' => $variant->title, 'sku' => $variant->sku,
                'price' => $variant->price, 'rule_price' => null, 'supplier_label' => null,
                'supplier_cost' => null, 'source_id' => null, 'sku_id' => null]]);
    }

    public function create(Request $request, ConfiguratorProduct $product, AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless($updater->isRadio($product), 422, 'La regola prezzo si applica soltanto alle autoradio.');
        $data = $request->validate(['source_id' => ['required', 'string', 'regex:/^\d{1,30}$/'], 'sku_id' => ['required', 'string', 'regex:/^\d{1,40}$/'],
            'replacement_url' => 'nullable|string|url|max:2048', 'title' => 'nullable|string|max:255']);
        $replacement = !empty($data['replacement_url']) ? $this->sourceId($data['replacement_url'], $updater) : null;
        abort_unless($replacement ? $replacement === $data['source_id'] : in_array($data['source_id'], $updater->sources($product), true), 422);
        try { $raw = $api->product($request->user()->id, $data['source_id']); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        $matches = array_values(array_filter($updater->offers($raw, $data['source_id']), fn ($o) => $o['sku_id'] === $data['sku_id']));
        if (count($matches) !== 1) throw ValidationException::withMessages(['sku_id' => 'Variante non disponibile con un costo EUR certo.']);
        $label = trim($data['title'] ?? '');
        if ($label === '') {
            $rows = data_get($raw, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
            if (isset($rows['sku_id'])) $rows = [$rows];
            foreach ($rows as $row) if ((string) ($row['sku_id'] ?? '') === $data['sku_id']) {
                $props = data_get($row, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
                if (isset($props['sku_property_id'])) $props = [$props];
                $label = implode(' · ', array_filter(array_map(fn ($p) => trim($p['property_value_definition_name'] ?? '') ?: trim($p['sku_property_value'] ?? ''), $props)));
            }
        }
        $label = mb_substr($label ?: 'Variante AliExpress '.$data['sku_id'], 0, 255);
        $quote = app(\App\Services\AutogenesiPrice::class)->quote($matches[0]['cost']);
        $supplierLabel = $matches[0]['label'] ?? $label;
        $variant = DB::transaction(function () use ($request, $product, $updater, $replacement, $data, $quote, $label, $supplierLabel) {
            $p = $product->newQuery()->lockForUpdate()->findOrFail($product->id);
            abort_unless($updater->isRadio($p), 422);
            if ($p->variants()->where('meta->source_product_id', $data['source_id'])->where('meta->source_sku_id', $data['sku_id'])->exists()) {
                throw ValidationException::withMessages(['sku_id' => 'Questa variante AliExpress è già abbinata a una variante app.']);
            }
            $sku = 'AE-'.$data['source_id'].'-'.$data['sku_id'];
            if ($p->variants()->where('sku', $sku)->exists()) throw ValidationException::withMessages(['sku_id' => 'Variante già presente.']);
            if ($replacement) $this->replaceLink($p, $replacement, 0, $updater);
            abort_unless(in_array($data['source_id'], $updater->sources($p), true), 422);
            $v = $p->variants()->create(['title' => $label, 'option_value' => $label, 'sku' => $sku, 'price' => $quote['selling_price'], 'image_url' => $p->image_url,
                'meta' => ['variant_cost' => $quote['supplier_cost'], 'source_product_id' => $data['source_id'], 'source_sku_id' => $data['sku_id'],
                    'supplier_variant_label' => $supplierLabel,
                    'supplier_match_manual' => ['user_id' => $request->user()->id, 'matched_at' => now()->toIso8601String()],
                    'price_update' => ['checked_at' => now()->toIso8601String(), 'source_product_id' => $data['source_id'], 'source_sku_id' => $data['sku_id'],
                        'old_price' => null, 'new_price' => $quote['selling_price'], 'currency' => 'EUR', 'pricing' => $quote]]]);
            $p->update(['price_min' => $p->variants()->min('price')]);
            return $v;
        });
        return response()->json(['message' => 'Nuova variante salvata nel DB con costo e prezzo calcolato.', 'app_variant' => ['id' => $variant->id,
            'label' => $variant->title, 'sku' => $variant->sku, 'price' => $variant->price, 'rule_price' => $quote['selling_price'],
            'supplier_label' => $supplierLabel, 'supplier_cost' => $quote['supplier_cost'], 'source_id' => $data['source_id'], 'sku_id' => $data['sku_id']]]);
    }

    private function replaceLink(ConfiguratorProduct $product, string $source, int $selected, CatalogPriceUpdater $updater): void
    {
        $oldSources = $updater->sources($product); $previous = $oldSources[0] ?? null;
        if ($previous === $source) return;
        $meta = $product->meta ?? []; $url = 'https://es.aliexpress.com/item/'.$source.'.html';
        $meta['supplier_link_history'][] = ['at' => now()->toIso8601String(), 'source_ids' => $oldSources, 'replaced_by' => $source];
        $meta['source_url'] = $url;
        $others = array_values(array_filter($oldSources, fn ($id) => $id !== $previous && $id !== $source));
        $meta['additional_source_urls'] = array_map(fn ($id) => 'https://es.aliexpress.com/item/'.$id.'.html', $others);
        if (isset($meta['autogenesi'])) {
            $meta['autogenesi']['source_url'] = $url;
            $meta['autogenesi']['additional_source_urls'] = $meta['additional_source_urls'];
        }
        $meta['supplier_replacement'] = ['source_id' => $source, 'at' => now()->toIso8601String()];
        $product->update(['meta' => $meta]);
        foreach ($product->variants()->whereKeyNot($selected)->lockForUpdate()->get() as $variant) {
            $vm = $variant->meta ?? [];
            if (empty($vm['source_product_id']) || (string) $vm['source_product_id'] === $previous) {
                $vm['supplier_match_required'] = true;
                if (empty($vm['source_product_id']) && $previous) $vm['source_product_id'] = $previous;
                $variant->update(['meta' => $vm]);
            }
        }
    }
}
