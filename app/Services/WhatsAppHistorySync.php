<?php

namespace App\Services;

use App\Models\WhatsAppChat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use LogicException;
use RuntimeException;

class WhatsAppHistorySync
{
    public function sync(WhatsAppChat $chat): array
    {
        if (! app(WhatsAppSessionConnection::class)->supported()) throw new LogicException('Recupero disponibile solo sul Mac con WAHA configurato.');
        $session = (string) config('waha.session');
        if ($chat->session !== $session || ! preg_match('/^[A-Za-z0-9_-]+$/', $session) || ! preg_match('/^[1-9]\d{7,14}$/', $chat->phone)) {
            throw new LogicException('Sessione o cliente non valido.');
        }
        $lock = Cache::store('file')->lock('waha-history-sync:'.hash('sha256', $session), 90);
        if (! $lock->get()) throw new LogicException('Un recupero storico è già in corso. Attendi prima di riprovare.');
        try {
            $api = Http::baseUrl(config('waha.url'))->withHeaders(['X-Api-Key' => config('waha.key')])->connectTimeout(3)->timeout(10);
            $state = $api->get('/api/sessions/'.rawurlencode($session))->throw()->json();
            if (($state['status'] ?? '') !== 'WORKING' || ($state['engine']['engine'] ?? '') !== 'WEBJS') {
                throw new LogicException('Il recupero richiede una sessione WEBJS collegata a WhatsApp.');
            }
            $ids = [$chat->phone.'@c.us'];
            $mapping = $api->get('/api/'.rawurlencode($session).'/lids/pn/'.$chat->phone);
            if (! in_array($mapping->status(), [404, 501], true)) {
                $m = $mapping->throw()->json();
                if (($m['pn'] ?? '') === $ids[0] && preg_match('/^\d+@lid$/', $m['lid'] ?? '')) array_unshift($ids, $m['lid']);
            }
            $result = Process::timeout(75)->input(file_get_contents(base_path('scripts/whatsapp-sync-history.cjs')))->run([
                config('waha.docker_binary'), 'exec', '-i', config('waha.container'), 'node', '-', $session, json_encode($ids, JSON_THROW_ON_ERROR),
            ])->throw();
            $data = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_int($data['before'] ?? null) || ! is_int($data['after'] ?? null)
                || ! is_bool($data['requested'] ?? null) || ! is_bool($data['limited'] ?? null)) throw new RuntimeException('Esito recupero non valido.');
            $message = $data['after'] > $data['before']
                ? 'Caricati altri '.($data['after'] - $data['before']).' messaggi. Ora puoi preparare il ricontatto.'
                : ($data['requested'] ? 'Storico richiesto al telefono. Tieni WhatsApp connesso e riprova il ricontatto tra qualche istante.'
                    : 'WAHA non ha recuperato altri messaggi. Lo storico disponibile potrebbe essere incompleto.');
            if ($data['limited']) $message .= ' Recupero parziale: puoi ripetere l’operazione.';

            return $data + ['message' => $message];
        } finally {
            $lock->release();
        }
    }
}
