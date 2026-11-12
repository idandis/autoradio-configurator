<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\DB;

class DsersLinkRecovery
{
    public function recover(array $rows): array
    {
        return DB::transaction(function () use ($rows) {
            $products = ConfiguratorProduct::orderBy('id')->lockForUpdate()->get();
            $matches = []; $unmatched = 0; $ambiguous = 0; $updated = 0; $existing = 0;
            foreach ($rows as $row) {
                $url = $row['supplier_url'];
                if (!preg_match('~^https://(?:[a-z0-9-]+\.)?aliexpress\.com/item/(\d+)\.html$~i', $url, $m)) { $unmatched++; continue; }
                $url = 'https://es.aliexpress.com/item/'.$m[1].'.html';
                $id = $row['shopify_product_id'] ?? '';
                $title = $this->title($row['title_es'] ?? '');
                $found = $products->filter(fn ($p) => $id !== '' && $id !== null
                    ? (string) ($p->meta['shopify_product_id'] ?? '') === (string) $id
                    : ($title !== '' && $this->title($p->title) === $title));
                if ($found->isEmpty()) { $unmatched++; continue; }
                if ($found->count() !== 1) { $ambiguous++; continue; }
                $p = $found->first();
                $matches[$p->id]['product'] = $p;
                $matches[$p->id]['urls'][$url] = $url;
                $matches[$p->id]['method'] = $id ? 'shopify_id' : 'exact_spanish_title';
            }
            foreach ($matches as $match) {
                $p = $match['product']; $meta = $p->meta ?? [];
                if (!empty($meta['source_url']) || !empty($meta['autogenesi']['source_url']) || preg_match('/-ae-\d+$/', $p->handle)) { $existing++; continue; }
                $urls = array_values($match['urls']);
                $meta['source_url'] = $urls[0];
                $meta['additional_source_urls'] = array_values(array_unique([...($meta['additional_source_urls'] ?? []), ...array_slice($urls, 1)]));
                $meta['dsers_recovery'] = ['matched_by' => $match['method'], 'recovered_at' => now()->toIso8601String(), 'supplier_urls' => $urls];
                $p->update(['meta' => $meta]); $updated++;
            }
            return compact('updated', 'existing', 'unmatched', 'ambiguous');
        });
    }

    private function title(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
