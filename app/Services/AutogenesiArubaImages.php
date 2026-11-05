<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AutogenesiArubaImages
{
    public function descriptor(AutogenesiProduct $product, array $image): ?array
    {
        $base = rtrim((string) config('autogenesi.media_base_url'), '/');
        if ($base === '') {
            return null;
        }
        $parts = parse_url($base);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('URL pubblico AutoGenesi non valido.');
        }
        if (! preg_match('/^\d+$/', $product->source_id) || ! preg_match('/^[a-f0-9]{64}$/', $image['sha256'] ?? '')) {
            throw new RuntimeException('Identificativo foto non valido.');
        }
        $path = Storage::disk('local')->path($image['path']);
        $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (! $ext || filesize($path) > 16 * 1024 * 1024 || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Foto non disponibile per Aruba: '.$product->title);
        }
        if (hash_file('sha256', $path) !== $image['sha256']) {
            throw new RuntimeException('Foto modificata: riapri la bozza prima dell’export.');
        }
        $relative = 'autogenesi/catalog/'.$product->source_id.'/'.$image['sha256'].'.'.$ext;

        $public = Storage::disk('public');
        if (! $public->exists($relative) || hash_file('sha256', $public->path($relative)) !== $image['sha256']) {
            if (! $public->put($relative, file_get_contents($path))) throw new RuntimeException('Preparazione foto per FileZilla non riuscita.');
        }

        return ['path' => $path, 'relative' => $relative, 'url' => $base.'/'.$relative, 'sha256' => $image['sha256']];
    }

}
