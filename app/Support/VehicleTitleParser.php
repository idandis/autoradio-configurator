<?php

namespace App\Support;

class VehicleTitleParser
{
    public static function parse(string $title, ?string $brand = null): array
    {
        $cleanTitle = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);

        preg_match('/(19|20)\d{2}\s*[-–]\s*((19|20)\d{2}|\+)/u', $cleanTitle, $yearMatch, PREG_OFFSET_CAPTURE);

        $yearFrom = null;
        $yearTo = null;
        $subject = $cleanTitle;

        if ($yearMatch !== []) {
            $yearFrom = (int) substr($yearMatch[0][0], 0, 4);
            $yearToText = trim(substr($yearMatch[0][0], -4));
            $yearTo = ctype_digit($yearToText) ? (int) $yearToText : (int) date('Y');
            $subject = trim(substr($cleanTitle, 0, $yearMatch[0][1]));
        }

        if (str_contains(mb_strtolower($subject), ' para ')) {
            $parts = preg_split('/\s+para\s+/iu', $subject);
            $subject = trim((string) end($parts));
        }

        $subject = preg_replace('/^(radio|autoradio|pantalla|junsun|dasaita)\s+/iu', '', $subject) ?? $subject;

        if ($brand) {
            $subject = preg_replace('/^'.preg_quote($brand, '/').'\s+/iu', '', $subject) ?? $subject;
        }

        $subject = trim($subject, " \t\n\r\0\x0B-–|/");

        return [
            'model' => $subject !== '' ? $subject : null,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
        ];
    }
}
