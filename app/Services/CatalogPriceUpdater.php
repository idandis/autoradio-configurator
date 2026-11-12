<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Facades\DB;

class CatalogPriceUpdater
{
    public function isRadio(ConfiguratorProduct $p): bool
    {
        return $p->category === 'screen' && strtoupper((string) ($p->meta['type'] ?? '')) !== 'DASHCAM'
            && !preg_match('/^(?:DVR\s|Dashcam\s|Marco\s|Donation$)/i', $p->title);
    }

    public function sources(ConfiguratorProduct $p): array
    {
        $meta = $p->meta ?? [];
        $urls = [$meta['autogenesi']['source_url'] ?? '', $meta['source_url'] ?? '',
            ...($meta['autogenesi']['additional_source_urls'] ?? []), ...($meta['additional_source_urls'] ?? [])];
        if (empty($meta['supplier_replacement']) && preg_match('/-ae-(\d+)$/', $p->handle, $m)) $urls[] = 'https://es.aliexpress.com/item/'.$m[1].'.html';
        $ids = [];
        foreach ($urls as $url) {
            $u = is_string($url) ? parse_url($url) : false;
            if ($u && in_array($u['scheme'] ?? '', ['https', 'http'], true) && !isset($u['user']) && !isset($u['pass'])
                && preg_match('/(^|\.)aliexpress\.com$/i', $u['host'] ?? '') && preg_match('~^/item/(\d+)\.html$~', $u['path'] ?? '', $m)) $ids[] = $m[1];
        }
        return array_values(array_unique($ids));
    }

