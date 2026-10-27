<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Str;

class WhatsAppCatalogSearch
{
    public function universal(array $messages, array $vehicle = []): array
    {
        $text = $this->normalize(implode(' ', array_slice($messages, -3)));
        preg_match('/\b([12])\s*din\b/', $text, $din);
        $products = ConfiguratorProduct::universalScreens()->with('variants')->orderBy('price_min')->get()
            ->filter(fn ($p) => $din ? preg_replace('/\s+/', '', strtoupper($p->meta['din'] ?? '')) === $din[1].'DIN'
                : in_array(preg_replace('/\s+/', '', strtoupper($p->meta['din'] ?? '')), ['1DIN', '2DIN'], true));
        $features = array_filter(['carplay', 'android auto', 'bluetooth', 'gps'], fn ($f) => $this->contains($text, $f));
        if ($features) {
            $products = $products->filter(fn ($p) => collect($features)->every(fn ($f) => $this->contains($this->normalize($p->title.' '.$p->body_html), $f)));
        }

        $results = $products->map(function ($p) use ($features) {
            $product = $this->product($p);
            foreach ($features as $feature) {
                $labels = collect($product['variants'])->filter(fn ($v) => $this->contains($this->normalize($v['option'].' '.$v['title']), $feature));
                if ($labels->isNotEmpty()) {
                    $product['variants'] = $labels->reject(fn ($v) => preg_match('/\b(no|sin|without|senza|not)\b.{0,20}'.preg_quote($feature, '/').'/', $this->normalize($v['option'].' '.$v['title'])))->values()->all();
                    if (! $product['variants']) {
                        return null;
                    }
                }
            }

            return $product;
        })->filter();

        return ['vehicle' => $vehicle, 'universal' => true, 'matching_specific_screens' => 0, 'matching_universal_screens' => $results->count(),
            'products' => $results->take(8)->values()->all()];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }

    private function contains(string $text, string $term): bool
    {
        return $term !== '' && str_contains(' '.$text.' ', ' '.$term.' ');
    }

