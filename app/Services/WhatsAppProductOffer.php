<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WhatsAppProductOffer
{
    public function accessoryContext(array $history, array $state, array $vehicle): ?array
    {
        $handle = $state['accessory_handle'] ?? null;
        if (! $handle) {
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? '') === 'assistant' && preg_match('~/products/([^?\s]+)~u', $message['content'], $m)) {
                    $handle = rawurldecode($m[1]);
                    break;
                }
            }
        }
        $p = $handle ? ConfiguratorProduct::where('handle', $handle)->where('category', '!=', 'screen')->first() : null;
        if (! $p) {
            return null;
        }

        return ['vehicle' => $vehicle, 'matching_specific_screens' => 0, 'accessory_handle' => $p->handle,
            'products' => [['handle' => $p->handle, 'title' => $p->title, 'category' => $p->category,
                'url' => rtrim(config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com', '/').'/products/'.$p->handle,
                'price_min' => $p->price_min, 'description' => mb_substr(strip_tags($p->localizedDescription()), 0, 3000),
                'variants' => $p->variants()->get()->map(fn ($v) => ['title' => $v->title, 'option' => $v->option_value, 'price' => $v->price])->all()]]];
    }

    public function cameraOffer(string $text, string $locale, array $vehicle): ?array
    {
        if (! preg_match('/\b(camera|c[aá]mara|telecamera|retrocamera)\b/ui', $text)
            || ! preg_match('/\b(hai|avete|cerco|vorrei|vend\w*|aggiung\w*|ten[eé]is|tienen|hay|busco|quiero|a[nñ]adir|have|sell|looking|want|add)\b|^\s*(?:una |un |a )?(?:camera|c[aá]mara|telecamera|retrocamera)\b/ui', $text)
            || preg_match('/inclus|incluid|include|viene con|lleva|in dotazione|compatib|install|instalaci|montagg|funziona|funciona|will it|does it/ui', $text)
            || empty($vehicle['brand']) || empty($vehicle['model']) || empty($vehicle['year'])) return null;
        $context = app(WhatsAppCatalogSearch::class)->search([$text], $vehicle, 'camera');
        $products = collect($context['products']);
        if (preg_match('/posterior|traser|retro|rear|reversing/ui', $text)) {
            $products = $products->filter(fn ($p) => preg_match('/posterior|traser|retro|rear|reversing/ui', implode(' ', $p['titles']).' '.$p['description']));
        }
        if ($products->isEmpty()) return null;
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $lines = [];
        foreach ($products as $p) {
            $v = collect($p['variants'])->filter(fn ($v) => $this->money($v['price'] ?? null) !== null)->sortBy(fn ($v) => $this->money($v['price']))->first();
            $price = $this->money($v['price'] ?? $p['price_min']);
            $line = '• '.$p['titles'][$locale];
            if ($rules['show_prices'] && $price !== null) $line .= ' — '.['it' => 'da', 'es' => 'desde', 'en' => 'from'][$locale].' '.number_format($price, 2, ',', '.').' €';
            $line .= "\n".$p['url'].(! empty($v['id']) ? '?variant='.rawurlencode($v['id']) : '');
            $lines[] = $line;
        }
        $intro = ['it' => 'Telecamere nel catalogo per', 'es' => 'Cámaras del catálogo para', 'en' => 'Catalogue cameras for'][$locale];
        $note = ['it' => 'La compatibilità con l’ingresso video della radio e gli eventuali adattatori va verificata prima dell’acquisto.',
            'es' => 'Antes de comprar hay que comprobar la compatibilidad con la entrada de vídeo de la radio y los posibles adaptadores.',
            'en' => 'Compatibility with the radio’s video input and any adapters must be checked before purchase.'][$locale];
        $context['products'] = $products->values()->all();
        $state = $products->count() === 1 ? ['accessory_handle' => $products->first()['handle']] : [];

        return ['text' => $intro.' '.implode(' ', [$vehicle['brand'], $vehicle['model'], $vehicle['year']]).":\n\n".implode("\n\n", $lines)."\n\n".$note,
            'mode' => 'accessory_offer', 'context' => $context, 'quote_state' => $state,
            'signature' => hash('sha256', json_encode($vehicle))];
    }

    public function namedAccessory(string $text, string $locale, array $vehicle): ?array
    {
        if (preg_match('/compatib|install|montagg|funziona|funciona|will it|does it/ui', $text)) {
            return null;
        }
        $normalize = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($s))));
        $query = ' '.$normalize($text).' ';
        $products = ConfiguratorProduct::where('category', '!=', 'screen')->get()->filter(function ($p) use ($normalize, $query) {
            foreach (array_filter([$p->title, $p->title_it, $p->title_en]) as $title) {
                $words = explode(' ', $normalize($title));
                if (count($words) >= 3 && str_contains($query, ' '.implode(' ', $words).' ')) {
                    return true;
                }
                if (count($words) >= 8 && str_contains($query, ' '.implode(' ', array_slice($words, 0, 8)).' ')) {
                    return true;
                }
            }

            return false;
        });
        if ($products->count() !== 1) {
            return null;
        }
        $p = $products->first();
        $variants = $p->variants()->whereNotNull('price')->orderBy('price')->get();
        $variant = $variants->first();
        $price = $variant?->price ?? $p->price_min;
        $url = rtrim(config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com', '/').'/products/'.rawurlencode($p->handle);
        if ($variant?->shopify_variant_id) {
            $url .= '?variant='.rawurlencode($variant->shopify_variant_id);
        }
        $label = ['it' => 'da', 'es' => 'desde', 'en' => 'from'][$locale];
        $settings = app(WhatsAppAssistantSettings::class)->get();
        $answer = $p->localizedTitle($locale);
        if ($settings['show_prices'] && $price !== null) {
            $answer .= ' — '.$label.' '.number_format((float) $price, 2, ',', '.').' €';
        }
        $answer .= "\n".$url;

        return ['text' => $answer, 'mode' => 'accessory_offer', 'quote_state' => ['accessory_handle' => $p->handle], 'context' => ['vehicle' => $vehicle,
            'matching_specific_screens' => 0, 'products' => [['handle' => $p->handle, 'title' => $p->title, 'url' => $url, 'category' => $p->category]]],
            'signature' => hash('sha256', json_encode($vehicle))];
    }

    public function recommendVariant(string $text, array $context, string $locale, array $history = [], array $state = [], bool $required = false): ?array
    {
        if (! $required && ! preg_match('/consigli|raccomand|propon|sugger|miglior|recomiend|recomend|consej|sugier|suger|mejor|recommend|suggest|propos|best|better/ui', $text)) return null;
        $normalize = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($s))));
        $query = ' '.$normalize($text).' ';
        $screens = collect($context['products'])->filter(fn ($p) => ($p['category'] ?? '') === 'screen');
        $named = $screens->filter(fn ($p) => collect($p['titles'] ?? [$p['title']])->contains(fn ($t) => str_word_count($normalize($t)) >= 5 && str_contains($query, ' '.$normalize($t).' ')));
        $selected = null;
        if (preg_match('/\b(prima|primo|primera|primero|first|seconda|secondo|segunda|segundo|second|terza|terzo|tercera|tercero|third)\b/ui', $text, $m)) {
            $index = match (mb_strtolower($m[1])) {
                'prima', 'primo', 'primera', 'primero', 'first' => 0,
                'seconda', 'secondo', 'segunda', 'segundo', 'second' => 1,
                default => 2,
            };
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? '') !== 'assistant') continue;
                preg_match_all('~/products/([^?\s)\]#]+)~u', $message['content'], $links);
                $handles = array_values(array_unique(array_map('rawurldecode', $links[1])));
                if (! $handles) continue;
                $selected = $screens->firstWhere('handle', $handles[$index] ?? '');
                if (! $selected) return null;
                break;
            }
            if (! $selected) return null;
        }
        if (! $selected && empty($state['product'])) {
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? '') !== 'assistant') continue;
                preg_match_all('~/products/([^?\s)\]#]+)~u', $message['content'], $links);
                $handles = array_values(array_unique(array_map('rawurldecode', $links[1])));
                if (! $handles) continue;
                if (count($handles) === 1) $selected = $screens->firstWhere('handle', $handles[0]);
                break;
            }
        }
        $p = $named->count() === 1 ? $named->first() : ($selected ?? $screens->firstWhere('handle', $state['product'] ?? '')
            ?? ($screens->count() === 1 ? $screens->first() : null));
        if (! $p) return null;
        $v = $this->preferredVariants(collect($p['variants']))->filter(fn ($v) => $this->money($v['price'] ?? null) !== null)->sortBy(fn ($v) => $this->money($v['price']))->first();
        if (! $v) return null;
        $label = trim(($v['title'] ?? '').' '.($v['option'] ?? ''));
        $intro = ['it' => 'Per questa radio consigliamo la variante', 'es' => 'Para esta radio recomendamos la versión', 'en' => 'For this radio we recommend the version'][$locale];
        $reason = ['it' => 'È la variante 8 core più economica disponibile; in assenza di 8 core scegliamo almeno 4 GB di RAM.', 'es' => 'Es la versión de 8 núcleos más económica disponible; si no hay versiones de 8 núcleos, recomendamos al menos 4 GB de RAM.', 'en' => 'We recommend the lowest-priced available 8-core version, or at least 4 GB of RAM if no 8-core version is listed.'][$locale];
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $answer = $intro.' '.$label.'.';
        $eight = preg_match('/8\s*[- ]?core|octa\s*[- ]?core/ui', $label);
        if (! $eight) $reason = ['it' => 'Non risulta una variante 8 core: consigliamo almeno 4 GB di RAM.', 'es' => 'No figura una versión de 8 núcleos: recomendamos al menos 4 GB de RAM.', 'en' => 'No 8-core version is listed: we recommend at least 4 GB of RAM.'][$locale];
        $answer .= "\n".$reason;
        if ($rules['show_prices']) $answer .= "\n".number_format($this->money($v['price']), 2, ',', '.').' €';
        if ($rules['show_links']) $answer .= "\n".$p['url'].(! empty($v['id']) ? '?variant='.rawurlencode($v['id']) : '');

        $variantId = ! empty($v['id']) ? ConfiguratorProduct::where('handle', $p['handle'])->first()?->variants()->where('shopify_variant_id', $v['id'])->value('id') : null;

        return ['text' => $answer, 'mode' => 'standard', 'context' => $context,
            'signature' => hash('sha256', json_encode($context['vehicle'])), 'quote_state' => ['product' => $p['handle'], 'variant_id' => $variantId]];
    }

    public function preferredVariants(Collection $variants): Collection
    {
        $label = fn ($v) => mb_strtolower(is_array($v) ? ($v['option'] ?? '').' '.($v['title'] ?? '') : $v->option_value.' '.$v->title);
        $eight = $variants->filter(fn ($v) => preg_match('/\b(?:8\s*[- ]?core|octa\s*[- ]?core)\b/u', $label($v)));
        if ($eight->isNotEmpty()) {
            return $eight;
        }
        $ram = $variants->filter(function ($v) use ($label) {
            return preg_match('/\b(\d+)\s*g(?:b)?\b/u', $label($v), $m) && (int) $m[1] >= 4;
        });

        return $ram;
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = preg_replace('/[^0-9,.]/', '', (string) $value);
        if (str_contains($s, ',')) {
            $s = str_contains($s, '.') && strrpos($s, '.') > strrpos($s, ',')
                ? str_replace(',', '', $s) : str_replace(',', '.', str_replace('.', '', $s));
        }

        return is_numeric($s) && (float) $s > 0 ? (float) $s : null;
    }

    public function make(array $context, array $messages, bool $requiredLink = false, ?string $locale = null, ?int $limit = null): ?string
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $rules = $settings->get();
        if (! $requiredLink && ! $rules['direct_offers']) {
            return null;
        }
        $vehicle = $context['vehicle'];
        $universal = $context['universal'] ?? false;
        $showPrices = $universal && $rules['show_prices'];
        if (! $universal && (empty($vehicle['brand']) || empty($vehicle['model']) || empty($vehicle['year']) || empty($context['matching_specific_screens']))) {
            return null;
        }
        $text = mb_strtolower(implode(' ', $messages));
        $last = mb_strtolower(end($messages) ?: '');
        if (preg_match('/\b(camera|telecamera|retrocamera|altoparlanti|speakers|altavoces)\b/u', $last)) {
            return null;
        }
        $locale ??= $settings->locale($messages);
        $region = preg_match('/\b(italia|italy)\b/u', $text) ? 'italia'
            : (preg_match('/\b(spagna|spain|españa|espana)\b/u', $text) ? 'spagna' : null);
        $copy = [
            'it' => ['Per', 'Schermo', 'da', 'Prezzi di catalogo indicativi.'],
            'es' => ['Para', 'Pantalla', 'desde', 'Precios de catálogo orientativos.'],
            'en' => ['For', 'Screen', 'from', 'Indicative catalogue prices.'],
        ][$locale];
        $lines = [];
        foreach ($context['products'] as $product) {
            if (empty($product[$universal ? 'is_universal_screen' : 'is_specific_screen'])) {
                continue;
            }
            $variants = collect($product['variants']);
            $preferred = $this->preferredVariants($variants);
            $offers = ($preferred->isNotEmpty() ? $preferred : $variants)->map(function ($v) use ($region) {
                $regional = $region ? $this->money($v['regional_prices'][$region] ?? null) : null;

                return ['price' => $regional ?? $this->money($v['price'] ?? null), 'regional' => $regional !== null, 'id' => $v['id'] ?? null];
            })->filter(fn ($v) => $v['price'] !== null)->sortBy('price');
            if ($offers->contains('regional', true)) {
                $offers = $offers->where('regional', true);
            }
            $offer = $offers->first();
            $price = $offer['price'] ?? $this->money($product['price_min'] ?? null);
            if ($price === null && $showPrices && ! $requiredLink) {
                continue;
            }
            $title = $product['titles'][$locale] ?? $product['title'];
            $label = ! $universal && preg_match('/\b(\d+(?:[,.]\d+)?)\s*["″”]/u', $title, $size)
                ? $copy[1].' '.$size[1].'″' : mb_substr($title, 0, 85);
            $url = $product['url'];
            if (! empty($offer['id'])) {
                $url .= '?variant='.rawurlencode($offer['id']);
            }
            $line = '• '.$label;
            if ($showPrices && $price !== null) {
                $formatted = number_format($price, floor($price) === $price ? 0 : 2, ',', '.');
                $line .= ' — '.$copy[2].' '.$formatted.' €';
            }
            if ($requiredLink || $rules['show_links']) {
                $line .= "\n".$url;
            }
            $lines[] = $line;
            if (count($lines) === ($limit ?? $rules['max_products'])) {
                break;
            }
        }
        if (! $lines) {
            return null;
        }

        $heading = $universal ? ['it' => 'Modelli universali disponibili', 'es' => 'Modelos universales disponibles', 'en' => 'Available universal head units'][$locale]
            : $copy[0].' '.$vehicle['brand'].' '.$vehicle['model'].' '.$vehicle['year'];
        $fit = $universal ? "\n\n".['it' => 'Il montaggio e gli eventuali adattatori vanno verificati per la tua auto.',
            'es' => 'Hay que verificar el montaje y los posibles adaptadores para tu coche.', 'en' => 'Fitment and any adapters need to be checked for your car.'][$locale] : '';

        return $heading.":\n\n"
            .implode("\n\n", $lines).$fit.($showPrices && $rules['price_disclaimer'] ? "\n\n".$copy[3] : '');
    }
}