    public function offers(array $product, string $source): array
    {
        $rows = data_get($product, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
        if (isset($rows['sku_id'])) $rows = [$rows];
        $profile = app(RadioVariantConfigurations::class)->profile($product);
        $out = [];
        foreach ($rows as $r) {
            $cost = $r['offer_sale_price'] ?? $r['sku_price'] ?? null;
            if (($r['currency_code'] ?? '') !== 'EUR' || !is_numeric($cost) || $cost <= 0 || $cost > 999999
                || (isset($r['sku_available_stock']) && (int) $r['sku_available_stock'] <= 0)
                || in_array($r['sku_stock'] ?? true, [false, 'false', 0, '0'], true)) continue;
            $props = data_get($r, 'ae_sku_property_dtos.ae_sku_property_d_t_o', []);
            if (isset($props['sku_property_id'])) $props = [$props];
            $label = implode(' · ', array_filter(array_map(fn ($p) => trim($p['property_value_definition_name'] ?? '') ?: trim($p['sku_property_value'] ?? ''), $props))) ?: (string) ($r['sku_attr'] ?? '');
            if ($this->excludedVariant($label)) continue;
            $out[] = ['source_id' => $source, 'sku_id' => (string) ($r['sku_id'] ?? ''),
                'label' => $label, 'configuration_profile' => $profile,
                'attr' => $this->attributes((string) ($r['sku_attr'] ?? '')),
                'cost' => number_format((float) $cost, 2, '.', '')];
        }
        return $out;
    }

    private function attributes(string $sku): string
    {
        preg_match_all('/(?:^|;)\s*(\d+):(-?\d+)/', $sku, $m, PREG_SET_ORDER);
        $pairs = [];
        foreach ($m as $pair) $pairs[] = $pair[1].':'.$pair[2];
        sort($pairs, SORT_STRING);
        return implode(';', array_unique($pairs));
    }

    public function excludedVariant(string $label): bool
    {
        return (bool) preg_match('/\bDVR\b|360/iu', $label);
    }

    public function eligibleRecreatedVariant(string $label): bool
    {
        return trim($label) !== '' && !$this->excludedVariant($label);
    }

    public function recreatedVariantName(string $label, ?string $profile = null): ?string
    {
        if (!$this->eligibleRecreatedVariant($label)) return null;
        if (preg_match('/\b(\d{1,2})\s*G(?:B)?\s*RAM\b/iu', $label, $ramMatch)
            && preg_match('/\b(16|32|64|128|256|512|1024)\s*G(?:B)?\s*ROM\b/iu', $label, $romMatch)) {
            $ram = (int) $ramMatch[1]; $rom = (int) $romMatch[1];
        } elseif (preg_match('/\b(\d{1,2})\s*(?:G(?:B)?)?\s*[-+\/ ,·]+\s*(16|32|64|128|256|512|1024)\s*G(?:B)?\b/iu', $label, $memory)) {
            $ram = (int) $memory[1]; $rom = (int) $memory[2];
        } else return null;
        if ($ram < 1 || $ram > 32 || $rom <= $ram) return null;
        $cores = $this->explicitCores($label);
        if ($cores === null && $profile === RadioVariantConfigurations::PROFILE) {
            $code = app(RadioVariantConfigurations::class)->code($label);
            $cores = $code === 'P1L' ? 4 : app(RadioVariantConfigurations::class)->resolve($label, $profile, [$ram, $rom], null)['cores'];
        }
        return ($cores ? $cores.'Core ' : '').$ram.'G '.$rom.'G';
    }

    private function memory(string $label): ?array
    {
        if (!preg_match('/\b(\d{1,2})\s*(?:G(?:B)?)?\s*[-+\/ ,]+\s*(\d{2,4})\s*G(?:B)?\b/iu', $label, $m)) return null;
        return (int) $m[1] >= 1 && (int) $m[1] <= 32 ? [(int) $m[1], (int) $m[2]] : null;
    }

    private function cores(string $label, ?string $profile = null): ?int
    {
        if (($cores = $this->explicitCores($label)) !== null) return $cores;
        if ($profile === RadioVariantConfigurations::PROFILE && app(RadioVariantConfigurations::class)->code($label) === 'P1L') return null;
        if (preg_match('/^\s*([PS])\d[\pL\pN]*/iu', $label, $m)) return strtoupper($m[1]) === 'P' ? 4 : 8;
        return null;
    }

    private function explicitCores(string $label): ?int
    {
        if (preg_match('/\b([48])\s*(?:CORES?|N[ÚU]CLEOS?|NUCLEI)\b/iu', $label, $m)) return (int) $m[1];
        if (preg_match('/\b(OCHO|EIGHT|OTTO|OITO|CUATRO|FOUR|QUATTRO)\s+(?:CORES?|N[ÚU]CLEOS?|NUCLEI)\b/iu', $label, $m))
            return in_array(mb_strtoupper($m[1]), ['OCHO', 'EIGHT', 'OTTO', 'OITO'], true) ? 8 : 4;
        return null;
    }

    private function nearestWarehouse(array $matches): array
    {
        if (count($matches) < 2) return $matches;
        $countries = ['España|Espana|Spain|Spagna', 'Portugal|Portogallo', 'France|Francia', 'Italy|Italia', 'Germany|Alemania|Germania', 'Belgium|Bélgica|Belgio', 'Netherlands|Países Bajos|Olanda', 'Czech Republic|República Checa|Repubblica Ceca', 'Poland|Polonia', 'United Kingdom|Reino Unido|Regno Unito', 'China|Cina'];
        $ranked = []; $names = [];
        foreach ($matches as $o) {
            $rank = null; $name = $o['label'];
            foreach ($countries as $i => $country) {
                $pattern = '/\b(?:'.$country.')\b/iu';
                if (preg_match($pattern, $name)) { $rank = $i; $name = preg_replace($pattern, '', $name); break; }
            }
            if ($rank === null) return $matches;
            $names[] = preg_replace('/[^\pL\pN]+/u', '', mb_strtolower($name));
            $ranked[$rank][] = $o;
        }
        if (count(array_unique($names)) !== 1) return $matches;
        ksort($ranked);
        return reset($ranked);
    }

    private function preferPlainOffer(array $matches, string $local): array
    {
        if (count($matches) < 2) return $matches;
        $features = ['CAM(?:ERA)?|C[AÁ]MARA', 'AHD\w*', 'HDT\w*', 'DVR', '360', 'AI', '2K', '4K', 'QLED', 'OLED', '4G', 'DAB\+?', 'TPMS', 'OBD\w*'];
        $scores = [];
        foreach ($matches as $i => $offer) {
            $scores[$i] = 0;
            foreach ($features as $feature) {
                $pattern = '/\b(?:'.$feature.')\b/iu';
                if (!preg_match($pattern, $local) && preg_match($pattern, $offer['label'] ?? '')) $scores[$i]++;
            }
        }
        $best = min($scores);
        return array_values(array_filter($matches, fn ($offer, $i) => $scores[$i] === $best, ARRAY_FILTER_USE_BOTH));
    }

    private function compatibleNames(string $local, string $supplier, ?string $profile = null): bool
    {
        $rules = app(RadioVariantConfigurations::class);
        $a = $rules->resolve($local, $profile, $this->memory($local), $this->cores($local, $profile));
        $b = $rules->resolve($supplier, $profile, $this->memory($supplier), $this->cores($supplier, $profile));
        if ($a['conflict'] || $b['conflict'] || ($a['code'] && $b['code'] && $a['code'] !== $b['code'])) return false;
        if (in_array($b['code'], ['T0', 'P1L'], true) && $a['cores'] !== null && $b['cores'] === null) return false;
        if (in_array($a['code'], ['T0', 'P1L'], true) && $b['cores'] !== null && $a['cores'] === null) return false;
        $memory = $a['memory'];
        if ($this->excludedVariant($local) || $this->excludedVariant($supplier)) return false;
        if (!$memory) {
            $normalize = fn ($name) => trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($name)));
            return $normalize($local) !== '' && $normalize($local) === $normalize($supplier);
        }
        if ($memory !== $b['memory']) return false;
        foreach (['2K', '4K', 'QLED', 'OLED', 'HIGH', 'LOW', 'PRO'] as $feature) {
            if (preg_match('/\b'.$feature.'\b/i', $local) && !preg_match('/\b'.$feature.'\b/i', $supplier)) return false;
        }
        if (preg_match('/\b(?:CAM|CAMERA|CÁMARA)\b/iu', $local) && !preg_match('/\b(?:CAM|CAMERA|CÁMARA)\b/iu', $supplier)) return false;
        return $a['cores'] === null || $b['cores'] === null || $a['cores'] === $b['cores'];
    }

    public function apply(int $productId, array $offers, ?callable $guard = null, ?int $variantId = null, bool $resetPrice = false, bool $byName = false): array
    {
        return DB::transaction(function () use ($productId, $offers, $guard, $variantId, $resetPrice, $byName) {
            if ($guard) $guard();
            $p = ConfiguratorProduct::lockForUpdate()->findOrFail($productId);
            if (!$this->isRadio($p)) throw new \RuntimeException('Questa regola prezzo è riservata alle autoradio. Camere e altre categorie escluse.');
            if (app(RadioVariantConfigurations::class)->knownBrand($p->title)) foreach ($offers as &$offer) $offer['configuration_profile'] ??= RadioVariantConfigurations::PROFILE;
            unset($offer);
            $sources = $this->sources($p);
            $variants = $p->variants()->when($variantId !== null, fn ($q) => $q->whereKey($variantId))->lockForUpdate()->get()->sortBy(fn ($v) => $this->variantOrder($v))->values();
            $results = []; $raised = 0; $unchanged = 0; $skipped = 0;
            $candidates = []; $uses = []; $methods = [];
            foreach ($variants as $v) {
                $meta = $v->meta ?? [];
                $source = (string) ($meta['source_product_id'] ?? '');
                $sourceSku = (string) ($meta['source_sku_id'] ?? '');
                $attr = $this->attributes((string) $v->sku);
                $matches = !empty($meta['supplier_match_required']) ? [] : array_values(array_filter($offers, fn ($o) => in_array($o['source_id'], $sources, true)
                    && (!$source || $source === $o['source_id'])
                    && ($sourceSku ? $sourceSku === $o['sku_id'] : ($attr !== '' && $attr === $o['attr']))));
                $methods[$v->id] = $sourceSku ? 'source_sku_id' : 'sku_attributes';
                if (empty($meta['supplier_match_required'])) {
                    $title = $v->title ?: $v->option_value ?: '';
                    if (!$sourceSku) $matches = array_values(array_filter($matches, fn ($o) => !$this->memory($title) || !$this->memory($o['label'] ?? '') || $this->compatibleNames($title, $o['label'], $o['configuration_profile'] ?? null)));
                    if (count($matches) !== 1) {
                        $matches = array_values(array_filter($offers, fn ($o) => in_array($o['source_id'], $sources, true) && (!$source || $source === $o['source_id']) && $this->compatibleNames($title, $o['label'] ?? '', $o['configuration_profile'] ?? null)));
                        $methods[$v->id] = $this->memory($title) ? 'memory_features' : 'exact_variant_name';
                    }
                }
                if ($byName) {
                    $title = $v->title ?: $v->option_value ?: '';
                    $matches = array_values(array_filter($offers, fn ($o) => in_array($o['source_id'], $sources, true) && $this->compatibleNames($title, $o['label'] ?? '', $o['configuration_profile'] ?? null)));
                    $methods[$v->id] = $this->memory($title) ? 'memory_features' : 'exact_variant_name';
                }
                $matches = $this->preferPlainOffer($matches, $v->title ?: $v->option_value ?: '');
                $matches = $this->nearestWarehouse($matches);
                $candidates[$v->id] = $matches;
                if (count($matches) === 1) {
                    $key = $matches[0]['source_id'].':'.($matches[0]['sku_id'] ?: $matches[0]['attr']);
                    $uses[$key] = ($uses[$key] ?? 0) + 1;
                }
            }
            foreach ($variants as $v) {
                $meta = $v->meta ?? []; $matches = $candidates[$v->id];
                $base = ['id' => $v->id, 'title' => $v->title ?: $v->option_value, 'sku' => $v->sku, 'old_price' => $v->price];
                if (count($matches) !== 1 || ($uses[$matches[0]['source_id'].':'.($matches[0]['sku_id'] ?: $matches[0]['attr'])] ?? 0) > 1) {
                    $results[] = $base + ['status' => 'skipped', 'reason' => count($matches) ? 'Corrispondenza ambigua o identificativi locali duplicati; prezzo invariato.' : 'Nessuna corrispondenza certa tramite SKU; prezzo invariato.'];
                    $skipped++; continue;
                }
                $o = $matches[0]; $quote = app(AutogenesiPrice::class)->quote($o['cost']);
                if (!$resetPrice && ($v->price === null || !is_numeric($v->price))) {
                    $results[] = $base + ['status' => 'skipped', 'reason' => 'Prezzo di vendita mancante: da impostare manualmente.'];
                    $skipped++; continue;
                }
                $increase = (float) $v->price < (float) $quote['selling_price'];
                $new = $resetPrice || $increase ? $quote['selling_price'] : $v->price;
                $status = $increase ? 'raised' : ((float) $new !== (float) $v->price ? 'recalculated' : 'unchanged');
                $audit = ['checked_at' => now()->toIso8601String(), 'old_price' => $v->price, 'new_price' => $new, 'match_method' => $methods[$v->id],
                    'source_product_id' => $o['source_id'], 'source_sku_id' => $o['sku_id'], 'currency' => 'EUR', 'pricing' => $quote, 'mode' => $resetPrice ? 'reset_to_rule' : 'increase_only',
                    'configuration_profile' => $o['configuration_profile'] ?? null, 'configuration_code' => isset($o['configuration_profile']) ? app(RadioVariantConfigurations::class)->code($o['label']) : null];
                $meta['variant_cost'] = $o['cost'];
                $meta['source_sku_id'] = $o['sku_id']; $meta['source_product_id'] = $o['source_id'];
                $meta['price_update'] = $audit;
                $meta['supplier_variant_label'] = $o['label'] ?? '';
                if ($byName) unset($meta['supplier_match_required']);
                $v->update(['price' => $new, 'meta' => $meta]);
                $results[] = $base + ['status' => $status, 'cost' => $o['cost'], 'minimum' => $quote['selling_price'], 'new_price' => $new,
                    'supplier_label' => $o['label'] ?? '', 'match_method' => $methods[$v->id]];
                if ($increase) $raised++;
                elseif ($status === 'unchanged') $unchanged++;
            }
            if ($raised || $resetPrice) $p->update(['price_min' => $p->variants()->min('price')]);
            return ['variants' => $results, 'supplier_variants' => count($offers), 'raised' => $raised, 'unchanged' => $unchanged, 'skipped' => $skipped];
        });
    }

    public function variantOrder($v): array
    {
        $label = $v->title ?: $v->option_value ?: '';
        if (preg_match('/\b(\d+)\s*[-\/]\s*(\d+)\s*G(?:B)?\b/i', $label, $m)
            || preg_match('/\b(\d+)\s*G(?:B)?\s*[-\/ +,]\s*(\d+)\s*G(?:B)?\b/i', $label, $m)
            || preg_match('/\b(\d+)\s*G(?:B)?\s+(\d+)\s*G(?:B)?\b/i', $label, $m)) return [$v->price === null ? 1 : 0, (float) $v->price, (int) $m[1], (int) $m[2], $v->id];
        return [$v->price === null ? 1 : 0, (float) $v->price, 0, 0, $v->id];
    }
}
