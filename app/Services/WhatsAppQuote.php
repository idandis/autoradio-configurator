<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use Illuminate\Support\Str;

class WhatsAppQuote
{
    public function requested(string $text): bool
    {
        return preg_match('/\b(preventivo|presupuesto|quote|quotation)\b/ui', $text)
            || (preg_match('/instal|montagg|montaj|fitting/ui', $text)
                && preg_match('/\b(prezz\w*|cost\w*|preci\w*|prices?|cu[aá]nto|quanto|total\w*|how much)\b/ui', $text));
    }

    private function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', str_replace('gb', 'g', Str::lower(Str::ascii($text))));
    }

    private function choiceInstruction(int $count, string $locale): string
    {
        if ($count < 1) return '';
        $choice = $count === 2 ? ['it' => '1 o 2', 'es' => '1 o 2', 'en' => '1 or 2'][$locale]
            : ($count === 1 ? '1' : ['it' => 'un numero da 1 a ', 'es' => 'un número del 1 al ', 'en' => 'a number from 1 to '][$locale].$count);

        return "\n\n".['it' => 'Per favore, scrivi solo ', 'es' => 'Por favor, escribe solo ', 'en' => 'Please write only '][$locale].$choice.'.';
    }

    public function respond(string $text, array $history, array $context, array $state, string $locale): ?array
    {
        $signature = hash('sha256', json_encode($context['vehicle']));
        $scope = ['installation_zone' => $state['installation_zone'] ?? null,
            'catalog_mode' => ($context['universal'] ?? false) ? 'universal' : 'specific',
            'include_installation' => (bool) (($state['include_installation'] ?? false) || preg_match('/installazion|instalaci[oó]n|installation/ui', $text))];
        if ($context['universal'] ?? false) {
            $scope += array_intersect_key($state, array_flip(['universal_din', 'universal_budget', 'budget_pending']));
        }
        if (preg_match('/\b(solo radio|radio only|senza installazione|sin instalaci[oó]n|without installation)\b/ui', $text)) {
            $scope['include_installation'] = false;
        }
        if (($state['signature'] ?? null) !== $signature) {
            $state = [];
        }
        if ($scope['include_installation'] && (! $scope['installation_zone'] || ($state['field'] ?? '') === 'installation_zone')) {
            preg_match('/\b\d{5}\b/', $text, $postal);
            $zones = InstallationZone::where('active', true)->with('postalCodes')->get()->filter(fn ($z) => $z->matchesLocation($text)
                || ($postal && $z->postalCodes->contains(fn ($p) => $p->postal_code_from <= $postal[0] && $p->postal_code_to >= $postal[0])));
            if ($zones->count() === 1) {
                $scope['installation_zone'] = $zones->first()->id;
            }
        }
        if (! ($state['pending'] ?? false) && ! $this->requested($text)) {
            return null;
        }
        $copy = [
            'it' => ['Preventivo', 'Quale radio preferisci?', 'Quale versione preferisci?', 'Radio', 'Sconto', 'Totale radio', 'Pagamento', 'Solo radio e variante scelta; installazione e accessori esclusi.'],
            'es' => ['Presupuesto', '¿Qué radio prefieres?', '¿Qué versión prefieres?', 'Radio', 'Descuento', 'Total radio', 'Pago', 'Solo radio y versión elegida; instalación y accesorios excluidos.'],
            'en' => ['Quote', 'Which radio would you like?', 'Which version would you like?', 'Radio', 'Discount', 'Radio total', 'Payment', 'Radio and selected version only; installation and accessories excluded.'],
        ][$locale];
        $handles = collect($context['products'])->where(($context['universal'] ?? false) ? 'is_universal_screen' : 'is_specific_screen', true)->pluck('handle');
        $products = ConfiguratorProduct::whereIn('handle', $handles)->get();
        if ($products->count() > 1 && preg_match('/econ[oó]mic\w*|pi[uù] economica|cheapest|lowest.price/ui', $text)) {
            $products = $products->sortBy(function ($p) {
                $variants = app(WhatsAppProductOffer::class)->preferredVariants($p->variants()->get());

                return $variants->whereNotNull('price')->min('price') ?? $p->price_min ?? INF;
            })->take(1);
        }
        $product = $products->firstWhere('handle', $state['product'] ?? '');
        if (! $product && ! ($state['pending'] ?? false)) {
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? '') !== 'assistant') continue;
                preg_match_all('~/products/([^?\s)\]#]+)~u', $message['content'], $links);
                $shown = array_values(array_unique(array_map('rawurldecode', $links[1])));
                if (! $shown) continue;
                if (count($shown) === 1) $product = $products->firstWhere('handle', $shown[0]);
                break;
            }
        }
        if (! $product && $products->count() === 1) {
            $product = $products->first();
        }
        $number = null;
        if (preg_match('/^(?:la |el |opci[oó]n |opzione |versione |version )?([1-9]|10)\s*[.!]?$/ui', trim($text), $m)) {
            $number = (int) $m[1] - 1;
        }
        if (! $product && ($state['field'] ?? '') === 'product' && $number !== null) {
            $product = $products->firstWhere('handle', $state['options'][$number] ?? '');
        }
        if (! $product && ($state['field'] ?? '') === 'product') {
            $query = $this->normalize($text);
            $named = $products->filter(fn ($p) => collect([$p->title, $p->title_it, $p->title_en])->filter()->contains(fn ($title) => strlen($query) >= 8 && str_contains($this->normalize($title), $query)));
            if ($named->count() === 1) $product = $named->first();
        }
        $base = ['context' => $context, 'signature' => $signature];
        if (! $product) {
            $options = $products->take(5)->values();
            $lines = $options->map(fn ($p, $i) => ($i + 1).'. '.$p->localizedTitle($locale))->all();

            $installationText = '';
            if ($scope['include_installation'] && $scope['installation_zone']) {
                $zone = InstallationZone::where('active', true)->with('services')->find($scope['installation_zone']);
                $service = $zone?->services->first(fn ($s) => preg_match('/^(?:pantalla|radio|schermo|screen)$/i', trim($s->name)));
                if ($service) $installationText = ['it' => 'Installazione', 'es' => 'Instalación', 'en' => 'Installation'][$locale].' — '.$zone->name.': '.number_format((float) $service->price, 2, ',', '.')." €\n\n";
            }

            return $base + ['text' => $installationText.$copy[1].($lines ? "\n".implode("\n", $lines) : '').$this->choiceInstruction($options->count(), $locale), 'mode' => 'quote_choice',

                'quote_state' => $scope + ['signature' => $signature, 'pending' => true, 'field' => 'product', 'options' => $options->pluck('handle')->all()]];
        }
        $variants = $product->variants()->get()->reject(fn ($v) => preg_match('/optic fiber|fiber box|fibra|canbus box|cable/i', $v->option_value.' '.$v->title))->values();
        if ($context['universal'] ?? false) {
            $allowed = collect(collect($context['products'])->firstWhere('handle', $product->handle)['variants'] ?? []);
            $variants = $variants->filter(fn ($v) => $allowed->contains(fn ($a) => ($a['id'] && $a['id'] === $v->shopify_variant_id)
                || ($a['option'] === $v->option_value && $a['title'] === $v->title)))->values();
        }
        $ranged = $variants->filter(fn ($v) => preg_match('/\b(\d{2})-(\d{2})\b/', $v->option_value ?: $v->title));
        if ($ranged->count() === $variants->count() && $variants->count() > 1) {
            $variants = $variants->filter(function ($v) use ($context) {
                preg_match('/\b(\d{2})-(\d{2})\b/', $v->option_value ?: $v->title, $m);

                return 2000 + (int) $m[1] <= $context['vehicle']['year'] && 2000 + (int) $m[2] >= $context['vehicle']['year'];
            })->values();
        }
        $variant = $variants->count() === 1 ? $variants->first() : null;
        if (! $variant && (! ($state['pending'] ?? true) || ($state['field'] ?? '') === 'installation_zone') && ! preg_match('/\d+\s*g(?:b)?\b/ui', $text)) {
            $variant = $variants->firstWhere('id', $state['variant_id'] ?? 0);
        }
        if (! $variant && $number !== null && ($state['field'] ?? '') === 'variant') {
            $variant = $variants->firstWhere('id', $state['options'][$number] ?? 0);
        }
        if (! $variant) {
            $query = $this->normalize($text);
            preg_match_all('/(\d+)\s*g(?:b)?\b/ui', $text, $capacities);
            $matches = $variants->filter(function ($v) use ($query, $capacities) {
                $option = $this->normalize($v->option_value ?: $v->title);
                if ($option !== '' && str_contains($query, $option)) {
                    return true;
                }

                return $capacities[1] && collect($capacities[1])->every(fn ($n) => preg_match('/(?<!\d)'.preg_quote($n, '/').'g/', $option));
            });
            if ($matches->count() === 1) {
                $variant = $matches->first();
            }
        }
        if (! $variant && $variants->count() > 1) {
            $year = $context['vehicle']['year'];
            $byYear = $variants->filter(function ($v) use ($year) {
                return preg_match('/\b(\d{2})-(\d{2})\b/', $v->option_value ?: $v->title, $m)
                    && (2000 + (int) $m[1]) <= $year && (2000 + (int) $m[2]) >= $year;
            });
            if ($byYear->count() === 1) {
                $variant = $byYear->first();
            }
        }
        $money = fn ($amount) => number_format($amount / 100, 2, ',', '.').' €';
        if (! $variant && ! preg_match('/\d+\s*g(?:b)?\b|\d+\s*[- ]?core/ui', $text)) {
            $preferred = app(WhatsAppProductOffer::class)->preferredVariants($variants);
            if ($preferred->isNotEmpty()) {
                $variant = $preferred->filter(fn ($v) => $v->price !== null)->sortBy('price')->first();
            }
        }
        if (! $variant && $variants->count() > 1) {
            $options = $variants->take(10)->values();
            $lines = $options->map(fn ($v, $i) => ($i + 1).'. '.($v->option_value ?: $v->title).' — '.($v->price !== null ? number_format((float) $v->price, 2, ',', '.').' €' : '—'))->all();

            return $base + ['text' => $product->localizedTitle($locale)."\n".$copy[2]."\n".implode("\n", $lines).$this->choiceInstruction($options->count(), $locale), 'mode' => 'quote_choice',
                'quote_state' => $scope + ['signature' => $signature, 'pending' => true, 'field' => 'variant', 'product' => $product->handle, 'options' => $options->pluck('id')->all()]];
        }
        if (! $variant && $product->variants()->exists()) {
            return $base + ['text' => $copy[2], 'mode' => 'quote_choice', 'quote_state' => $scope + ['signature' => $signature, 'pending' => true, 'field' => 'variant', 'product' => $product->handle, 'options' => []]];
        }
        $checkout = app(ItalianCheckout::class);
        $items = $checkout->normalizeItems(['items' => [['type' => $variant ? 'variant' : 'product', 'id' => $variant?->id ?? $product->id, 'quantity' => 1]]]);
        $quote = $checkout->quote($items, locale: $locale === 'it' ? 'it' : 'es');
        $installation = null;
        if ($scope['include_installation']) {
            $zone = InstallationZone::where('active', true)->with('services')->find($scope['installation_zone']);
            if (! $zone) {
                return $base + ['text' => ['it' => 'In quale zona vuoi l’installazione? Scrivi la località oppure il codice postale di 5 cifre, ad esempio Telde o 35200.', 'es' => '¿En qué zona quieres la instalación? Escribe la localidad o el código postal de 5 cifras, por ejemplo Telde o 35200.', 'en' => 'In which area would you like installation? Write the town or 5-digit postal code, for example Telde or 35200.'][$locale],
                    'mode' => 'quote_choice', 'quote_state' => $scope + ['signature' => $signature, 'pending' => true, 'field' => 'installation_zone', 'product' => $product->handle, 'variant_id' => $variant?->id]];
            }
            $service = $zone->services->first(fn ($s) => preg_match('/^(?:pantalla|radio|schermo|screen)$/i', trim($s->name)));
            if (! $service) {
                return $base + ['mode' => 'handoff', 'handoff_reason' => 'Richiesto preventivo radio con installazione in '.$zone->name.'; tariffa schermo non configurata.',
                    'text' => ['it' => 'Verifichiamo il preventivo con installazione. Verrai ricontattato.', 'es' => 'Verificaremos el presupuesto con instalación. Te contactaremos.', 'en' => 'We will check your quote including installation and contact you.'][$locale]];
            }
            $installation = ['name' => $service->localizedName($locale), 'zone' => $zone->name, 'amount' => (int) round((float) $service->price * 100)];
        }
        $store = rtrim(config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com', '/');
        $discount = match (true) {
            $quote['subtotal_amount'] >= 90000 => 'Vip', $quote['subtotal_amount'] >= 50000 => 'Pro',
            $quote['subtotal_amount'] >= 30000 => 'Base', default => null,
        };
        $cart = $variant?->shopify_variant_id && ! str_contains($quote['discount_label'] ?? '', 'Offerta')
            ? $store.($locale === 'es' ? '' : '/'.$locale).'/cart/'.$variant->shopify_variant_id.':1'.($discount ? '?discount='.$discount : '') : null;
        $title = $product->localizedTitle($locale);
        $vehicle = $context['vehicle'];
        $lines = [$copy[0].' — '.(($context['universal'] ?? false) ? 'Universal' : $vehicle['brand'].' '.$vehicle['model'].' '.$vehicle['year']), $title];
        if ($variant) {
            $lines[] = $variant->option_value ?: $variant->title;
        }
        $lines[] = $copy[3].': '.$money($quote['subtotal_amount']);
        if ($quote['discount_amount']) {
            $lines[] = $copy[4].': −'.$money($quote['discount_amount']);
        }
        $lines[] = $copy[5].': '.$money($quote['total_amount']);
        if ($installation) {
            $label = ['it' => 'Installazione', 'es' => 'Instalación', 'en' => 'Installation'][$locale];
            $lines[] = $label.' — '.$installation['zone'].': '.$money($installation['amount']);
            $lines[] = ['it' => 'Totale preventivo', 'es' => 'Total presupuesto', 'en' => 'Quote total'][$locale].': '.$money($quote['total_amount'] + $installation['amount']);
            $quote['items'][] = ['title' => $label.' — '.$installation['name'].' — '.$installation['zone'], 'variant_title' => null, 'quantity' => 1, 'total_amount' => $installation['amount']];
            $quote['subtotal_amount'] += $installation['amount'];
            $quote['total_amount'] += $installation['amount'];
        } else {
            $lines[] = $copy[7];
        }
        $lines[] = $store.'/products/'.$product->handle;
        if ($cart) {
            $lines[] = $copy[6].($installation ? ['it' => ' solo radio', 'es' => ' solo radio', 'en' => ' for radio only'][$locale] : '').': '.$cart;
        }

        return $base + ['text' => implode("\n", $lines), 'mode' => 'quote',
            'quote_state' => $scope + ['signature' => $signature, 'pending' => false, 'product' => $product->handle, 'variant_id' => $variant?->id],
            'quote' => $quote, 'payment_url' => $cart, 'installation' => $installation];
    }
}
