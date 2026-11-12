<?php

namespace App\Models;

use App\Services\AutogenesiImageLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogProductSheet extends AutogenesiProduct
{
    public static function fromCatalog(ConfiguratorProduct $p): self
    {
        $p->loadMissing('variants');
        $meta = $p->meta ?? [];
        $images = $meta['product_sheet_images'] ?? collect([$p->image_url, ...($meta['gallery_images'] ?? []), ...$p->variants->pluck('image_url')->all(), ...array_column($meta['original_dashboard_images'] ?? [], 'url')])->filter()->unique()->values()->map(function ($url) use ($p, $meta) {
            $id = hash('sha256', $url);
            $dashboard = collect($meta['original_dashboard_images'] ?? [])->firstWhere('url', $url);
            return ['path' => 'catalog-editor/'.$p->id.'/'.$id.'.png', 'sha256' => $id, 'source_url' => $url, 'catalog_url' => $url,
                'role' => $dashboard ? 'cruscotto_originale' : 'prodotto', 'dashboard_selected' => (bool) $dashboard,
                'shopify_file_reference' => $dashboard['shopify_file_reference'] ?? '', 'width' => 0, 'height' => 0];
        })->all();
        $s = new self;
        $s->forceFill(['id' => $p->id, 'source_id' => 'catalog-'.$p->id, 'source_url' => $meta['source_url'] ?? $meta['autogenesi']['source_url'] ?? '',
            'handle' => $p->handle, 'title' => $p->title, 'body_html' => $p->body_html ?? '', 'status' => 'draft',
            'brand' => $p->brand ?? '', 'model' => $p->model ?? '', 'year_from' => $p->year_from, 'year_to' => $p->year_to,
            'images' => $images, 'metadata' => ['dashboard_selection_explicit' => true, 'additional_source_urls' => $meta['additional_source_urls'] ?? $meta['autogenesi']['additional_source_urls'] ?? [], 'vehicle_compatibilities' => $meta['vehicle_compatibilities'] ?? [], 'description_generation' => $meta['description_generation'] ?? null, 'description_source' => $meta['description_source'] ?? null, 'description_notes' => $meta['description_notes'] ?? []],
            'variants' => $p->variants->map(fn ($v) => ['sku' => 'CAT-'.$v->id, 'label' => $v->title ?: $v->option_value ?: $v->sku,
                'supplier_price' => $v->meta['variant_cost'] ?? null, 'currency' => 'EUR', 'selling_price' => $v->price])->all()]);
        $s->exists = true;
        return $s;
    }

    public function locked(): AutogenesiProduct
    {
        return self::fromCatalog(ConfiguratorProduct::lockForUpdate()->findOrFail($this->id));
    }

    public function fresh($with = [])
    {
        return self::fromCatalog(ConfiguratorProduct::findOrFail($this->id));
    }

    public function update(array $attributes = [], array $options = [])
    {
        DB::transaction(function () use ($attributes) {
            $p = ConfiguratorProduct::lockForUpdate()->findOrFail($this->id);
            $meta = $p->meta ?? []; $values = array_intersect_key($attributes, array_flip(['title', 'body_html', 'brand', 'model', 'year_from', 'year_to']));
            if (isset($values['title']) && $values['title'] !== $p->title) $values += ['title_it' => null, 'title_en' => null];
            if (isset($values['body_html']) && $values['body_html'] !== $p->body_html) $values += ['body_html_it' => null, 'body_html_en' => null];
            if (isset($attributes['metadata'])) foreach (['vehicle_compatibilities', 'additional_source_urls', 'description_source', 'description_generation', 'description_notes', 'previous_copy'] as $key) {
                if (array_key_exists($key, $attributes['metadata'])) $meta[$key] = $attributes['metadata'][$key];
            }
            if (array_key_exists('images', $attributes)) {
                $images = $attributes['images']; $gallery = []; $dashboards = []; $replaced = [];
                $oldUrls = collect([$p->image_url, ...($meta['gallery_images'] ?? []), ...$p->variants()->pluck('image_url')->all()])->filter()->unique()->all();
                foreach ($images as &$image) {
                    if (empty($image['catalog_url'])) {
                        $disk = Storage::disk('local');
                        if (!$disk->exists($image['path'])) throw new \RuntimeException('Foto non disponibile.');
                        $ext = pathinfo($image['path'], PATHINFO_EXTENSION);
                        $target = 'autogenesi/catalog/catalog-'.$p->id.'/'.$image['sha256'].'.'.$ext;
                        if (!Storage::disk('public')->put($target, $disk->get($image['path']))) throw new \RuntimeException('Salvataggio foto pubblica non riuscito.');
                        $image['catalog_url'] = '/storage/'.$target;
                    } elseif (!empty($image['original']) && $image['path'] !== $image['original']['path']) {
                        $target = 'autogenesi/catalog/catalog-'.$p->id.'/'.$image['sha256'].'.'.pathinfo($image['path'], PATHINFO_EXTENSION);
                        if (!Storage::disk('public')->put($target, Storage::disk('local')->get($image['path']))) throw new \RuntimeException('Salvataggio foto pubblica non riuscito.');
                        $image['catalog_url'] = '/storage/'.$target;
                    }
                    if (!empty($image['original']['catalog_url'])) $replaced[$image['original']['catalog_url']] = $image['catalog_url'];
                    $gallery[] = $image['catalog_url'];
                    if ($image['dashboard_selected'] ?? false) $dashboards[] = ['url' => $image['catalog_url'], 'variant' => null, 'shopify_file_reference' => $image['shopify_file_reference'] ?? null];
                }
                unset($image);
                $meta['product_sheet_images'] = $images; $meta['gallery_images'] = $gallery; $meta['original_dashboard_images'] = $dashboards;
                $values['image_url'] = $gallery[0] ?? null;
                foreach (array_diff($oldUrls, $gallery) as $old) $p->variants()->where('image_url', $old)->update(['image_url' => $replaced[$old] ?? $gallery[0] ?? null]);
            }
            if (isset($attributes['variants'])) {
                $ids = [];
                foreach ($attributes['variants'] as $v) {
                    $id = str_starts_with($v['sku'], 'CAT-') ? substr($v['sku'], 4) : null;
                    $row = $id ? $p->variants()->findOrFail($id) : $p->variants()->make(['sku' => $v['sku']]);
                    $vm = $row->meta ?? [];
                    if (isset($v['supplier_price'])) $vm['variant_cost'] = $v['supplier_price'];
                    $row->fill(['title' => $v['label'], 'option_value' => $v['label'], 'price' => $v['selling_price'], 'meta' => $vm])->save();
                    $ids[] = $row->id;
                }
                $p->variants()->whereNotIn('id', $ids)->delete();
                $values['price_min'] = $p->variants()->min('price');
            }
            $p->fill($values + ['meta' => $meta])->save();
        });
        $this->setRawAttributes($this->fresh()->getAttributes());
        return true;
    }
}
