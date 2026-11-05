<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiChromeImport
{
    public function import(UploadedFile $file, array $vehicle): AutogenesiProduct
    {
        $data = json_decode(file_get_contents($file->getRealPath()), true, 64, JSON_THROW_ON_ERROR);
        Validator::make($data, ['format' => 'required|in:autogenesi.chrome.v1', 'manifest' => 'required|array',
            'manifest.images' => 'required|array|min:3|max:12', 'manifest.description.complete' => 'required|accepted',
            'manifest.description.text' => 'present|string|max:120000', 'manifest.description.html' => 'present|string|max:250000',
            'manifest.description.specifications' => 'present|array|max:200',
            'manifest.description.specifications.*.name' => 'required|string|max:500',
            'manifest.description.specifications.*.value' => 'required|string|max:10000',
            'manifest.description.images' => 'present|array|max:60'])->validate();
        $manifest = $data['manifest'];
        $url = AutogenesiSource::url($manifest['source_url'] ?? '');
        $manifest['source_platform'] = in_array(parse_url($url, PHP_URL_HOST), ['es.aliexpress.com', 'www.aliexpress.com'], true) ? 'aliexpress' : 'web';
        if (! $url) {
            throw new RuntimeException('Link prodotto mancante nel pacchetto.');
        }
        $manifest['source_url'] = $url;
        $dir = Storage::disk('local')->path('autogenesi/captures/chrome-'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        $seen = [];
        $total = 0;
        $imported = false;
        try {
            foreach (['images' => 'image', 'description' => 'description'] as $key => $prefix) {
                $images = $key === 'images' ? $manifest['images'] : $manifest['description']['images'];
                foreach ($images as &$image) {
                    $name = $image['file'] ?? '';
                    if (! preg_match('/^'.$prefix.'-\d+\.bin$/', $name) || isset($seen[$name])) {
                        throw new RuntimeException('Nome file immagine non valido o duplicato.');
                    }
                    AutogenesiSource::url($image['source_url'] ?? '');
                    $bytes = base64_decode($image['bytes_base64'] ?? '', true);
                    if (! $bytes || strlen($bytes) > 8 * 1024 * 1024) {
                        throw new RuntimeException('Immagine mancante o troppo grande.');
                    }
                    $total += strlen($bytes);
                    if ($total > 35 * 1024 * 1024) {
                        throw new RuntimeException('Pacchetto immagini superiore a 35 MB.');
                    }
                    $info = @getimagesizefromstring($bytes);
                    if (! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                        || min($info[0], $info[1]) < 200 || $info[0] * $info[1] > 25000000) {
                        throw new RuntimeException('Immagine non valida.');
                    }
                    file_put_contents($dir.'/'.$name, $bytes, LOCK_EX);
                    $seen[$name] = true;
                    unset($image['bytes_base64']);
                }
                unset($image);
                if ($key === 'images') {
                    $manifest['images'] = $images;
                } else {
                    $manifest['description']['images'] = $images;
                }
            }
            file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $product = app(AutogenesiImport::class)->import($dir.'/manifest.json', $vehicle);
            $imported = true;
            app(AutogenesiExport::class)->writeFiles($product);

            return $product;
        } catch (\Throwable $e) {
            if (! $imported) {
                File::deleteDirectory($dir);
            }
            throw $e;
        }
    }
}
