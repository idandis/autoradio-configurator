<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiExtraImages
{
    public function append(array $images, array $data, array $selection, string $dir): array
    {
        $disk = Storage::disk('local');
        $written = [];
        try {
            foreach (['accessories_image' => 'accessori', 'before_after_image' => 'cruscotto_prima_dopo'] as $key => $role) {
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
