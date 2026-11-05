<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImageCrop
{
    public function crop(AutogenesiProduct $product, string $id, int $x, int $y, int $size): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Ritaglio immagini disponibile solo in locale.');
        }
        $image = collect($product->images)->firstWhere('sha256', $id);
        if (! $image) {
            throw new RuntimeException('La foto selezionata non è più presente.');
        }
        $disk = Storage::disk('local');
        $path = $disk->path($image['path']);
        $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || filesize($path) > 16 * 1024 * 1024 || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Immagine non valida.');
        }
        if ($x < 0 || $y < 0 || $size < 32 || $x + $size > $info[0] || $y + $size > $info[1]) {
            throw new RuntimeException('Il quadrato deve rimanere dentro l’immagine.');
        }
        $source = imagecreatefromstring(file_get_contents($path));
        $side = min($size, 1600);
        $result = imagecreatetruecolor($side, $side);
        imagealphablending($result, false);
        imagesavealpha($result, true);
        imagecopyresampled($result, $source, 0, 0, $x, $y, $side, $side, $size, $size);
        ob_start();
        $ok = imagewebp($result, null, 95);
        $bytes = ob_get_clean();
        if (! $ok || ! $bytes) {
            throw new RuntimeException('Ritaglio non riuscito.');
        }
        $target = 'autogenesi/products/'.$product->source_id.'/crop-'.Str::uuid().'.webp';
        if (! $disk->put($target, $bytes)) {
            throw new RuntimeException('Salvataggio ritaglio non riuscito.');
        }
        try {
            DB::transaction(function () use ($product, $id, $target, $bytes, $image, $side, $x, $y, $size) {
                $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
                $images = $current->images;
                $index = array_search($id, array_column($images, 'sha256'), true);
                if ($index === false) {
                    throw new RuntimeException('La foto è cambiata durante il ritaglio.');
                }
                $images[$index] = array_replace($images[$index], ['path' => $target, 'width' => $side, 'height' => $side,
                    'sha256' => hash('sha256', $bytes), 'original' => $image['original'] ?? $image, 'square_cropped' => true,
                    'manually_cropped' => true, 'crop' => compact('x', 'y', 'size')]);
                unset($images[$index]['shopify_file_reference'], $images[$index]['shopify_url'], $images[$index]['public_url']);
                $current->update(['images' => $images]);
            });
        } catch (\Throwable $e) {
            $disk->delete($target);
            throw $e;
        }
        if (isset($image['original']) && $image['path'] !== $image['original']['path']) {
            $disk->delete($image['path']);
        }
    }
}
