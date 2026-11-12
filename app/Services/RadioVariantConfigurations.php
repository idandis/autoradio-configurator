<?php

namespace App\Services;

class RadioVariantConfigurations
{
    public const PROFILE = 'ossuret-hizpo-qs-t-6115';
    private const MODELS = [
        'QS1' => [8, 3, 32], 'QS2' => [8, 4, 32], 'QS4' => [8, 4, 32],
        'QS6' => [8, 6, 64], 'QS8' => [8, 8, 128],
        'T1' => [4, 1, 32], 'T2CP' => [4, 2, 32], 'T3CP' => [4, 2, 64], 'T0' => [null, 1, 16],
        'P1' => [4, 1, 32], 'P2CP' => [4, 2, 32], 'P3CP' => [4, 2, 64], 'P1L' => [null, 1, 16],
    ];

    public function code(string $label): ?string
    {
        preg_match_all('/(?<![A-Z0-9])(?:Q?S[12468]|T[01]|T[23]CP|P1L?|P[23]CP)(?![A-Z0-9])/i', $label, $m);
        $codes = array_values(array_unique(array_map(fn ($code) => preg_replace('/^S/', 'QS', strtoupper($code)), $m[0])));
        return count($codes) === 1 ? $codes[0] : null;
    }

    public function knownBrand(string $text): bool
    {
        return (bool) preg_match('/\b(?:OSSURET|HIZPO)\b/i', $text);
    }

    public function profile(array $product): ?string
    {
        $context = data_get($product, 'ae_item_base_info_dto.subject', '').' '.data_get($product, 'ae_store_info.store_name', '').' '.json_encode($product['ae_item_properties'] ?? []);
        if ($this->knownBrand($context)) return self::PROFILE;
        $variants = json_encode($product['ae_item_sku_info_dtos'] ?? []);
        preg_match_all('/\bQ?S[12468]\b/i', $variants, $qs);
        return count(array_unique(array_map(fn ($code) => preg_replace('/^S/', 'QS', strtoupper($code)), $qs[0]))) >= 2 && preg_match('/\b[TP][23]CP\b/i', $variants)
            && preg_match('/\b(?:6115|A100)\b/i', $context) ? self::PROFILE : null;
    }

    public function resolve(string $label, ?string $profile, ?array $memory, ?int $cores): array
    {
        $code = $profile === self::PROFILE ? $this->code($label) : null;
        if (!$code) return ['memory' => $memory, 'cores' => $cores, 'code' => null, 'conflict' => false];
        [$cpu, $ram, $rom] = self::MODELS[$code];
        return ['memory' => $memory ?? [$ram, $rom], 'cores' => $cores ?? $cpu, 'code' => $code,
            'conflict' => ($memory !== null && $memory !== [$ram, $rom]) || ($cores !== null && $cpu !== null && $cores !== $cpu)];
    }
}
