<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppTechnicalAlert
{
    public function repeated(string $text, array $history): bool
    {
        $normalize = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($s))));
        $answer = $normalize($text);

        return $answer !== '' && collect($history)->where('role', 'assistant')->contains(fn ($m) => str_ends_with($normalize(preg_split('/\n\s*(?:Da verificare|Por verificar|To verify):/ui', $m['content'])[0]), $answer));
    }

    public function send(array $data): void
    {
        $key = 'waha-technical-alert:'.hash('sha256', json_encode($data));
        if (! Cache::store('file')->add($key, 'sending', now()->addHours(12))) {
            return;
        }
        $vehicle = $data['vehicle'];
        $body = 'Cliente WhatsApp: +'.$data['phone']."\n\nAuto: ".implode(' ', array_filter([$vehicle['brand'] ?? '', $vehicle['model'] ?? '', $vehicle['year'] ?? '']));
        $body .= "\n\nRadio / prodotti individuati:\n";
        foreach ($data['products'] as $p) {
            $body .= ($p['title'] ?? '')."\n".($p['url'] ?? '')."\n";
        }
        $body .= "\nIl cliente vuole sapere:\n".$data['question'];
        $body .= "\n\nPunti da verificare:\n".implode("\n", $data['unknown']);
        $label = trim(implode(' ', array_filter([$vehicle['brand'] ?? '', $vehicle['model'] ?? '']))) ?: 'cliente WhatsApp';
        try {
            $sent = Mail::mailer('smtp')->raw($body, fn ($m) => $m->to('pollong@gmail.com')->subject('WhatsApp: verifica tecnica '.$label));
            if ($sent === null) {
                throw new RuntimeException('Email tecnica non inviata.');
            }
            Cache::store('file')->put($key, 'smtp_accepted', now()->addHours(12));
            Cache::store('file')->forever('waha-mail-status', ['state' => 'smtp_accepted', 'at' => now()->timestamp]);
        } catch (\Throwable $e) {
            Cache::store('file')->forget($key);
            Cache::store('file')->forever('waha-mail-status', ['state' => 'failed', 'at' => now()->timestamp]);
            throw $e;
        }
    }
}
