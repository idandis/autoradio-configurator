<?php

namespace App\Services;

use InvalidArgumentException;

class AutogenesiPrice
{
    public function quote(string $cost): array
    {
        if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $cost)) {
            throw new InvalidArgumentException('Costo EUR non valido.');
        }
        [$euros, $decimals] = array_pad(explode('.', $cost), 2, '');
        $cents = (int) $euros * 100 + (int) str_pad($decimals, 2, '0');
        if ($cents <= 0) {
            throw new InvalidArgumentException('Il costo deve essere positivo.');
        }
        $markup = match (true) {
            $cents < 15000 => 11000,
            $cents < 25000 => 15000,
            $cents < 35000 => 17000,
            $cents < 45000 => 18000,
            default => 20000,
        };
        $base = $cents + 1832;
        $duty = $cents >= 15000 ? $this->percent($base, 1400) : 0;
        $vat = $cents >= 15000 ? $this->percent($base + $duty, 2100) : 0;
        $fees = $cents >= 15000 ? 2181 + 518 + $this->percent(2181 + 518, 2100) : 0;
        $customs = $duty + $vat + $fees;
        $total = $cents + $markup + $customs;

        return ['rule_version' => '2026-11-04-vat21', 'supplier_cost' => $this->money($cents), 'markup' => $this->money($markup), 'vat_rate' => '21.00',
            'customs_estimate' => $this->money($customs), 'customs_base_estimate' => $cents >= 15000 ? $this->money($base) : null,
            'inferred_base_adjustment' => $cents >= 15000 ? '18.32' : '0.00', 'duty_estimate' => $this->money($duty),
            'import_vat_estimate' => $this->money($vat), 'correos_estimate' => $this->money($fees),
            'before_rounding' => $this->money($total), 'selling_price' => (string) intdiv($total + 99, 100)];
    }

    private function percent(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
