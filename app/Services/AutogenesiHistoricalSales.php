<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AutogenesiHistoricalSales
{
    public const SPAIN_2001 = 'https://www.km77.com/mercado/espana/2001/datosdicitur.asp';

    public function fetch(int $year): array
    {
        if ($year !== 2001) return [];
        try {
            $response = Http::connectTimeout(10)->timeout(25)->get(self::SPAIN_2001);
            return $response->successful() && strlen($response->body()) < 2 * 1024 * 1024 ? $this->parse($response->body()) : [];
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }

    public function parse(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//table') as $table) {
            $rows = [];
            foreach ($xpath->query('./tr|./tbody/tr', $table) as $tr) {
                $cells = [];
                foreach ($xpath->query('./td|./th', $tr) as $cell) $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                if (count($cells) === 4) $rows[] = $cells;
            }
            $header = array_shift($rows);
            if (!$header || mb_strtolower($header[0]) !== 'marca' || mb_strtolower($header[1]) !== 'modelo'
                || !preg_match('/acumulado\s+2001/i', $header[3])) continue;
            $cars = [];
            foreach ($rows as $row) {
                if (!preg_match('/^\d{1,3}(?:\.\d{3})*$|^\d+$/', $row[3]) || !$row[0] || !$row[1]) continue;
                $units = (int) str_replace('.', '', $row[3]);
                if (!$units) continue;
                $cars[] = ['brand' => $row[0], 'model' => $row[1], 'year' => 2001, 'market' => 'Spagna', 'units' => $units,
                    'source_url' => self::SPAIN_2001, 'evidence' => implode(' | ', $row).' (colonna Acumulado 2001, gennaio–dicembre; fonte ANFAC).'];
            }
            usort($cars, fn ($a, $b) => $b['units'] <=> $a['units']);
            return array_map(fn ($car, $i) => $car + ['rank' => $i + 1], array_slice($cars, 0, 50), array_keys(array_slice($cars, 0, 50)));
        }
        return [];
    }
}
