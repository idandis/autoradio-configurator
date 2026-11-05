<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiSquareImage
{
    public function original(string $path): array
    {
        $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (! $ext || min($info[0], $info[1]) < 200 || $info[0] * $info[1] > 25000000 || filesize($path) > 16 * 1024 * 1024) {
            throw new RuntimeException('Immagine non valida.');
        }
        $bytes = file_get_contents($path);

        return ['bytes' => $bytes, 'extension' => $ext, 'width' => $info[0], 'height' => $info[1], 'sha256' => hash('sha256', $bytes), 'square_cropped' => false];
    }

    public function prepare(string $path, bool $preserveContent = false): array
    {
        $info = @getimagesize($path);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (! $ext || min($info[0], $info[1]) < 200 || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Immagine non valida.');
        }
        $bytes = file_get_contents($path);
        $cropped = $info[0] !== $info[1];
        if ($cropped) {
            $source = imagecreatefromstring($bytes);
            if (! $source) {
                throw new RuntimeException('Immagine non leggibile.');
            }
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($source, IMG_FLIP_HORIZONTAL);
                }
                $angle = [3 => 180, 4 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
                if ($angle) {
                    $source = imagerotate($source, $angle, 0);
                }
            }
            $w = imagesx($source);
            $h = imagesy($source);
            $side = min($w, $h);
            $size = min($preserveContent ? max($w, $h) : $side, 1600);
            $square = imagecreatetruecolor($size, $size);
            imagecopyresampled($square, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
            if ($preserveContent) {
                for ($i = 0; $i < 15; $i++) {
                    imagefilter($square, IMG_FILTER_GAUSSIAN_BLUR);
                }
                imagefilter($square, IMG_FILTER_BRIGHTNESS, -45);
                $scale = min($size / $w, $size / $h);
                $fw = max(1, (int) round($w * $scale));
                $fh = max(1, (int) round($h * $scale));
                imagecopyresampled($square, $source, (int) (($size - $fw) / 2), (int) (($size - $fh) / 2), 0, 0, $fw, $fh, $w, $h);
            }
            ob_start();
            $ok = imagewebp($square, null, 92);
            $bytes = ob_get_clean();
            if (! $ok || ! $bytes) {
                throw new RuntimeException('Ritaglio quadrato non riuscito.');
            }
            $ext = 'webp';
        } else {
            $size = $info[0];
        }

        return ['bytes' => $bytes, 'extension' => $ext, 'width' => $size, 'height' => $size, 'sha256' => hash('sha256', $bytes), 'square_cropped' => $cropped];
    }

    public function normalizeProduct(AutogenesiProduct $product): int
    {
        $disk = Storage::disk('local');
        $written = [];
        $old = [];
        $count = 0;
        $normalize = function (array $image) use ($product, $disk, &$written, &$old, &$count) {
            $result = $this->prepare($disk->path($image['path']));
            if (! $result['square_cropped']) {
                return $image;
            }
            $target = 'autogenesi/products/'.$product->source_id.'/square-'.Str::uuid().'.webp';
            if (! $disk->put($target, $result['bytes'])) {
                throw new RuntimeException('Salvataggio foto quadrata non riuscito.');
            }
            $written[] = $target;
            if (empty($image['uncropped_path'])) {
                $image['uncropped_path'] = $image['path'];
            } else {
                $old[] = $image['path'];
            }
            $count++;

            return array_replace($image, ['path' => $target, 'width' => $result['width'], 'height' => $result['height'],
                'sha256' => $result['sha256'], 'square_cropped' => true]);
        };
        try {
            DB::transaction(function () use ($product, $normalize) {
                $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
                $images = $current->images;
                foreach ($images as &$image) {
                    if (! empty($image['original'])) {
                        $image['original'] = $normalize($image['original']);
                    }
                    $image = $normalize($image);
                }
                unset($image);
                $current->update(['images' => $images]);
            });
        } catch (\Throwable $e) {
            $disk->delete($written);
            throw $e;
        }
        $disk->delete($old);

        return $count;
    }
}
