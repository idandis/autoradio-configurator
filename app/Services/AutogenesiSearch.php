<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AutogenesiSearch
{
    public function search(string $query, string $mode = 'links'): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Ricerca disponibile solo in locale.');
        }
        if (! in_array($mode, ['links', 'best_price'], true)) {
            throw new RuntimeException('Modalità ricerca non valida.');
        }
        $control = app(AutogenesiRunControl::class);
        $runId = $control->state()['run_id'];
        $control->assertActive($runId);
        $parsed = $this->parse($query);
        $query = $parsed['query'];
        $vehicle = $parsed['vehicle'];
        $dir = Storage::disk('local')->path('autogenesi/searches/'.Str::uuid());
        Process::timeout($mode === 'best_price' ? 360 : 150)->run(['node', base_path('scripts/autogenesi/search-products.cjs'), $query, $dir, json_encode($vehicle), $mode])->throw();
        $control->assertActive($runId);
        $result = json_decode(file_get_contents($dir.'/results.json'), true, flags: JSON_THROW_ON_ERROR);

        return ['query' => $query, 'mode' => $mode, 'vehicle' => $vehicle, 'origin' => $result['origin'], 'url' => $result['url'],
            'candidates' => $result['candidates'], 'selected' => $result['selected'] ?? null,
            'inspected_count' => $result['inspected_count'] ?? 0, 'pages_scanned' => $result['pages_scanned'] ?? 0];
    }

    public function sourceUrl(string $query): ?string
    {
        $query = trim($query);
        if (! preg_match('~^(?:https?://|www\.|(?:es\.)?aliexpress\.com/)~i', $query)) {
            return null;
        }
        $url = parse_url($query);
        $host = strtolower($url['host'] ?? '');
        if (($url['scheme'] ?? '') !== 'https' || ! in_array($host, ['es.aliexpress.com', 'www.aliexpress.com'], true)
            || ! preg_match('~^/item/\d+\.html$~', $url['path'] ?? '') || isset($url['user']) || isset($url['pass'])
            || (isset($url['port']) && $url['port'] !== 443)) {
            throw ValidationException::withMessages(['query' => 'Inserisci il link completo del prodotto: https://es.aliexpress.com/item/numero.html']);
        }

        return 'https://'.$host.$url['path'];
    }

    public function parse(string $query): array
    {
        $query = preg_replace('/\s+/u', ' ', trim($query));
        $query = preg_replace('/^(?:radio\s+)+/iu', '', trim($query));
        if (! preg_match('/^(Alfa\s+Romeo|Land\s+Rover|Mercedes\s+Benz|DS\s+Automobiles|[\pL-]+)\s+(.+?)\s+(\d{4})(?:\D+(\d{4}))?\s*$/iu', $query, $m)) {
            throw new RuntimeException('Inserisci marca, modello e anno o intervallo anni.');
        }
        $vehicle = Validator::make(['brand' => $m[1], 'model' => trim($m[2]), 'year_from' => (int) $m[3], 'year_to' => (int) ($m[4] ?? $m[3])],
            ['brand' => 'required|string|max:80', 'model' => 'required|string|max:120', 'year_from' => 'integer|min:1', 'year_to' => 'integer|gte:year_from'])->validate();
        $query = 'radio '.$vehicle['brand'].' '.$vehicle['model'].' '.$vehicle['year_from'].($vehicle['year_to'] !== $vehicle['year_from'] ? ' '.$vehicle['year_to'] : '');

        return ['query' => $query, 'vehicle' => $vehicle];
    }
}
