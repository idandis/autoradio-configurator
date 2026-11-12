<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AutogenesiMaskedBlur
{
    public function edit(AutogenesiProduct $product, string $id, UploadedFile $file, int $strength = 12, bool $enhance = false): void
    {
        if (!app()->environment('local')) throw new RuntimeException('Sfocatura disponibile solo in locale.');
        $image = collect($product->images)->firstWhere('sha256', $id);
        if (!$image) throw new RuntimeException('Foto non più presente.');
        $path = Storage::disk('local')->path($image['path']);
        $info = is_file($path) && !is_link($path) ? @getimagesize($path) : false;
        $mi = @getimagesize($file->getRealPath());
        if (!$info || !$mi || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || filesize($path) > 16 * 1024 * 1024 || $mi['mime'] !== 'image/png' || $mi[0] !== $info[0] || $mi[1] !== $info[1]
            || $info[0] * $info[1] > 8000000 || $strength < 1 || $strength > 100) throw new RuntimeException('Maschera o foto non valida: stesse dimensioni, massimo 8 megapixel.');
        [$w, $h] = $info;
        $original = imagecreatefromstring(file_get_contents($path)); imagepalettetotruecolor($original); imagesavealpha($original, true);
        if ($enhance) imageconvolution($original, [[0, -0.2, 0], [-0.2, 1.8, -0.2], [0, -0.2, 0]], 1, 0);
        $mask = imagecreatefrompng($file->getRealPath()); imagepalettetotruecolor($mask);
        $factor = max(1, $strength / 2);
        $sw = max(1, (int) round($w / $factor)); $sh = max(1, (int) round($h / $factor));
        $small = imagecreatetruecolor($sw, $sh); imagealphablending($small, false); imagesavealpha($small, true);
        imagecopyresampled($small, $original, 0, 0, 0, 0, $sw, $sh, $w, $h);
        for ($i = 0; $i < 3; $i++) imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        $blur = imagecreatetruecolor($w, $h); imagealphablending($blur, false); imagesavealpha($blur, true);
        imagecopyresampled($blur, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagealphablending($original, false);
        $selected = 0;
        for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
            $p = imagecolorat($mask, $x, $y);
            if (($p & 255) > 127 && (($p >> 24) & 127) < 64) {
                $selected++;
                $color = (imagecolorat($blur, $x, $y) & 0xffffff) | (imagecolorat($original, $x, $y) & 0x7f000000);
                imagesetpixel($original, $x, $y, $color);
            }
        }
        if (!$selected && !$enhance) throw new RuntimeException('Evidenzia almeno una scritta da sfocare.');
        ob_start(); imagepng($original); $output = ob_get_clean();
        foreach ([$original, $mask, $blur, $small] as $im) imagedestroy($im);
        $tmp = tempnam(sys_get_temp_dir(), 'autogenesi-blur-');
        try {
            file_put_contents($tmp, $output);
            app(AutogenesiImagePaint::class)->save($product, $id, new UploadedFile($tmp, 'blur.png', 'image/png', null, true), [
                'masked_cleanup' => false, 'masked_blur' => $selected > 0, 'blur_strength' => $strength, 'locally_enhanced' => $enhance,
            ]);
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
}
