<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\Http;

class ShopifyIdSync
{
    public function sync(ConfiguratorProduct $product): array
    {
        $url = rtrim((string) (config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com'), '/');
        $response = Http::acceptJson()->connectTimeout(5)->timeout(12)
            ->get($url.'/products/'.rawurlencode($product->handle).'.js');

        if ($response->status() === 404) return ['status' => 'missing', 'variants' => 0];
        if (! $response->successful()) return ['status' => 'error', 'variants' => 0];

        $data = $response->json();
        if (! is_array($data) || ($data['handle'] ?? null) !== $product->handle
            || ! preg_match('/^\d+$/', (string) ($data['id'] ?? ''))
            || ! is_array($data['variants'] ?? null)) {
            return ['status' => 'error', 'variants' => 0];
        }

        $remote = collect($data['variants'])->filter(fn ($v) => is_array($v) && preg_match('/^\d+$/', (string) ($v['id'] ?? '')));
        $used = $product->variants->pluck('shopify_variant_id')->filter()->mapWithKeys(fn ($id) => [(string) $id => true])->all();
        $updated = 0;
        foreach ($product->variants as $variant) {
            if (filled($variant->shopify_variant_id)) continue;
            $sku = trim((string) $variant->sku);
            $matches = $sku !== '' ? $remote->filter(fn ($v) => trim((string) ($v['sku'] ?? '')) === $sku) : collect();
            if ($matches->count() !== 1) {
                $name = trim((string) ($variant->option_value ?: $variant->title));
                $matches = $name !== '' ? $remote->filter(fn ($v) => trim((string) ($v['option1'] ?? $v['title'] ?? '')) === $name) : collect();
            }
            if ($matches->count() !== 1 && $product->variants->count() === 1 && $remote->count() === 1) $matches = $remote;
            if ($matches->count() !== 1) continue;
            $id = (string) $matches->first()['id'];
            if (isset($used[$id])) continue;
            $variant->update(['shopify_variant_id' => $id]);
            $used[$id] = true;
            $updated++;
        }

        $meta = $product->meta ?? [];
        if (empty($meta['shopify_product_id'])) {
            $meta['shopify_product_id'] = (string) $data['id'];
            $product->update(['meta' => $meta]);
        }

        return ['status' => $updated ? 'updated' : 'unmatched', 'variants' => $updated];
    }
}
