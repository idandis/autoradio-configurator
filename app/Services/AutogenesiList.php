<?php

namespace App\Services;

use App\Jobs\ProcessAutogenesiBatch;
use App\Models\AutogenesiBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutogenesiList
{
    public function create(string $text, int $userId): AutogenesiBatch
    {
        $lines = preg_split('/\R/u', trim(preg_replace('/^\x{FEFF}/u', '', $text)));
        $rows = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/[;,\t]/', $line)) {
                $separator = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
                $parts = array_map('trim', str_getcsv($line, $separator, '"', ''));
                if (in_array(mb_strtolower($parts[0]), ['marca', 'brand'], true) && in_array(mb_strtolower($parts[1] ?? ''), ['modello', 'model'], true)) {
                    continue;
                }
                if (! in_array(count($parts), [3, 4], true)) {
                    $errors[] = 'Riga '.($i + 1).': servono marca, modello e uno o due anni.';

                    continue;
                }
                $line = implode(' ', $parts);
            }
            try {
                $parsed = app(AutogenesiSearch::class)->parse($line);
                $rows[mb_strtolower($parsed['query'])] ??= $parsed;
            } catch (\Throwable $e) {
                $errors[] = 'Riga '.($i + 1).': indica marca, modello e anno o intervallo valido.';
            }
        }
        if (! $rows) {
            $errors[] = 'Inserisci almeno una vettura valida.';
        }
        if (count($rows) > 100) {
            $errors[] = 'Puoi inserire al massimo 100 vetture per lista.';
        }
        if ($errors) {
            throw ValidationException::withMessages(['list' => implode(' ', array_slice($errors, 0, 6))]);
        }

        return DB::transaction(function () use ($rows, $userId) {
            $batch = AutogenesiBatch::create(['user_id' => $userId, 'total' => count($rows), 'status' => 'queued']);
            foreach (array_values($rows) as $i => $row) {
                $batch->items()->create($row + ['position' => $i + 1, 'status' => 'queued']);
            }
            ProcessAutogenesiBatch::dispatch($batch->id)->afterCommit();

            return $batch;
        });
    }
}
