<?php

namespace App\Services;

use Illuminate\Support\Str;

class WhatsAppVehicleMatcher
{
    private function compact(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }

    private function close(string $a, string $b): bool
    {
        if (min(strlen($a), strlen($b)) < 4 || abs(strlen($a) - strlen($b)) > 1) {
            return false;
        }
        if (levenshtein($a, $b) <= 1) {
            return true;
        }
        for ($i = 0; $i < strlen($a) - 1; $i++) {
            $copy = $a;
            [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
            if ($copy === $b) {
                return true;
            }
        }

        return false;
    }

    public function brand(string $text, array $brands): ?string
    {
        foreach (['vw' => 'volkswagen', 'mercedes benz' => 'mercedes', 'benz' => 'mercedes', 'b m w' => 'bmw'] as $alias => $name) {
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/i', $text)) {
                $matches = array_values(array_filter($brands, fn ($b) => str_starts_with($this->compact($b), $this->compact($name))));
                if (count($matches) === 1) {
                    return $matches[0];
                }
            }
        }
        $tokens = explode(' ', $text);
        $matches = array_values(array_filter($brands, fn ($b) => collect($tokens)->contains(fn ($t) => $this->close($t, $this->compact($b)))));

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function model(string $text, string $brand, array $models, ?array $yearModels = null): array
    {
        if (str_starts_with($this->compact($brand), 'mercedes')
            && preg_match('/\b(?:(?:clase|classe|class)\s+([a-z]{1,3})\b|([a-z]{1,3})\s+class\b|([a-z]{1,3})\s*\d{2,3}\b)/i', $text, $class)) {
            $family = strtolower($class[1] ?: ($class[2] ?: $class[3]));
            $pattern = '/^(?:clase|classe|class)\s+'.preg_quote($family, '/').'\b/i';
            $candidates = array_values(array_filter($models, fn ($m) => preg_match($pattern, $m)));
            $explicit = array_values(array_filter($candidates, fn ($m) => preg_match('/\b[wcx]\d{3}\b/i', $m, $chassis) && str_contains($this->compact($text), $this->compact($chassis[0]))));
            if ($explicit) {
                $candidates = $explicit;
            } elseif ($yearModels !== null) {
                $inYear = array_values(array_intersect($candidates, $yearModels));
                if ($inYear) {
                    $candidates = $inYear;
                }
            }
            if ($candidates) {
                return ['model' => count($candidates) === 1 ? $candidates[0] : null, 'candidates' => $candidates];
            }
        }
        if ($this->compact($brand) === 'lexus' && preg_match('/\brx\s*(?:[- ]?\s*\d{3}\s*h?)?\b/i', $text)) {
            return ['model' => collect($models)->first(fn ($m) => $this->compact($m) === 'rx') ?? 'RX', 'candidates' => []];
        }
        $words = explode(' ', $text);
        $phrases = [];
        foreach ($words as $i => $word) {
            for ($n = 1; $n <= 4; $n++) {
                $phrases[] = implode('', array_slice($words, $i, $n));
            }
        }
        $exact = array_values(array_filter($models, fn ($m) => ! preg_match('/^(19|20)\d{2}$/', $m) && in_array($this->compact($m), $phrases, true)));
        if ($exact) {
            usort($exact, fn ($a, $b) => strlen($b) <=> strlen($a));

            return ['model' => $exact[0], 'candidates' => []];
        }
        $normalize = function ($value) {
            $value = Str::lower(Str::ascii($value));
            $value = preg_replace('/\b(?:clase|classe|class)\b/', 'class', $value);
            $value = preg_replace('/\b(?:series|serie)(?=\b|\d)/', 'series', $value);
            $value = preg_replace('/\b([a-z]{1,3})\s+class\b/', 'class $1', $value);

            return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
        };
        $normalized = $normalize($text);
        if ($this->compact($brand) === 'bmw' && preg_match('/\b([1-8])\d{2}[a-z]{0,3}\b/', $normalized, $badge)) {
            $normalized .= ' series '.$badge[1];
        }
        $tokens = explode(' ', $normalized);
        $aliases = [];
        foreach ($models as $m) {
            $name = $normalize($m);
            $parts = explode(' ', $name);
            if (count($parts) > 1 && preg_match('/^(?:[a-z]{1,3}\d{1,3}|[ivx]{1,4})$/', end($parts))) {
                array_pop($parts);
            }
            $alias = implode('', $parts);
            if (strlen($alias) < 2 || in_array($alias, ['class', 'series', 'universal'], true) || preg_match('/^(19|20)\d{2}$/', $alias)) {
                continue;
            }
            $found = false;
            foreach ($tokens as $i => $token) {
                for ($n = 1; $n <= count($parts) + 1; $n++) {
                    $phrase = implode('', array_slice($tokens, $i, $n));
                    if ($phrase === $alias || ($n === 1 && count($parts) === 1 && ! preg_match('/\d/', $alias) && $this->close($phrase, $alias))
                        || (preg_match('/^[a-z]{2,5}$/', $alias) && preg_match('/^'.preg_quote($alias, '/').'\d{2,3}[a-z]{0,2}$/', $phrase))) {
                        $found = true;
                    }
                }
            }
            if ($found) {
                $aliases[$m] = strlen($alias);
            }
        }
        if ($aliases) {
            $candidates = array_keys(array_filter($aliases, fn ($score) => $score === max($aliases)));
            if ($yearModels !== null && ($inYear = array_values(array_intersect($candidates, $yearModels)))) {
                $candidates = $inYear;
            }

            return ['model' => count($candidates) === 1 ? $candidates[0] : null, 'candidates' => $candidates];
        }
        foreach ($words as $i => $word) {
            if ($this->compact($word) !== $this->compact($brand) && ! $this->close($word, $this->compact($brand))) {
                continue;
            }
            $next = $words[$i + 1] ?? '';
            if (preg_match('/^(?:\d{1,3}|[a-z]{1,3}\d{1,3}[a-z]?)$/', $next)) {
                return ['model' => strtoupper($next), 'candidates' => []];
            }
        }
        $candidates = array_values(array_filter($models, fn ($m) => ! preg_match('/^\d+$/', $m) && collect($words)->contains(fn ($w) => $this->close($w, $this->compact($m)))));

        return ['model' => count($candidates) === 1 ? $candidates[0] : null, 'candidates' => $candidates];
    }
}
