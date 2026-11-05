<?php

namespace App\Services;

use RuntimeException;

class AutogenesiSource
{
    public static function url(string $url): string
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (strlen($url) > 2048 || ($p['scheme'] ?? '') !== 'https' || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/(?:^|\.)(?:local|localhost|internal|test|invalid)$/i', $host)
            || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            throw new RuntimeException('Usa un indirizzo HTTPS pubblico senza credenziali.');
        }
        if (in_array($host, ['es.aliexpress.com', 'www.aliexpress.com'], true)) return app(AutogenesiSearch::class)->sourceUrl($url);
        return 'https://'.$host.($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public static function id(string $url): string
    {
        $url = self::url($url);
        if (in_array(parse_url($url, PHP_URL_HOST), ['es.aliexpress.com', 'www.aliexpress.com'], true)) {
            return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        }
        return '9'.(string) hexdec(substr(hash('sha256', $url), 0, 15));
    }
}
