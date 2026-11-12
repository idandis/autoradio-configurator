<?php

namespace App\Services;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AutogenesiPriceRules
{
    private const FILE = 'autogenesi/pricing/rules.json';

    public function defaults(): array
    {
        return ['version' => '2026-11-04-vat21', 'vat_rate' => '21.00', 'ranges' => [
            ['min' => '0.00', 'max' => '150.00', 'percentage' => '0.00', 'fixed' => '110.00'],
            ['min' => '150.00', 'max' => '250.00', 'percentage' => '0.00', 'fixed' => '150.00'],
            ['min' => '250.00', 'max' => '350.00', 'percentage' => '0.00', 'fixed' => '170.00'],
            ['min' => '350.00', 'max' => '450.00', 'percentage' => '0.00', 'fixed' => '180.00'],
            ['min' => '450.00', 'max' => null, 'percentage' => '0.00', 'fixed' => '200.00'],
        ]];
    }

    public function current(): array
    {
        $app = Container::getInstance();
        if (!$app->bound('filesystem')) return $this->defaults();
        $disk = $app->make('filesystem')->disk('local');
        if (!$disk->exists(self::FILE)) return $this->defaults();
        $data = json_decode($disk->get(self::FILE), true, 32, JSON_THROW_ON_ERROR);
        return ['version' => $data['version'], 'ranges' => $this->validate($data['ranges']), 'vat_rate' => $this->vat($data['vat_rate'] ?? '21.00'), 'saved_at' => $data['saved_at'] ?? null];
    }

    public function validate(array $ranges): array
    {
        $data = Validator::make(['ranges' => $ranges], [
            'ranges' => 'required|array|min:1|max:50', 'ranges.*.min' => 'required|numeric|min:0|max:1000000|decimal:0,2',
            'ranges.*.max' => 'nullable|numeric|min:0|max:1000000|decimal:0,2',
            'ranges.*.percentage' => 'required|numeric|min:0|max:1000|decimal:0,2',
            'ranges.*.fixed' => 'required|numeric|min:0|max:999999|decimal:0,2',
        ])->validate();
        $result = []; $next = 0;
        foreach (array_values($data['ranges']) as $i => $r) {
            $min = (int) round((float) $r['min'] * 100);
            $max = isset($r['max']) && $r['max'] !== '' ? (int) round((float) $r['max'] * 100) : null;
            if ($min !== $next || ($max !== null && $max <= $min) || ($max === null && $i !== count($ranges) - 1)) {
                throw ValidationException::withMessages(['ranges' => 'Le fasce devono partire da 0, essere contigue, senza sovrapposizioni e con limite superiore maggiore del minimo.']);
            }
            $result[] = ['min' => number_format($min / 100, 2, '.', ''), 'max' => $max === null ? null : number_format($max / 100, 2, '.', ''),
                'percentage' => number_format((float) $r['percentage'], 2, '.', ''), 'fixed' => number_format((float) $r['fixed'], 2, '.', '')];
            $next = $max;
        }
        if ($next !== null) throw ValidationException::withMessages(['ranges' => 'L’ultima fascia deve essere senza limite superiore.']);
        return $result;
    }

    public function vat(mixed $rate): string
    {
        $data = Validator::make(['vat_rate' => $rate], ['vat_rate' => 'required|numeric|min:0|max:100|decimal:0,2'])->validate();
        return number_format((float) $data['vat_rate'], 2, '.', '');
    }

    public function save(array $ranges, int $user, mixed $vat = null): array
    {
        $ranges = $this->validate($ranges);
        $vat = $this->vat($vat ?? $this->current()['vat_rate']);
        $data = ['version' => 'custom-'.substr(hash('sha256', json_encode([$ranges, $vat])), 0, 16), 'ranges' => $ranges, 'vat_rate' => $vat, 'saved_at' => now()->toIso8601String(), 'saved_by' => $user];
        $disk = app('filesystem')->disk('local'); $disk->makeDirectory('autogenesi/pricing');
        $path = $disk->path(self::FILE); $tmp = $path.'.'.bin2hex(random_bytes(8));
        try {
            if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Salvataggio regola non riuscito.');
            chmod($tmp, 0600);
            if (!rename($tmp, $path)) throw new \RuntimeException('Salvataggio regola non riuscito.');
        } finally { if (is_file($tmp)) unlink($tmp); }
        return $data;
    }
}
