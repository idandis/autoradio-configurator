<?php

namespace App\Services;

use App\Models\QuoteCounter;
use Illuminate\Support\Facades\DB;

class QuoteNumbers
{
    public function next(): string
    {
        $date = now()->format('Ymd');

        $sequence = DB::transaction(function () use ($date): int {
            $counter = QuoteCounter::query()
                ->where('quote_date', $date)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                $counter = QuoteCounter::create([
                    'quote_date' => $date,
                    'last_number' => 0,
                ]);
            }

            $counter->increment('last_number');

            return (int) $counter->fresh()->last_number;
        });

        return sprintf('ARC-%s-%03d', $date, $sequence);
    }
}
