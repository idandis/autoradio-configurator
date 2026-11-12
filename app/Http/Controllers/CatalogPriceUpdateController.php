<?php

namespace App\Http\Controllers;

use App\Jobs\UpdateCatalogPrices;
use App\Models\ConfiguratorProduct;
use App\Models\ProductPriceUpdate;
use App\Services\AutogenesiAliExpressAuth;
use App\Services\AutogenesiRunControl;
use App\Services\AutogenesiWorker;
use App\Services\CatalogPriceUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CatalogPriceUpdateController extends Controller
{
    public function start(Request $request, CatalogPriceUpdater $updater)
    {
        abort_unless(app()->environment('local'), 403);
        try { app(AutogenesiAliExpressAuth::class)->accessToken($request->user()->id); }
        catch (\RuntimeException $e) { throw ValidationException::withMessages(['price_update' => $e->getMessage()]); }
        Cache::lock('catalog-price-start', 15)->block(5, function () use ($request, $updater) {
            if (ProductPriceUpdate::whereIn('status', ['running', 'blocked', 'stopped'])->exists()) {
                throw ValidationException::withMessages(['price_update' => 'Esiste già un aggiornamento: riprendilo o avviane uno nuovo dopo averlo completato.']);
            }
            $items = ConfiguratorProduct::orderBy('id')->get()->filter(fn ($p) => $updater->isRadio($p) && count($updater->sources($p)))
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'status' => 'pending'])->values()->all();
            if (!$items) throw ValidationException::withMessages(['price_update' => 'Nessun prodotto con link AliExpress valido.']);
            app(AutogenesiRunControl::class)->activate();
            $run = ProductPriceUpdate::create(['user_id' => $request->user()->id, 'status' => 'running', 'items' => $items]);
            $run->refresh();
            UpdateCatalogPrices::dispatch($run->id, $run->generation);
        });
        app(AutogenesiWorker::class)->ensure();
        return back();
    }

    public function stop(Request $request, ProductPriceUpdate $run)
    {
        abort_unless(app()->environment('local') && $run->user_id === $request->user()->id, 403);
        if ($run->status === 'running') $run->update(['status' => 'stopped', 'generation' => $run->generation + 1]);
        return back();
    }

    public function resume(Request $request, ProductPriceUpdate $run)
    {
        abort_unless(app()->environment('local') && $run->user_id === $request->user()->id, 403);
        if (!in_array($run->status, ['stopped', 'blocked', 'completed'], true)) return back();
        $updater = app(CatalogPriceUpdater::class);
        $products = ConfiguratorProduct::with('variants')->whereIn('id', array_column($run->items, 'id'))->get()->filter(fn ($p) => $updater->isRadio($p))->keyBy('id');
        $ids = $products->keys()->all();
        $items = array_values(array_filter($run->items, fn ($item) => in_array($item['id'], $ids, true)));
        foreach ($items as &$item) {
            $manual = $products->get($item['id'])->variants->contains(fn ($v) => $this->isManualVariant($v, $run));
            if ($manual) { $item['status'] = 'completed'; unset($item['error']); continue; }
            $variants = collect($item['result']['variants'] ?? []);
            $blue = $item['status'] === 'completed' && $variants->contains(fn ($v) => ($v['status'] ?? '') === 'raised');
            $yellow = $item['status'] === 'completed' && !$blue && $variants->contains(fn ($v) => ($v['status'] ?? '') === 'skipped');
            if ($yellow || $item['status'] === 'running') { $item['status'] = 'pending'; unset($item['error']); }
        }
        unset($item);
        if (!collect($items)->contains(fn ($item) => $item['status'] === 'pending')) {
            $run->update(['status' => 'completed', 'items' => $items, 'error' => null]);
            return back();
        }
        try { app(AutogenesiAliExpressAuth::class)->accessToken($request->user()->id); }
        catch (\RuntimeException $e) { throw ValidationException::withMessages(['price_update' => $e->getMessage()]); }
        app(AutogenesiRunControl::class)->activate();
        $run->update(['status' => 'running', 'generation' => $run->generation + 1, 'items' => $items, 'error' => null]);
        UpdateCatalogPrices::dispatch($run->id, $run->generation);
        app(AutogenesiWorker::class)->ensure();
        return back();
    }

    public function snapshot(int $user): ?array
    {
        if (!app()->environment('local') || !\Illuminate\Support\Facades\Schema::hasTable('product_price_updates')) return null;
        $run = ProductPriceUpdate::where('user_id', $user)->latest('id')->first();
        if (!$run || $run->status === 'archived') return null;
        if ($run->status === 'running' && app(AutogenesiRunControl::class)->state()['stopped']) $run->update(['status' => 'stopped', 'generation' => $run->generation + 1]);
        $counts = ['raised' => 0, 'unchanged' => 0, 'skipped' => 0];
        $products = ConfiguratorProduct::with('variants')->whereIn('id', array_column($run->items, 'id'))->get()->keyBy('id');
        $visible = array_values(array_filter($run->items, fn ($item) => $products->has($item['id'])));
        $items = array_map(function ($item) use ($products, $run) {
            $product = $products->get($item['id']);
            $item['source_urls'] = $product ? array_map(fn ($id) => 'https://es.aliexpress.com/item/'.$id.'.html', app(CatalogPriceUpdater::class)->sources($product)) : [];
            $isUpdated = fn ($v) => $this->isManualVariant($v, $run);
            $item['manual_updated'] = $product && $product->variants->contains($isUpdated);
            if ($item['manual_updated']) {
                $item['status'] = 'manual_updated';
                unset($item['error']);
                $previous = collect($item['result']['variants'] ?? [])->keyBy('id');
                $rows = $product->variants->sortBy(fn ($v) => app(CatalogPriceUpdater::class)->variantOrder($v))->values()->map(function ($v) use ($isUpdated, $previous) {
                    $row = $previous->get($v->id, ['id' => $v->id, 'title' => $v->title ?: $v->option_value, 'old_price' => $v->price, 'status' => 'skipped', 'reason' => 'Variante da verificare.']);
                    if (!$isUpdated($v)) return $row;
                    $audit = $v->meta['price_update'];
                    $byName = in_array($audit['match_method'] ?? '', ['memory_features', 'exact_variant_name'], true);
                    return ['id' => $v->id, 'title' => $v->title ?: $v->option_value, 'status' => $byName ? 'name_matched' : 'manual_matched',
                        'old_price' => $audit['old_price'] ?? '', 'new_price' => $v->price, 'cost' => $v->meta['variant_cost'] ?? null,
                        'minimum' => $audit['pricing']['selling_price'] ?? null, 'supplier_label' => $v->meta['supplier_variant_label'] ?? ''];
                })->all();
                $item['result'] = array_replace($item['result'] ?? ['supplier_variants' => 0], ['variants' => $rows,
                    'skipped' => count(array_filter($rows, fn ($r) => $r['status'] === 'skipped'))]);
            }
            return $item;
        }, $visible);
        foreach ($items as $item) foreach ($counts as $k => $v) $counts[$k] += $item['result'][$k] ?? 0;
        return ['id' => $run->id, 'status' => $run->status, 'error' => $run->error, 'counts' => $counts, 'items' => $items];
    }

    public function reset(Request $request, ProductPriceUpdate $run)
    {
        abort_unless(app()->environment('local') && $run->user_id === $request->user()->id, 403);
        \Illuminate\Support\Facades\DB::transaction(function () use ($run) {
            $current = ProductPriceUpdate::lockForUpdate()->findOrFail($run->id);
            if ($current->status !== 'archived') $current->update(['status' => 'archived', 'generation' => $current->generation + 1]);
        });
        $request->session()->forget('autogenesi_price_research');
        return back();
    }

    private function isManualVariant($v, ProductPriceUpdate $run): bool
    {
        $meta = $v->meta ?? [];
        $at = strtotime($meta['supplier_match_manual']['matched_at'] ?? '');
        return $at !== false && $at >= $run->created_at->timestamp && !empty($meta['price_update'])
            && (string) ($meta['price_update']['source_sku_id'] ?? '') === (string) ($meta['source_sku_id'] ?? '')
            && (string) ($meta['price_update']['source_product_id'] ?? '') === (string) ($meta['source_product_id'] ?? '');
    }
}
