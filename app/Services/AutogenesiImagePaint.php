<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImagePaint
{
    public function save(AutogenesiProduct $product, string $id, UploadedFile $file): void
    {
        $disk = Storage::disk('local');
        $image = collect($product->images)->firstWhere('sha256', $id);
        $path = $image ? $disk->path($image['path']) : '';
        $source = is_file($path) && !is_link($path) ? @getimagesize($path) : false;
        $info = @getimagesize($file->getRealPath());
        if (!$source || !$info || $info['mime'] !== 'image/png' || $info[0] !== $source[0] || $info[1] !== $source[1] || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('La modifica deve mantenere le dimensioni originali della foto.');
        }
        $decoded = imagecreatefrompng($file->getRealPath());
        ob_start();
        imagepng($decoded);
        $bytes = ob_get_clean();
        imagedestroy($decoded);
        if (!$bytes || strlen($bytes) > 16 * 1024 * 1024) throw new RuntimeException('Immagine Paint troppo grande.');
        $target = 'autogenesi/products/'.$product->source_id.'/paint-'.Str::uuid().'.png';
        if (!$disk->put($target, $bytes)) throw new RuntimeException('Salvataggio Paint non riuscito.');
        try {
            DB::transaction(function () use ($product, $id, $image, $target, $bytes) {
                $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
                $images = $current->images;
                $i = array_search($id, array_column($images, 'sha256'), true);
                if ($i === false) throw new RuntimeException('La foto è cambiata durante la modifica.');
                $images[$i] = array_replace($images[$i], ['path' => $target, 'sha256' => hash('sha256', $bytes),
                    'original' => $image['original'] ?? $image, 'manually_painted' => true]);
                unset($images[$i]['shopify_file_reference'], $images[$i]['shopify_url'], $images[$i]['public_url']);
                $current->update(['images' => $images]);
            });
        } catch (\Throwable $e) {
            $disk->delete($target);
            throw $e;
        }
        if (isset($image['original']) && $image['path'] !== $image['original']['path']) $disk->delete($image['path']);
    }
}
