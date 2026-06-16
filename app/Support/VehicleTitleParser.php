<?php

namespace App\Support;

class VehicleTitleParser
{
    public static function parse(string $title, ?string $brand = null): array
    {
        $cleanTitle = self::normalizeWhitespace($title);

        $yearFrom = null;
        $yearTo = null;
        $subject = $cleanTitle;

        preg_match_all('/(19|20)\d{2}\s*[-–]\s*((19|20)\d{2}|\+)/u', $cleanTitle, $yearMatches);

        if (($yearMatches[0] ?? []) !== []) {
            $yearFromValues = [];
            $yearToValues = [];

            foreach ($yearMatches[0] as $yearRange) {
                $yearFromValues[] = (int) substr($yearRange, 0, 4);
                $yearToText = trim(substr($yearRange, -4));
                $yearToValues[] = ctype_digit($yearToText) ? (int) $yearToText : (int) date('Y');
                $subject = trim(str_replace($yearRange, ' ', $subject));
            }

            $yearFrom = min($yearFromValues);
            $yearTo = max($yearToValues);
        }

        if (str_contains(mb_strtolower($subject), ' para ')) {
            $parts = preg_split('/\s+para\s+/iu', $subject);
            $subject = trim((string) end($parts));
        }

        $subject = self::stripNoise($subject, $brand);

        if (mb_strtolower((string) $brand) === 'bmw') {
            $subject = self::extractBmwModel($subject);
        }

        if (mb_strtolower((string) $brand) === 'alfa romeo') {
            $subject = self::extractAlfaRomeoModel($subject);
        }

        $subject = self::finalizeSubject($subject);

        return [
            'model' => $subject !== '' ? $subject : null,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
        ];
    }

    private static function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private static function stripNoise(string $subject, ?string $brand): string
    {
        $patterns = [
            '/\b\d{1,2}(?:[.,]\d{1,2})?["”]/u',
            '/\b[12]\s*din\b/iu',
            '/\b(?:android(?:\s*\d+)?|gps|multimedia|oem|carplay|autoradio|radio|pantalla|stereo|head\s*unit)\b/iu',
            '/\b(?:de\s+coche|for\s+car|navegador|touch)\b/iu',
            '/\b(?:junsun|dasaita|alpine)\b/iu',
            '/\b(?:cd\d{2,4})\b/iu',
            '/\b(?:y\s+más|and\s+more)\b/iu',
            '/\b(?:19|20)\d{2}(?:\s*,\s*(?:19|20)\d{2})+\b/u',
        ];

        foreach ($patterns as $pattern) {
            $subject = preg_replace($pattern, ' ', $subject) ?? $subject;
        }

        if ($brand) {
            $subject = preg_replace('/\b'.preg_quote($brand, '/').'\b/iu', ' ', $subject) ?? $subject;
        }

        return self::normalizeWhitespace($subject);
    }

    private static function extractBmwModel(string $subject): string
    {
        $normalized = mb_strtolower($subject);
        $normalized = preg_replace('/\bseries\b/u', 'serie', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(\d+)\s+serie\b/u', 'serie $1', $normalized) ?? $normalized;
        $normalized = self::normalizeWhitespace($normalized);

        $patterns = [
            '/\bserie\s+\d+(?:\/\d+)*(?:-\d+)?(?:\s+[efg]\d{2}(?:-[efg]\d{2})*)?(?:\s*\([^)]*\))?/iu',
            '/\b[efg]\d{2}(?:-[efg]\d{2})?(?:\s+m\d)?(?:\s+\d{3}(?:\/\d{3})*)?/iu',
            '/\bx\d(?:\/x\d)?\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $match) === 1) {
                return $match[0];
            }
        }

        return $normalized;
    }

    private static function finalizeSubject(string $subject): string
    {
        $subject = preg_replace('/\(\s*\)/u', '', $subject) ?? $subject;
        $subject = preg_replace('/\s*\([^)]*$/u', '', $subject) ?? $subject;
        $subject = preg_replace('/\s*,\s*/u', ', ', $subject) ?? $subject;
        $subject = preg_replace('/\s*\/\s*/u', ' / ', $subject) ?? $subject;
        $subject = preg_replace('/\s{2,}/u', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\s+[-–|\/]\s*$/u', '', $subject) ?? $subject;
        $subject = trim($subject, " \t\n\r\0\x0B-–|/,:;");

        return self::normalizeWhitespace($subject);
    }

    private static function extractAlfaRomeoModel(string $subject): string
    {
        preg_match_all(
            '/\b(?:147|156|159|166|4c|brera|spider|giulietta|mito|giulia|stelvio|gt|tonale|sportwagon)\b/iu',
            $subject,
            $matches
        );

        $tokens = [];

        foreach ($matches[0] ?? [] as $match) {
            $token = mb_strtolower($match);

            if ($token === 'sportwagon' && $tokens !== []) {
                $lastIndex = array_key_last($tokens);
                $tokens[$lastIndex] .= ' Sportwagon';
                continue;
            }

            if (! in_array($token, array_map('mb_strtolower', $tokens), true)) {
                $tokens[] = ctype_digit($token) ? $token : ucfirst($token);
            }
        }

        if ($tokens !== []) {
            return implode(' / ', $tokens);
        }

        return $subject;
    }
}
