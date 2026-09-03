<?php

namespace App\Services;

use Illuminate\Http\Request;

class VisitorBotDetector
{
    /** @return array{is_bot: bool, should_block: bool, reason: ?string} */
    public function analyze(Request $request): array
    {
        $userAgent = trim((string) $request->userAgent());

        if ($userAgent === '') {
            return $this->bot('User agent assente');
        }

        if (preg_match('/(?:googlebot|google-inspectiontool|bingbot|duckduckbot|yandexbot|baiduspider|applebot)/i', $userAgent)) {
            return $this->bot('Motore di ricerca', false);
        }

        if (preg_match('/(?:facebookexternalhit|whatsapp|telegrambot|discordbot)/i', $userAgent)) {
            return $this->bot('Anteprima social', false);
        }

        if (preg_match('/(?:bot|crawler|spider|slurp|headless|lighthouse|pagespeed|uptime|monitoring|healthcheck|curl|wget|python-requests|guzzlehttp|go-http-client|postmanruntime|semrush|ahrefs|mj12bot|bytespider|petalbot|claudebot|gptbot)/i', $userAgent)) {
            return $this->bot('Agente automatico dichiarato');
        }

        if (preg_match('/Android\s+([0-4])(?:\.|;|\s)/i', $userAgent)) {
            return $this->bot('Browser obsoleto o simulato');
        }

        $hasBrowserSignals = filled($request->header('Accept-Language'))
            || filled($request->header('Sec-Fetch-Site'))
            || filled($request->header('Sec-CH-UA'));

        if (! $hasBrowserSignals && blank($request->header('Referer'))) {
            return $this->bot('Richiesta senza segnali browser', false);
        }

        return ['is_bot' => false, 'should_block' => false, 'reason' => null];
    }

    /** @return array{is_bot: true, should_block: bool, reason: string} */
    private function bot(string $reason, bool $shouldBlock = true): array
    {
        return ['is_bot' => true, 'should_block' => $shouldBlock, 'reason' => $reason];
    }
}
