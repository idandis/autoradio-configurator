<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImageUpscale
{
    public function upscale(AutogenesiProduct $product, string $id, int $sharpness = 35): void
    {
        $image = collect($product->images)->firstWhere('sha256', $id);
        if (!$image) throw new RuntimeException('La foto selezionata non è più presente.');
        $disk = Storage::disk('local');
        $path = $disk->path($image['path']);
        $info = is_file($path) && !is_link($path) ? @getimagesize($path) : false;
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || filesize($path) > 16 * 1024 * 1024 || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Immagine non valida.');
        }
        $long = max($info[0], $info[1]);
        if ($long >= 2048) throw new RuntimeException('La foto ha già almeno 2048 pixel sul lato più lungo.');
        app(ProductSheetProcessing::class)->check();
        $scale = min(2, 2048 / $long);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $source = @imagecreatefromstring(file_get_contents($path));
        if (!$source) throw new RuntimeException('Immagine non leggibile.');
        $result = imagecreatetruecolor($width, $height);
        imagealphablending($result, false);
        imagesavealpha($result, true);
        imagecopyresampled($result, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        if ($sharpness > 0) {
            $amount = $sharpness / 250;
            imageconvolution($result, [[0, -$amount, 0], [-$amount, 1 + 4 * $amount, -$amount], [0, -$amount, 0]], 1, 0);
        }
        ob_start();
        $ok = imagewebp($result, null, 95);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($result);
        if (!$ok || !$bytes) throw new RuntimeException('Aumento risoluzione non riuscito.');
        app(ProductSheetProcessing::class)->check();
        $target = 'autogenesi/products/'.$product->source_id.'/upscale-'.Str::uuid().'.webp';
        if (!$disk->put($target, $bytes)) throw new RuntimeException('Salvataggio foto non riuscito.');
        $original = $image['original'] ?? $image;
        try {
            DB::transaction(function () use ($product, $id, $target, $bytes, $width, $height, $original, $sharpness) {
                $current = $product->locked();
                $images = $current->images;
                $index = array_search($id, array_column($images, 'sha256'), true);
                if ($index === false) throw new RuntimeException('La foto è cambiata durante la modifica.');
                $images[$index] = array_replace($images[$index], ['path' => $target, 'width' => $width,
                    'height' => $height, 'sha256' => hash('sha256', $bytes), 'original' => $original,
                    'resolution_upscaled' => true, 'sharpness' => $sharpness]);
                unset($images[$index]['shopify_file_reference'], $images[$index]['shopify_url'], $images[$index]['public_url']);
                $current->update(['images' => $images]);
            });
        } catch (\Throwable $e) {
            $disk->delete($target);
            throw $e;
        }
        if ($image['path'] !== $original['path']) $disk->delete($image['path']);
    }
}
