<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutogenesiImageLayout
{
    public static function dashboard(AutogenesiProduct $product): ?array
    {
        if ($product->metadata['dashboard_selection_explicit'] ?? false) {
            return collect($product->images)->first(fn ($image) => ! empty($image['dashboard_selected']));
        }
        return collect($product->images)->first(fn ($image) => in_array($image['role'], ['cruscotto_originale', 'cruscotto_prima_dopo'], true));
    }

    public function update(AutogenesiProduct $product, array $data): void
    {
        DB::transaction(function () use ($product, $data) {
            $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
            $images = $current->images;
            $selected = self::dashboard($current)['sha256'] ?? null;
            if (array_key_exists('order', $data)) {
                $ids = array_column($images, 'sha256');
                if (count($ids) !== count($data['order']) || array_diff($ids, $data['order']) || array_diff($data['order'], $ids)) {
                    throw ValidationException::withMessages(['layout' => 'Le foto sono cambiate. Ricarica la bozza e riprova.']);
                }
                $byId = array_column($images, null, 'sha256');
                $images = array_map(fn ($id) => $byId[$id], $data['order']);
            }
            $meta = $current->metadata;
            if (array_key_exists('order', $data) && ! ($meta['dashboard_selection_explicit'] ?? false)) {
                foreach ($images as &$image) $image['dashboard_selected'] = $image['sha256'] === $selected;
                unset($image);
                $meta['dashboard_selection_explicit'] = true;
            }
            if (array_key_exists('dashboard_image_id', $data)) {
                $id = $data['dashboard_image_id'];
                if ($id !== null && ! in_array($id, array_column($images, 'sha256'), true)) {
                    throw ValidationException::withMessages(['layout' => 'La foto selezionata è cambiata. Riprova.']);
                }
                foreach ($images as &$image) $image['dashboard_selected'] = $image['sha256'] === $id;
                unset($image);
                $meta['dashboard_selection_explicit'] = true;
            }
            if (array_key_exists('shopify_file_reference', $data)) {
                $found = false;
                foreach ($images as &$image) {
                    if (! empty($image['dashboard_selected'])) {
                        $image['shopify_file_reference'] = $data['shopify_file_reference'] ?? '';
                        $found = true;
                    }
                }
                unset($image);
                if (! $found) throw ValidationException::withMessages(['layout' => 'Seleziona prima la foto del cruscotto.']);
            }
            $current->update(['images' => $images, 'metadata' => $meta]);
        });
    }
}
