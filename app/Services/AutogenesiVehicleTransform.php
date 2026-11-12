<?php

namespace App\Services;

use Illuminate\Support\Str;

class AutogenesiVehicleTransform
{
    public function model(string $value, bool $preserveUppercase = false): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $aliases = preg_split('~\s*/\s*~u', $value);
        $keys = array_unique(array_map(fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($s))), $aliases));
        if (count($keys) === 1) $value = $aliases[0];
        if (preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value))) === 'smax') return 'S-MAX';
        $words = preg_split('/[^\pL\pN-]+/u', $value);
        $upperWords = array_merge(
            preg_grep('/^\p{Lu}$/u', $words),
            $preserveUppercase ? preg_grep('/^\p{Lu}[\p{Lu}\p{N}-]+$/u', $words) : [],
        );
        $value = mb_strtolower($value);
        $value = mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
        return preg_replace_callback('/[\pL\pN]+(?:-[\pL\pN]+)*/u', function ($m) use ($upperWords) {
            $upper = mb_strtoupper($m[0]);
            $code = preg_match('/[a-z]/i', Str::ascii($m[0])) && preg_match('/\d/', $m[0]);
            return $code || mb_strlen($m[0]) === 1 || in_array($upper, $upperWords, true) || in_array($upper, ['MINI', 'BMW', 'AMG', 'GT', 'GTI', 'GTD', 'GTE', 'GTS', 'GT-R', 'RS', 'ST', 'FR', 'FJ', 'TT', 'CC', 'RCZ', 'DS', 'IQ', 'ID', 'EV', 'HEV', 'PHEV', 'SUV', 'SL', 'SLK', 'SLR', 'SLC', 'CLS', 'GLC', 'GLE', 'GLA', 'GLB', 'GLK', 'XJ', 'XJL', 'CR-V', 'HR-V', 'C-HR'], true) ? $upper : $m[0];
        }, $value);
    }

    public function fields(array $entries): array
    {
        $brands = array_values(array_unique(array_map(fn ($v) => mb_strtoupper(trim($v['brand'])), $entries)));
        $models = [];
        foreach ($entries as $entry) {
            $model = $this->model($entry['model'], ($entry['origin'] ?? null) === 'manual');
            $models[] = count($brands) === 1 ? $model : (array_search(mb_strtoupper(trim($entry['brand'])), $brands, true) + 1).':'.$model;
        }
        return ['brand' => implode(' | ', $brands), 'model' => implode(' | ', array_unique($models))];
    }

    public function fromFields(string $brand, string $model): array
    {
        $entries = app(VehicleImageResolver::class)->vehicleEntries($brand, $model);
        return $entries ? $this->fields($entries) : ['brand' => mb_strtoupper(trim($brand)), 'model' => $model];
    }

    public function groups(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) $groups[$entry['year_from'].'-'.$entry['year_to']][] = $entry;
        return array_values(array_map(fn ($items) => $this->fields($items) + ['year_from' => (int) $items[0]['year_from'], 'year_to' => (int) $items[0]['year_to']], $groups));
    }
}
