<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class WhatsAppHealthMonitor
{
    public function check(): string
    {
        if (! app()->environment('local') || ! config('waha.monitor_enabled') || ! config('waha.test_enabled')) return 'disabled';
        $cache = Cache::store('file');
        $key = 'waha-health:'.hash('sha256', config('waha.url').config('waha.session'));
        $lock = $cache->lock($key.':lock', 120);
        if (! $lock->get()) return 'busy';
        try {
            $settings = app(WhatsAppAssistantSettings::class);
            $runtime = $settings->runtime();
            if (! $settings->get()['enabled'] || $runtime['manual_stop']) {
                $cache->forget($key);

                return 'paused';
            }
            $connection = app(WhatsAppSessionConnection::class)->status();
            $issues = [];
            if (! $connection['available'] || $connection['status'] !== 'WORKING') $issues[] = 'Sessione WhatsApp scollegata o non disponibile: '.$connection['status'];
            if (! $runtime['running']) $issues[] = 'Bot non attivo: avviare nuovamente la prova locale (durata massima 120 minuti o 50 risposte).';
            elseif ($runtime['state'] === 'connection_error') $issues[] = 'Il bot è attivo ma non riesce a comunicare con WhatsApp.';
            if (! $issues) {
                $cache->forget($key);

                return 'healthy';
            }
            $incident = $cache->get($key, ['since' => now()->timestamp, 'sent' => false]);
            $cache->forever($key, $incident + ['issues' => $issues]);
            if ($incident['sent']) return 'already_notified';
            if (now()->timestamp - $incident['since'] < 120) return 'pending';
            $body = "L’assistente WhatsApp richiede attenzione.\n\n".implode("\n", $issues)
                ."\n\nApri la dashboard WhatsApp: ".url('/dashboard/whatsapp')
                ."\nPer ricollegare la sessione, apri WAHA sul Mac e scansiona l’eventuale QR dal telefono aziendale."
                ."\nNon verranno inviate altre email per questa interruzione. Il controllo riprende a notificare dopo il ripristino."
                ."\nQuesto controllo locale funziona solo con il Mac acceso e connesso.";
            $sent = Mail::mailer('smtp')->raw($body, fn ($m) => $m->to(config('mail.notification_to'))
                ->subject('AutoradioCanario: assistente WhatsApp non disponibile'));
            if ($sent === null) throw new RuntimeException('Email di monitoraggio non inviata.');
            $cache->forever($key, ['since' => $incident['since'], 'sent' => true, 'issues' => $issues]);

            return 'notified';
        } finally {
            $lock->release();
        }
    }
}
