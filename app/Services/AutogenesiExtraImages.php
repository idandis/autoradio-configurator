<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiExtraImages
{
    public const SELECTION_RULES = 'Select ONLY 2 or 3 distinct clear photographs of the actual car radio, preferably from variant images when they show the real product well. Prefer different useful product views, preserving the correct fascia and proportions. Exclude specification tables, RAM/CPU comparison charts, feature-only advertising panels, logos, shipping banners and unrelated products. Also select at most ONE accessories/package-list image showing included components, not separately sold optional extras, and at most ONE before/after dashboard comparison matching the vehicle. Missing categories must be null/empty; never invent a match. Use exact supplied file names. Never classify an accessories image or before/after comparison as a product photograph.';

    public function curate(array $images, array $data, array $selection): array
    {
        $sources = array_column(array_merge($data['images'] ?? [], $data['description']['images'] ?? []), null, 'file');
        $urls = isset($selection['product_images']) ? array_map(fn ($file) => $sources[$file]['source_url'] ?? '', $selection['product_images']) : null;
        $result = []; $hashes = []; $counts = ['prodotto' => 0, 'accessori' => 0, 'cruscotto_prima_dopo' => 0];
        foreach (['prodotto', 'accessori', 'cruscotto_prima_dopo'] as $role) foreach ($images as $image) {
            $product = in_array($image['role'], ['prodotto', 'da_verificare'], true);
            if (($role === 'prodotto' ? (!$product || ($urls !== null && !in_array($image['source_url'], $urls, true))) : $image['role'] !== $role)
                || $counts[$role] >= ($role === 'prodotto' ? 3 : 1) || isset($hashes[$image['sha256']])) continue;
            $image['role'] = $role; $result[] = $image; $hashes[$image['sha256']] = true; $counts[$role]++;
        }
        if (!$counts['prodotto']) throw new RuntimeException('Nessuna foto prodotto riconosciuta: nessuna immagine importata.');
        return $result;
    }

    public function append(array $images, array $data, array $selection, string $dir): array
    {
        $disk = Storage::disk('local');
        $written = [];
        try {
            $picks = [];
            foreach ($selection['product_images'] ?? [] as $i => $file) { $key = 'product_image_'.$i; $selection[$key] = $file; $picks[$key] = 'prodotto'; }
            $picks += ['accessories_image' => 'accessori', 'before_after_image' => 'cruscotto_prima_dopo'];
            foreach ($picks as $key => $role) {
                $file = $selection[$key] ?? null;
                if ($file === null) {
                    continue;
                }
                $source = collect(array_merge($data['images'] ?? [], $data['description']['images'] ?? []))->firstWhere('file', $file);
                if (! $source || ! preg_match('/^(?:image|description)-\d+\.bin$/', $file)) {
                    throw new RuntimeException('Immagine aggiuntiva non presente nelle fonti originali.');
                }
                AutogenesiSource::url($source['source_url']);
                $path = $dir.'/'.$file;
                if (is_link($path) || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                    throw new RuntimeException('Immagine aggiuntiva non valida.');
                }
                $found = false;
                foreach ($images as &$image) {
                    if ($image['source_url'] !== $source['source_url']) {
                        continue;
                    }
                    $image['role'] = $role;
                    $found = true;
                }
                unset($image);
                if ($found) {
                    continue;
                }
                $photo = app(AutogenesiSquareImage::class)->original($path);
                $target = 'autogenesi/products/'.$data['source_id'].'/'.Str::uuid().'.'.$photo['extension'];
                if (! $disk->put($target, $photo['bytes'])) {
                    throw new RuntimeException('Salvataggio immagine aggiuntiva non riuscito.');
                }
                $written[] = $target;
                $images[] = ['path' => $target, 'uncropped_path' => null, 'source_url' => $source['source_url'], 'role' => $role,
                    'variant' => null, 'width' => $photo['width'], 'height' => $photo['height'], 'sha256' => $photo['sha256'], 'square_cropped' => $photo['square_cropped']];
            }
        } catch (\Throwable $e) {
            $disk->delete($written);
            throw $e;
        }
        usort($images, fn ($a, $b) => ($this->order($a['role']) <=> $this->order($b['role'])));

        return $images;
    }

    private function order(string $role): int
    {
        return str_starts_with($role, 'cruscotto_') ? 2 : ($role === 'accessori' ? 1 : 0);
    }
}