    public function search(array $messages, array $vehicle = []): array
    {
        $resolver = app(VehicleImageResolver::class);
        $matcher = app(WhatsAppVehicleMatcher::class);
        $rows = ConfiguratorProduct::query()->get()->map(function ($p) use ($resolver) {
            $entries = $resolver->vehicleEntries($p->brand, $p->model);
            if ($p->category === 'screen') {
                $title = $this->normalize($p->title);
                foreach ($entries as $entry) {
                    $brand = preg_quote($this->normalize($entry['brand']), '/');
                    if (preg_match('/\b'.$brand.'\s+([a-z0-9 ]{1,55}?)\s+(?:19|20)\d{2}\b/', $title, $m)
                        && str_starts_with($m[1], $this->normalize($entry['model']).' ')
                        && str_word_count($m[1]) <= 4) {
                        $entries[] = ['brand' => $entry['brand'], 'model' => strtoupper(trim($m[1])), 'title_alias' => true];
                    }
                }
            }

            return ['product' => $p, 'entries' => $entries];
        });
        $entries = $rows->flatMap(fn ($r) => $r['entries']);
        foreach ($vehicle ? array_slice($messages, -1) : $messages as $message) {
            $text = $this->normalize($message);
            $brand = $entries->pluck('brand')->unique()->sortByDesc(fn ($b) => strlen($b))
                ->first(fn ($b) => $this->contains($text, $this->normalize($b)));
            $brand ??= $matcher->brand($text, $entries->pluck('brand')->unique()->values()->all());
            if ($brand && $this->normalize($vehicle['brand'] ?? '') !== $this->normalize($brand)) {
                $keep = empty($vehicle['brand']) || $matcher->brand($this->normalize($vehicle['brand']), [$brand]) === $brand;
                $vehicle = $keep ? array_replace($vehicle, ['brand' => $brand]) : ['brand' => $brand];
            }
            if (! empty($vehicle['brand'])) {
                $brandEntries = $entries->filter(fn ($e) => $this->normalize($e['brand']) === $this->normalize($vehicle['brand']));
                $model = $brandEntries->pluck('model')->unique()->sortByDesc(fn ($m) => strlen($m))
                    ->first(fn ($m) => ! preg_match('/^(19|20)\d{2}$/', $m) && $this->contains($text, $this->normalize($m)));
                $model ??= $brandEntries->reject(fn ($e) => $e['title_alias'] ?? false)
                    ->pluck('model')->unique()->sortByDesc(fn ($m) => strlen($m))->first(function ($m) use ($text, $vehicle) {
                        $m = $this->normalize($m);
                        if (preg_match('/^(19|20)\d{2}$/', $m)) {
                            return $this->contains($text, $this->normalize($vehicle['brand']).' '.$m)
                                || $this->contains($text, 'modello '.$m) || $this->contains($text, 'modelo '.$m);
                        }

                        return $this->contains($text, $m);
                    });
                if (! $model && ($brand || empty($vehicle['model']) || $text === $this->normalize($vehicle['model']) || preg_match('/\b(?:19|20)\d{2}\b|\b(?:modello|modelo|model)\b/', $text))) {
                    preg_match_all('/\b(?:19|20)\d{2}\b/', $text, $messageYears);
                    $modelYear = $messageYears[0] ? (int) end($messageYears[0]) : ($vehicle['year'] ?? null);
                    $yearModels = $modelYear ? $rows->filter(fn ($r) => $r['product']->year_from !== null && $r['product']->year_to !== null
                        && $r['product']->year_from <= $modelYear && $r['product']->year_to >= $modelYear)
                        ->flatMap(fn ($r) => $r['entries'])->filter(fn ($e) => $this->normalize($e['brand']) === $this->normalize($vehicle['brand']))
                        ->pluck('model')->unique()->values()->all() : null;
                    $resolved = $matcher->model($text, $vehicle['brand'], $brandEntries->pluck('model')->unique()->values()->all(), $yearModels);
                    $model = $resolved['model'];
                    if (! $model && count($resolved['candidates']) > 1) {
                        $vehicle['_model_candidates'] = $resolved['candidates'];
                        unset($vehicle['model']);
                    }
                }
                if ($model) {
                    unset($vehicle['_model_candidates']);
                }
                if ($model && ($vehicle['model'] ?? null) !== $model) {
                    if (! empty($vehicle['model'])) {
                        unset($vehicle['year']);
                    }
                    $vehicle['model'] = $model;
                }
            }
            if (empty($vehicle['brand'])) {
                $model = $entries->pluck('model')->unique()->sortByDesc(fn ($m) => strlen($m))
                    ->first(fn ($m) => ! preg_match('/^(19|20)\d{2}$/', $m) && $this->contains($text, $this->normalize($m)));
                if (! $model) {
                    preg_match_all('/\b(?:19|20)\d{2}\b/', $text, $messageYears);
                    $modelYear = $messageYears[0] ? (int) end($messageYears[0]) : ($vehicle['year'] ?? null);
                    $yearModels = $modelYear ? $rows->filter(fn ($r) => $r['product']->year_from !== null && $r['product']->year_to !== null
                        && $r['product']->year_from <= $modelYear && $r['product']->year_to >= $modelYear)
                        ->flatMap(fn ($r) => $r['entries'])->pluck('model')->unique()->values()->all() : null;
                    $resolved = $matcher->model($text, '', $entries->pluck('model')->unique()->values()->all(), $yearModels);
                    $model = $resolved['model'];
                }
                if ($model && ($vehicle['model'] ?? null) !== $model) {
                    if (! empty($vehicle['model'])) {
                        unset($vehicle['year']);
                    }
                    $vehicle['model'] = $model;
                }
                $knownModel = $this->normalize($vehicle['model'] ?? '');
                if ($knownModel !== '' && ! in_array($knownModel, ['universal', 'one', 'plus', 'radio', 'screen'], true)) {
                    $brands = $entries->filter(fn ($e) => $this->normalize($e['model']) === $knownModel)
                        ->pluck('brand')->filter()->unique(fn ($b) => $this->normalize($b))->values();
                    if ($brands->count() === 1) {
                        $vehicle['brand'] = $brands->first();
                    }
                }
            }
            $yearText = preg_replace('/\b\d{1,2}[-\/.]\d{1,2}[-\/.](?:19|20)\d{2}\b/u', '', $message);
            if (preg_match('/\b((?:19|20)\d{2})\s*[-–—]\s*((?:19|20)\d{2})\b/u', $yearText, $range)) {
                $yearText = $range[1];
            }
            preg_match_all('/\b(?:19|20)\d{2}\b/', $yearText, $years);
            $years = array_values(array_filter($years[0], fn ($y) => $y !== ($vehicle['model'] ?? null)));
            if ($years) {
                $vehicle['year'] = (int) end($years);
            }
        }
        $question = $this->normalize(implode(' ', array_slice($messages, -3)));
        $tokens = collect(explode(' ', $question))->filter(fn ($w) => strlen($w) >= 2)->unique();
        $matches = $rows->filter(function ($row) use ($vehicle) {
            $p = $row['product'];
            if (! empty($vehicle['brand'])) {
                $found = collect($row['entries'])->contains(fn ($e) => $this->normalize($e['brand']) === $this->normalize($vehicle['brand'])
                    && (empty($vehicle['model']) || $this->normalize($e['model']) === $this->normalize($vehicle['model'])));
                if (! $found) {
                    return false;
                }
            }
            if (! empty($vehicle['year']) && (! empty($vehicle['model']))) {
                return $p->year_from !== null && $p->year_to !== null
                    && $p->year_from <= $vehicle['year'] && $p->year_to >= $vehicle['year'];
            }

            return true;
        })->map(function ($row) use ($tokens) {
            $p = $row['product'];
            $name = $this->normalize($p->title.' '.$p->brand.' '.$p->model);
            $row['score'] = $tokens->sum(fn ($w) => $this->contains($name, $w) ? 1 : 0);

            return $row;
        })->filter(fn ($r) => $r['score'] > 0 || ! empty($vehicle['model']));
        $screens = $matches->filter(fn ($r) => $r['product']->category === 'screen' && ($r['product']->meta['type'] ?? '') !== 'DASHCAM');
        $products = $matches->sortByDesc(fn ($r) => ($r['product']->category === 'screen' ? 1000 : 0) + $r['score'])
            ->take(8)->map(fn ($row) => $this->product($row['product']))->values()->all();

        return ['vehicle' => $vehicle, 'matching_specific_screens' => $screens->count(), 'products' => $products];
    }

