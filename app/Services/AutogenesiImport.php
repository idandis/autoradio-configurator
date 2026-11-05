<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImport
{
    public function capture(string $url, array $vehicle): AutogenesiProduct
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Importazione browser disponibile solo in locale.');
        }
        $control = app(AutogenesiRunControl::class);
        $runId = $control->state()['run_id'];
        $control->assertActive($runId);
        $this->validateSource($url);
        $id = $this->sourceId($url);
        if ($existing = AutogenesiProduct::where('source_id', $id)->first()) {
            return $existing;
        }
        $dir = Storage::disk('local')->path('autogenesi/captures/'.Str::uuid());
        Process::timeout(360)->run(['node', base_path('scripts/autogenesi/capture-product.cjs'), $url, $dir])->throw();

        $control->assertActive($runId);

        return $this->import($dir.'/manifest.json', $vehicle);
    }

    public function regenerate(AutogenesiProduct $product): AutogenesiProduct
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Generazione disponibile solo in locale.');
        }
        $this->validateSource($product->source_url);
        $dir = Storage::disk('local')->path('autogenesi/captures/'.Str::uuid());
        Process::timeout(360)->run(['node', base_path('scripts/autogenesi/capture-product.cjs'), $product->source_url, $dir])->throw();
        $data = json_decode(file_get_contents($dir.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $vehicle = $product->only(['brand', 'model', 'year_from', 'year_to']);
        $copy = app(AutogenesiDescription::class)->generate($data, $vehicle, $dir);
        $metadata = $product->metadata;
        $metadata['previous_copy'] = ['title' => $product->title, 'body_html' => $product->body_html];
        $metadata['description_source'] = $data['description'];
        $metadata['capture_directory'] = $dir;
        $metadata['description_generation'] = array_diff_key($copy, array_flip(['title', 'body_html', 'notes']));
        $metadata['warnings'] = array_values(array_unique(array_merge($metadata['warnings'] ?? [], $copy['notes'])));
        $product->update(['title' => $copy['title'], 'body_html' => $copy['body_html'], 'metadata' => $metadata]);

        return $product->fresh();
    }

    public function import(string $manifest, array $vehicle): AutogenesiProduct
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Importazione disponibile solo in locale.');
        }
        $control = app(AutogenesiRunControl::class);
        $runId = $control->state()['run_id'];
        $control->assertActive($runId);
        $vehicle = Validator::make($vehicle, ['brand' => 'required|string|max:80', 'model' => 'required|string|max:120',
            'year_from' => 'required|integer|min:1', 'year_to' => 'required|integer|gte:year_from'])->validate();
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        Validator::make($data, ['source_id' => 'required|regex:/^\d+$/', 'source_url' => 'required|url',
            'source_title' => 'required|string|max:2000', 'variants' => 'required|array|min:1|max:12',
            'variants.*.label' => 'required|string|max:500', 'variants.*.supplier_price' => 'required|numeric|gt:0|max:999999',
            'variants.*.currency' => 'required|in:EUR', 'images' => 'required|array|min:3|max:12',
            'images.*.file' => 'required|regex:/^image-\d+\.bin$/', 'images.*.source_url' => 'required|url'])->validate();
        $web = ($data['source_platform'] ?? '') === 'web';
        if ($web) AutogenesiSource::url($data['source_url']);
        else $this->validateSource($data['source_url']);
        if (($web ? AutogenesiSource::id($data['source_url']) : $this->sourceId($data['source_url'])) !== $data['source_id']) {
            throw new RuntimeException($web ? 'ID prodotto e link non corrispondono.' : 'ID prodotto e link AliExpress non corrispondono.');
        }
        if ($existing = AutogenesiProduct::where('source_id', $data['source_id'])->first()) {
            return $existing;
        }
        $disk = Storage::disk('local');
        $images = [];
        $written = [];
        $hashes = [];
        try {
            foreach ($data['images'] as $image) {
                AutogenesiSource::url($image['source_url']);
                $path = dirname($manifest).'/'.$image['file'];
                if (is_link($path) || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                    throw new RuntimeException('File immagine non valido.');
                }
                $info = @getimagesize($path);
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
                if (! $ext || min($info[0], $info[1]) < 200 || $info[0] * $info[1] > 25000000) {
                    throw new RuntimeException('Formato o dimensioni immagine non validi.');
                }
                $photo = app(AutogenesiSquareImage::class)->original($path);
                $hash = $photo['sha256'];
                if (isset($hashes[$hash])) {
                    continue;
                }
                $hashes[$hash] = true;
                $target = 'autogenesi/products/'.$data['source_id'].'/'.Str::uuid().'.'.$photo['extension'];
                if (! $disk->put($target, $photo['bytes'])) {
                    throw new RuntimeException('Salvataggio immagine non riuscito.');
                }
                $written[] = $target;
                $images[] = ['path' => $target, 'uncropped_path' => null, 'source_url' => $image['source_url'], 'role' => 'da_verificare',
                    'variant' => null, 'width' => $photo['width'], 'height' => $photo['height'], 'sha256' => $hash, 'square_cropped' => $photo['square_cropped']];
            }
            if (count($images) < 3) {
                throw new RuntimeException('Servono almeno tre immagini distinte.');
            }
            $data['variants'] = array_values(array_filter($data['variants'], fn ($v) => ! str_contains($v['label'].' '.json_encode($v['options'] ?? []), '360')));
            if (! $data['variants']) {
                throw new RuntimeException('Nessuna variante senza 360 disponibile.');
            }
            $copy = app(AutogenesiDescription::class)->generate($data, $vehicle, dirname($manifest));
            $requestedVehicle = $vehicle;
            $compatibility = app(AutogenesiCompatibility::class)->resolve($data, $copy, $vehicle);
            $vehicle = $compatibility['vehicle'];
            $data['warnings'] = array_merge($data['warnings'] ?? [], $compatibility['warnings']);
            $oldPaths = array_column($images, 'path');
            $images = app(AutogenesiExtraImages::class)->append($images, $data, $copy, dirname($manifest));
            foreach ($images as $image) {
                if (in_array($image['path'], $oldPaths, true)) {
                    continue;
                }
                $written[] = $image['path'];
                if (! empty($image['uncropped_path'])) {
                    $written[] = $image['uncropped_path'];
                }
            }
            if (! empty($copy['before_after_image'])) {
                $data['warnings'] = array_values(array_filter($data['warnings'] ?? [], fn ($w) => $w !== 'Foto del cruscotto originale non identificata.'));
            }
            $variants = array_map(function ($v, $i) use ($data, $web) {
                $cost = number_format((float) $v['supplier_price'], 2, '.', '');
                $pricing = app(AutogenesiPrice::class)->quote($cost);

                return ['label' => $v['label'], 'supplier_price' => $cost, 'currency' => 'EUR', 'selling_price' => $pricing['selling_price'],
                    'pricing' => $pricing, 'sku' => ($web ? 'WEB-' : 'AE-').$data['source_id'].'-'.($i + 1), 'source_options' => $v['options'] ?? [],
                    'observed_at' => $v['observed_at'] ?? ($data['captured_at'] ?? null)];
            }, $data['variants'], array_keys($data['variants']));
            $variants = app(AutogenesiVariants::class)->normalize($variants, $copy['variant_specs'] ?? []);

            $control->assertActive($runId);

            return AutogenesiProduct::create($vehicle + ['source_id' => $data['source_id'], 'source_url' => $data['source_url'],
                'handle' => Str::slug($vehicle['brand'].'-'.$vehicle['model'].'-'.$vehicle['year_from'].'-'.$vehicle['year_to']).($web ? '-web-' : '-ae-').$data['source_id'],
                'status' => 'draft', 'title' => $copy['title'], 'body_html' => $copy['body_html'], 'variants' => $variants, 'images' => $images,
                'metadata' => ['requested_vehicle' => $requestedVehicle, 'vehicle_compatibilities' => $compatibility['entries'], 'source_platform' => $web ? 'web' : 'aliexpress', 'source_title' => $data['source_title'], 'seller' => $data['seller'] ?? null, 'captured_at' => $data['captured_at'] ?? null,
                    'warnings' => array_values(array_unique(array_merge($data['warnings'] ?? [], $copy['notes']))), 'source_text' => $data['source_text'] ?? '', 'compatibility_verified' => false,
                    'description_source' => $data['description'], 'capture_directory' => dirname($manifest), 'description_generation' => array_diff_key($copy, array_flip(['title', 'body_html', 'notes']))]]);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }
            throw $e;
        }
    }

    private function validateSource(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', ['es.aliexpress.com', 'www.aliexpress.com'], true)
            || ! preg_match('~^/item/\d+\.html$~', $parts['path'] ?? '') || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Inserisci il link HTTPS della scheda prodotto AliExpress.');
        }
    }

    private function sourceId(string $url): string
    {
        return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
    }
}
