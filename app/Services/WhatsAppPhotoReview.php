<?php

namespace App\Services;

use App\Models\ConfiguratorVariant;
use App\Models\ConfiguratorProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppPhotoReview
{
    public function isPhoto(array $message): bool
    {
        if (($message['hasMedia'] ?? false) !== true) return false;
        $mime = $message['media']['mimetype'] ?? $message['_data']['mimetype'] ?? null;

        return $mime !== null ? in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            : ($message['_data']['type'] ?? $message['type'] ?? '') === 'image';
    }

    public function receive(array $message, string $chat): array
    {
        $session = rawurlencode(config('waha.session'));
        $api = Http::baseUrl(config('waha.url'))->withHeaders(['X-Api-Key' => config('waha.key')])->connectTimeout(5)->timeout(30)->withoutRedirecting();
        $full = $api->get('/api/'.$session.'/chats/'.rawurlencode($chat).'/messages/'.rawurlencode($message['id']), ['downloadMedia' => 'true'])->throw()->json();
        if (! is_array($full) || ($full['id'] ?? null) !== $message['id'] || ! $this->isPhoto($full)
            || ($full['fromMe'] ?? null) !== false || ($full['from'] ?? null) !== $message['from']) {
            throw new RuntimeException('Foto WhatsApp non verificabile.');
        }
        $url = parse_url($full['media']['url'] ?? '');
        $base = parse_url(config('waha.url'));
        $path = $url['path'] ?? '';
        if (! in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true)
            || ($url['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || ($url['port'] ?? 80) !== ($base['port'] ?? 80)
            || ! str_starts_with($path, '/api/files/') || str_contains(rawurldecode($path), '..')) {
            throw new RuntimeException('Percorso foto WhatsApp non valido.');
        }
        $response = $api->get($path)->throw();
        if (! $response->successful()) throw new RuntimeException('Download foto non riuscito.');

        return $this->store($response->body(), hash('sha256', config('waha.session').$chat.$message['id']));
    }

    public function upload(UploadedFile $file): array
    {
        return $this->store(file_get_contents($file->getRealPath()));
    }

    private function store(string $bytes, ?string $id = null): array
    {
        if (strlen($bytes) > 8 * 1024 * 1024) throw new RuntimeException('Foto troppo grande.');
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null) throw new RuntimeException('Formato foto non supportato.');
        $disk = Storage::disk('local');
        foreach ($disk->files('waha/photos') as $old) {
            if ($disk->lastModified($old) < now()->timestamp - 43200) $disk->delete($old);
        }
        $path = 'waha/photos/'.($id ?? Str::uuid()).'.'.$ext;
        if (! Storage::disk('local')->put($path, $bytes)) throw new RuntimeException('Salvataggio foto non riuscito.');

        return ['path' => $path, 'mime' => $mime];
    }

    public function discard(array $photos): void
    {
        foreach ($photos as $photo) {
            if (preg_match('~^waha/photos/[a-f0-9-]+\.(jpg|png|webp)$~', $photo['path'] ?? '')) {
                Storage::disk('local')->delete($photo['path']);
            }
        }
    }

    public function step(string $text, array $history, array $vehicle, array $quoteState, string $phone, array $photos, string $locale): array
    {
        $messages = [...collect($history)->where('role', 'user')->pluck('content')->all(), $text];
        $context = app(WhatsAppCatalogSearch::class)->search($messages, $vehicle);
        $vehicle = $context['vehicle'];
        $result = ['context' => $context, 'photos' => $photos, 'quote_state' => $quoteState,
            'signature' => hash('sha256', json_encode($vehicle))];
        foreach (['brand', 'model', 'year'] as $field) {
            if (! empty($vehicle[$field])) continue;
            $question = [
                'brand' => ['it' => 'Foto ricevuta. Di che marca è la tua auto?', 'es' => 'Foto recibida. ¿De qué marca es tu coche?', 'en' => 'Photo received. What make is your car?'],
                'model' => ['it' => 'Foto ricevuta. Qual è il modello della tua auto?', 'es' => 'Foto recibida. ¿Cuál es el modelo de tu coche?', 'en' => 'Photo received. What model is your car?'],
                'year' => ['it' => 'Foto ricevuta. Di che anno è la tua auto? Scrivi l’anno con 4 cifre, ad esempio 2016.', 'es' => 'Foto recibida. ¿De qué año es tu coche? Escribe el año con 4 cifras, por ejemplo 2016.', 'en' => 'Photo received. What year is your car? Write the 4-digit year, for example 2016.'],
            ][$field][$locale];

            return $result + ['mode' => 'vehicle_details', 'text' => $question];
        }
        $products = collect($context['products'])->where('is_specific_screen', true);
        $selected = $products->firstWhere('handle', $quoteState['product'] ?? '');
        if ($selected) $products = collect([$selected]);
        elseif (($quoteState['catalog_mode'] ?? '') === 'universal' && ! empty($quoteState['product'])) {
            $universal = ConfiguratorProduct::universalScreens()->where('handle', $quoteState['product'])->first();
            if ($universal) $products = collect([['handle' => $universal->handle, 'title' => $universal->localizedTitle('it'),
                'url' => rtrim(config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com', '/').'/products/'.rawurlencode($universal->handle)]]);
        }
        $variant = isset($quoteState['variant_id']) ? ConfiguratorVariant::with('product')->find($quoteState['variant_id']) : null;
        $products = $products->take(8)->map(function ($p) use ($variant) {
            $url = $p['url'];
            if ($variant && $variant->product?->handle === $p['handle'] && $variant->shopify_variant_id) {
                $url .= '?variant='.rawurlencode($variant->shopify_variant_id);
            }

            return ['title' => $p['titles']['it'] ?? $p['title'], 'url' => $url];
        })->values()->all();

        $offer = app(WhatsAppProductOffer::class)->make($context,
            [...$messages, implode(' ', [$vehicle['brand'], $vehicle['model'], $vehicle['year']])], true, $locale, count($context['products']));
        $notice = [
            'it' => 'Foto ricevuta. Un operatore verificherà la compatibilità della radio con la tua auto e ti ricontatterà.',
            'es' => 'Foto recibida. Un operador comprobará la compatibilidad de la radio con tu coche y te contactará.',
            'en' => 'Photo received. A human operator will check the radio’s compatibility with your car and contact you.',
        ][$locale];

        return $result + ['mode' => 'photo_review', 'text' => ($offer ? $offer."\n\n" : '').$notice,
            'alert' => ['phone' => $phone, 'vehicle' => $vehicle, 'question' => $text,
            'products' => $products, 'photos' => $photos,
            'unknown' => ['Verificare manualmente la compatibilità usando la foto del cliente.', ...($products ? [] : ['Nessuna radio individuata nel catalogo per questa auto.'])]]];
    }
}
