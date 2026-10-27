<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

class WhatsAppSessionConnection
{
    public function supported(): bool
    {
        return app()->environment('local') && filled(config('waha.key'))
            && in_array(config('waha.url'), ['http://127.0.0.1:3000', 'http://localhost:3000'], true);
    }

    public function revisionKey(): string
    {
        return 'waha-session-revision:'.hash('sha256', config('waha.url').config('waha.session'));
    }

    private function client()
    {
        return Http::baseUrl(config('waha.url'))->withHeaders(['X-Api-Key' => config('waha.key')])->acceptJson()->connectTimeout(2);
    }

    public function status(): array
    {
        if (! $this->supported()) {
            return ['available' => false, 'status' => 'UNAVAILABLE'];
        }

        return Cache::store('file')->remember($this->revisionKey().':status', 3, function () {
            try {
                $state = $this->client()->timeout(3)->get('/api/sessions/'.rawurlencode(config('waha.session')))->throw()->json('status');

                return ['available' => true, 'status' => is_string($state) ? $state : 'UNKNOWN'];
            } catch (Throwable $e) {
                return ['available' => false, 'status' => 'UNREACHABLE'];
            }
        });
    }

    public function restart(): array
    {
        if (! $this->supported()) {
            throw new LogicException('Riavvio disponibile solo per WAHA locale configurato.');
        }
        $cache = Cache::store('file');
        if (! $cache->add($this->revisionKey().':cooldown', true, now()->addSeconds(30))) {
            throw new LogicException('Riavvio già richiesto. Attendi 30 secondi prima di riprovare.');
        }
        $cache->put($this->revisionKey(), bin2hex(random_bytes(12)), now()->addDay());
        $cache->forget($this->revisionKey().':status');
        $state = $this->client()->timeout(25)->post('/api/sessions/'.rawurlencode(config('waha.session')).'/restart')->throw()->json('status');

        return ['available' => true, 'status' => is_string($state) ? $state : 'STARTING'];
    }
}
