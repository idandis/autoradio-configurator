<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CurrencyConverter
{
    private const ECB_DAILY_RATES_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public function usdToEurRate(): float
    {
        $freshRate = Cache::get('currency.usd_to_eur.fresh');
        if (is_numeric($freshRate) && (float) $freshRate > 0) {
            return (float) $freshRate;
        }

        try {
            $xml = Http::timeout(8)
                ->retry(2, 250)
                ->get(self::ECB_DAILY_RATES_URL)
                ->throw()
                ->body();

            if (! preg_match('/currency=[\'"]USD[\'"]\s+rate=[\'"]([\d.]+)[\'"]/', $xml, $matches)) {
                throw new RuntimeException('Tasso USD non presente nella risposta BCE.');
            }

            $usdPerEuro = (float) $matches[1];
            if ($usdPerEuro <= 0) {
                throw new RuntimeException('Tasso USD non valido.');
            }

            $usdToEur = round(1 / $usdPerEuro, 6);
            Cache::put('currency.usd_to_eur.fresh', $usdToEur, now()->addHours(12));
            Cache::forever('currency.usd_to_eur.last', $usdToEur);

            return $usdToEur;
        } catch (\Throwable $exception) {
            $lastRate = Cache::get('currency.usd_to_eur.last');
            if (is_numeric($lastRate) && (float) $lastRate > 0) {
                return (float) $lastRate;
            }

            throw new RuntimeException(
                'Impossibile recuperare il cambio USD/EUR dalla BCE. Riprova tra qualche minuto.',
                previous: $exception,
            );
        }
    }
}
