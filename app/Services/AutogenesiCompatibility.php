<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class AutogenesiCompatibility
{
    public function resolve(array $data, array $copy, array $requested): array
    {
        $source = $data['description'] ?? [];
        $text = $this->normalize(($source['text'] ?? '').' '.implode(' ', array_map(fn ($s) => ($s['name'] ?? '').' '.($s['value'] ?? ''), $source['specifications'] ?? [])));
        $files = array_column(array_merge($data['images'] ?? [], $source['images'] ?? []), 'file');
        $entries = [];
        foreach ($copy['compatibilities'] ?? [] as $entry) {
            if (! is_array($entry) || Validator::make($entry, [
                'brand' => 'required|string|max:80', 'model' => 'required|string|max:120',
                'year_from' => 'required|integer|min:1|max:65535', 'year_to' => 'required|integer|gte:year_from|max:65535',
                'evidence' => 'required|string|max:4000', 'source_file' => 'nullable|string',
            ])->fails()) continue;
            $evidence = $this->normalize($entry['evidence']);
            if (preg_match('/\b(?:(?:no|not|non)\s+(?:es\s+|e\s+)?compatib|not confirmed|non confermat|no confirmad|por ejemplo|for example)/u', $evidence)) continue;
            if (empty($entry['source_file'])) {
                if (mb_strlen($evidence) < 8 || ! str_contains($text, $evidence)
                    || ! str_contains($this->normalize(($data['source_title'] ?? '').' '.$text), $this->normalize($entry['brand']))
                    || ! str_contains($evidence, $this->normalize($entry['model']))
                    || ! str_contains($evidence, (string) $entry['year_from']) || ! str_contains($evidence, (string) $entry['year_to'])) continue;
            } elseif (! in_array($entry['source_file'], $files, true)) continue;
            $entry['brand'] = mb_strtoupper(trim($entry['brand']));
            $entry['model'] = mb_strtoupper(trim($entry['model']));
            $entry['year_from'] = (int) $entry['year_from'];
            $entry['year_to'] = (int) $entry['year_to'];
            $entries[$entry['brand'].'|'.$entry['model'].'|'.$entry['year_from'].'|'.$entry['year_to']] = $entry;
        }
        if (! $entries) return ['vehicle' => $requested, 'entries' => [], 'warnings' => ['Compatibilità non ricavata con certezza dalla descrizione: verificare marca, modello e anni.']];
        $entries = array_values($entries);
        $brands = array_values(array_unique(array_column($entries, 'brand')));
        $models = array_values(array_unique(array_map(fn ($e) => (array_search($e['brand'], $brands, true) + 1).':'.$e['model'], $entries)));
        $vehicle = ['brand' => implode(' | ', $brands), 'model' => count($models) === 1 ? $entries[0]['model'] : implode(' | ', $models),
            'year_from' => min(array_column($entries, 'year_from')), 'year_to' => max(array_column($entries, 'year_to'))];
        return ['vehicle' => $vehicle, 'entries' => $entries, 'warnings' => count($entries) !== count($copy['compatibilities'] ?? []) ? ['Alcune compatibilità senza riscontro sono state escluse.'] : []];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
