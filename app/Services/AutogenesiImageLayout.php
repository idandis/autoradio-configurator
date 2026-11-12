<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutogenesiImageLayout
{
    public static function dashboard(AutogenesiProduct $product): ?array
    {
        return self::dashboards($product)[0] ?? null;
    }

    public static function dashboards(AutogenesiProduct $product): array
    {
        if ($product->metadata['dashboard_selection_explicit'] ?? false) {
            return collect($product->images)->filter(fn ($image) => !empty($image['dashboard_selected']))->values()->all();
        }
        $first = collect($product->images)->first(fn ($image) => in_array($image['role'], ['cruscotto_originale', 'cruscotto_prima_dopo'], true));
        return $first ? [$first] : [];
    }

    public function update(AutogenesiProduct $product, array $data): void
    {
        DB::transaction(function () use ($product, $data) {
            $current = $product->locked();
            $images = $current->images;
            $selected = array_column(self::dashboards($current), 'sha256');
            if (array_key_exists('order', $data)) {
                $ids = array_column($images, 'sha256');
                if (count($ids) !== count($data['order']) || array_diff($ids, $data['order']) || array_diff($data['order'], $ids)) {
                    throw ValidationException::withMessages(['layout' => 'Le foto sono cambiate. Ricarica la bozza e riprova.']);
                }
                $byId = array_column($images, null, 'sha256');
                $images = array_map(fn ($id) => $byId[$id], $data['order']);
            }
            $meta = $current->metadata;
            if ((array_key_exists('order', $data) || array_key_exists('reference_image_id', $data)) && ! ($meta['dashboard_selection_explicit'] ?? false)) {
                foreach ($images as &$image) $image['dashboard_selected'] = in_array($image['sha256'], $selected, true);
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
            if (array_key_exists('dashboard_image_ids', $data)) {
                $ids = $data['dashboard_image_ids'];
                if (array_diff($ids, array_column($images, 'sha256'))) {
                    throw ValidationException::withMessages(['layout' => 'Una foto selezionata non appartiene alla bozza.']);
                }
                foreach ($images as &$image) $image['dashboard_selected'] = in_array($image['sha256'], $ids, true);
                unset($image);
                $meta['dashboard_selection_explicit'] = true;
            }
            if (array_key_exists('shopify_file_reference', $data)) {
                $found = false;
                foreach ($images as &$image) {
                    if (! empty($image['dashboard_selected']) && (!isset($data['reference_image_id']) || $image['sha256'] === $data['reference_image_id'])) {
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
