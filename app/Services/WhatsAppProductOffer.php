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

    public function make(array $context, array $messages, bool $requiredLink = false): ?string
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $rules = $settings->get();
        if (! $requiredLink && ! $rules['direct_offers']) {
            return null;
        }
        $vehicle = $context['vehicle'];
        $universal = $context['universal'] ?? false;
        if (! $universal && (empty($vehicle['brand']) || empty($vehicle['model']) || empty($vehicle['year']) || empty($context['matching_specific_screens']))) {
            return null;
        }
        $text = mb_strtolower(implode(' ', $messages));
        $last = mb_strtolower(end($messages) ?: '');
        if (preg_match('/\b(camera|telecamera|retrocamera|altoparlanti|speakers|altavoces)\b/u', $last)) {
            return null;
        }
        $locale = $settings->locale($messages);
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
            if ($price === null && $rules['show_prices'] && ! $requiredLink) {
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
            if ($rules['show_prices'] && $price !== null) {
                $formatted = number_format($price, floor($price) === $price ? 0 : 2, ',', '.');
                $line .= ' — '.$copy[2].' '.$formatted.' €';
            }
            if ($requiredLink || $rules['show_links']) {
                $line .= "\n".$url;
            }
            $lines[] = $line;
            if (count($lines) === $rules['max_products']) {
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
            .implode("\n\n", $lines).$fit.($rules['show_prices'] && $rules['price_disclaimer'] ? "\n\n".$copy[3] : '');
    }
}
