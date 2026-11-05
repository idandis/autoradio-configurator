<?php

namespace App\Services;

use RuntimeException;

class AutogenesiVariants
{
    public function normalize(array $variants, array $specs = []): array
    {
        $result = [];
        foreach ($variants as $v) {
            $source = $v['source_label'] ?? $v['label'];
            if (preg_match('/360/u', $source.' '.$v['label'].' '.json_encode($v['source_options'] ?? []))) {
                continue;
            }
            $v['source_label'] = $source;
            if (empty($v['label_edited'])) {
                $cores = null;
                foreach ($specs as $s) {
                    if (($s['label'] ?? '') === $source && in_array($s['cores'] ?? null, [4, 8], true) && ! empty($s['evidence'])) {
                        $cores = $s['cores'];
                        $v['core_evidence'] = $s;
                        break;
                    }
                }
                if ($cores === null) {
                    $cores = $v['core_evidence']['cores'] ?? null;
                }
                if ($cores === null && preg_match('/\b([48])\s*[- ]?core\b/i', $source, $m)) {
                    $cores = (int) $m[1];
                }
                if (in_array($cores, [4, 8], true) && preg_match('/\b(\d+)\s*G(?:B)?\s*[,\/ +]*\s*(\d+)\s*G(?:B)?\b/i', $source, $m)) {
                    $v['label'] = $cores.'Core '.$m[1].'GB '.$m[2].'GB';
                    if (preg_match('/\)\s*(\d{1,2}(?:[.,]\d+)?)/', $source, $size)) {
                        $v['label'] .= ' '.str_replace(',', '.', $size[1])."''";
                    }
                }
            }
            $result[] = $v;
        }
        if (! $result) {
            throw new RuntimeException('Nessuna variante disponibile dopo l’esclusione delle versioni 360.');
        }
        $counts = array_count_values(array_column($result, 'label'));
        foreach ($result as &$v) {
            if ($counts[$v['label']] > 1 && empty($v['label_edited'])) {
                $code = trim(preg_split('/\(/', $v['source_label'])[0]);
                $v['label'] .= ' · '.$code;
            }
        }
        unset($v);
        usort($result, fn ($a, $b) => ((float) ($a['selling_price'] ?? $a['supplier_price'])) <=> ((float) ($b['selling_price'] ?? $b['supplier_price'])));

        return $result;
    }
}