    private function product(ConfiguratorProduct $p): array
    {
        $variants = $p->variants()->get();
        $store = rtrim(config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com', '/');

        return [
            'handle' => $p->handle, 'url' => $store.'/products/'.rawurlencode($p->handle),
            'titles' => ['it' => $p->localizedTitle('it'), 'en' => $p->localizedTitle('en'), 'es' => $p->title],
            'title' => $p->title, 'category' => $p->category, 'brand' => $p->brand, 'model' => $p->model,
            'is_specific_screen' => $p->category === 'screen' && ($p->meta['type'] ?? '') !== 'DASHCAM' && mb_strtolower(trim($p->model ?? '')) !== 'universal',
            'is_universal_screen' => $p->category === 'screen' && ($p->meta['type'] ?? '') !== 'DASHCAM' && mb_strtolower(trim($p->model ?? '')) === 'universal',
            'price_min' => $p->price_min,
            'year_from' => $p->year_from, 'year_to' => $p->year_to,
            'description' => mb_substr(html_entity_decode(strip_tags($p->body_html ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 1500),
            'variants' => $variants->map(fn ($v) => [
                'id' => $v->shopify_variant_id, 'price' => $v->price,
                'option' => $v->option_value, 'title' => $v->title, 'regional_prices' => $v->meta['regional_prices'] ?? [],
            ])->all(),
        ];
    }
}
