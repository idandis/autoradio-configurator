<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImageUpload
{
    public function add(AutogenesiProduct $product, array $files, string $role): int
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Caricamento foto disponibile solo in locale.');
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('autogenesi/uploads');
        $written = [];
        $pending = [];
        try {
            foreach ($files as $file) {
                app(ProductSheetProcessing::class)->check();
                $tmp = $disk->path('autogenesi/uploads/'.Str::uuid().'.png');
                try {
                    $this->convert($file, $tmp);
                    $photo = app(AutogenesiSquareImage::class)->original($tmp);
                    $base = 'autogenesi/products/'.$product->source_id.'/upload-'.Str::uuid();
                    $path = $base.'.'.$photo['extension'];
                    if (! $disk->put($path, $photo['bytes'])) {
                        throw new RuntimeException('Salvataggio foto non riuscito.');
                    }
                    $written[] = $path;
                    $pending[] = ['path' => $path, 'uncropped_path' => null, 'source_url' => null, 'uploaded' => true,
                        'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'role' => $role, 'variant' => null,
                        'width' => $photo['width'], 'height' => $photo['height'], 'sha256' => $photo['sha256'], 'square_cropped' => $photo['square_cropped']];
                } finally {
                    if (is_file($tmp)) {
                        unlink($tmp);
                    }
                }
            }
            $used = [];
            $count = DB::transaction(function () use ($product, $pending, &$used) {
                $current = $product->locked();
                $images = $current->images;
                $hashes = array_column($images, 'sha256');
                $added = 0;
                foreach ($pending as $image) {
                    if (in_array($image['sha256'], $hashes, true)) {
                        continue;
                    }
                    $images[] = $image;
                    $hashes[] = $image['sha256'];
                    $used[] = $image['path'];
                    if ($image['uncropped_path']) {
                        $used[] = $image['uncropped_path'];
                    }
                    $added++;
                }
                $rank = fn ($i) => str_starts_with($i['role'], 'cruscotto_') ? 2 : ($i['role'] === 'accessori' ? 1 : 0);
                usort($images, fn ($a, $b) => $rank($a) <=> $rank($b));
                $current->update(['images' => $images]);

                return $added;
            });
            $disk->delete(array_values(array_diff($written, $used)));

            return $count;
        } catch (\Throwable $e) {
            $disk->delete($written);
            throw $e;
        }
    }

    private function convert(UploadedFile $file, string $target): void
    {
        if (! $file->isValid() || $file->getSize() > 8 * 1024 * 1024) {
            throw new RuntimeException('Ogni foto deve essere inferiore a 8 MB.');
        }
        $path = $file->getRealPath();
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? $file->getMimeType();
        if (in_array($mime, ['image/heic', 'image/heif', 'image/tiff', 'image/x-tiff'], true)) {
            if (! is_executable('/usr/bin/sips')) {
                throw new RuntimeException('Conversione HEIC/TIFF disponibile su Mac.');
            }
            $probe = Process::timeout(15)->run(['/usr/bin/sips', '-g', 'pixelWidth', '-g', 'pixelHeight', $path])->throw()->output();
            preg_match('/pixelWidth:\s*(\d+)/', $probe, $w);
            preg_match('/pixelHeight:\s*(\d+)/', $probe, $h);
            if (empty($w[1]) || empty($h[1]) || $w[1] * $h[1] > 25000000) {
                throw new RuntimeException('Foto non valida o superiore a 25 megapixel.');
            }
            Process::timeout(30)->run(['/usr/bin/sips', '-s', 'format', 'png', '--resampleHeightWidthMax', (string) min(1600, max((int) $w[1], (int) $h[1])), $path, '--out', $target])->throw();

            return;
        }
        if (! $info || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/avif'], true)) {
            throw new RuntimeException('Formato non supportato: usa JPG, PNG, WebP, AVIF, GIF, BMP, HEIC o TIFF.');
        }
        if ($info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Foto superiore a 25 megapixel.');
        }
        $image = @imagecreatefromstring(file_get_contents($path));
        if (! $image) {
            throw new RuntimeException('Non riesco a leggere questa immagine.');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $angle = [3 => 180, 4 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $image = imagerotate($image, $angle, 0);
            }
        }
        $scale = min(1, max(1600 / max(imagesx($image), imagesy($image)), 200 / min(imagesx($image), imagesy($image))));
        $out = imagecreatetruecolor((int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($image), imagesy($image));
        if (! imagepng($out, $target)) {
            throw new RuntimeException('Conversione immagine non riuscita.');
        }
    }
}
