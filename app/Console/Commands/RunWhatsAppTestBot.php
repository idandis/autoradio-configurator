<?php

namespace App\Console\Commands;

use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppSessionConnection;
use App\Services\WhatsAppTestBot;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunWhatsAppTestBot extends Command
{
    protected $signature = 'whatsapp:test-bot {--check} {--stop} {--reset} {--minutes=120} {--number=}';

    protected $description = 'Run the local WhatsApp trial for the configured test number only';

    public function handle(WhatsAppTestBot $bot): int
    {
        $dir = storage_path('app/private/waha');
        if (! app()->environment('local')) {
            $this->error('Comando disponibile solo in ambiente locale.');

            return self::FAILURE;
        }
        if ($this->option('stop')) {
            file_put_contents($dir.'/PAUSA', 'stop', LOCK_EX);
            $this->info('Arresto richiesto.');

            return self::SUCCESS;
        }
        if ($this->option('reset')) {
            app(WhatsAppAssistantSettings::class)->reset();
            $this->info('Memoria e veicolo del numero di prova azzerati.');

            return self::SUCCESS;
        }
        try {
            if ($this->option('number')) {
                $bot->useNumber(preg_replace('/\D/', '', $this->option('number')));
            }
            $bot->connect();
            $messages = $bot->messages();
        } catch (Throwable $e) {
            $this->error('Collegamento non pronto: '.class_basename($e));

            return self::FAILURE;
        }
        if ($this->option('check')) {
            $this->info('Sessione WORKING; chat di prova raggiungibile. Nessun messaggio inviato.');

            return self::SUCCESS;
        }
        $lock = fopen($dir.'/bot-'.hash('sha256', $bot->chatId()).'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Un bot di prova è già attivo.');

            return self::FAILURE;
        }
        if (is_file($dir.'/PAUSA')) {
            $this->error('Prova in pausa. Rimuovere il file PAUSA per riattivarla.');

            return self::FAILURE;
        }
        $since = time();
        $seen = array_fill_keys(array_column($messages, 'id'), true);
        $deadline = time() + max(1, min(120, (int) $this->option('minutes'))) * 60;
        $sent = 0;
        $settings = app(WhatsAppAssistantSettings::class);
        $currentChat = $bot->chatId();
        $active = true;
        $skipBacklog = false;
        $sessionKey = app(WhatsAppSessionConnection::class)->revisionKey();
        $sessionRevision = Cache::store('file')->get($sessionKey);
        $heartbeat = function (string $state) use (&$sent, $deadline, $bot): void {
            $key = $this->option('number') ? 'waha-test-runtime:'.hash('sha256', $bot->chatId()) : 'waha-test-runtime';
            Cache::store('file')->put($key, [
                'state' => $state, 'at' => time(), 'number' => explode('@', $bot->chatId())[0],
                'replies' => $sent, 'expires_at' => $deadline,
            ], now()->addMinutes(5));
        };
        $this->info('Prova attiva per il solo numero configurato, massimo 120 minuti o 50 risposte.');
        try {
            while (time() < $deadline && $sent < 50 && ! is_file($dir.'/PAUSA')) {
                try {
                    if (! $settings->get()['enabled']) {
                        $active = false;
                        $skipBacklog = true;
                        $heartbeat('paused');
                        usleep(3_000_000);

                        continue;
                    }
                    if (! $active || $currentChat !== $bot->chatId() || Cache::store('file')->get($sessionKey) !== $sessionRevision) {
                        $bot->connect();
                        if ($skipBacklog || $currentChat !== $bot->chatId()) {
                            $messages = $bot->messages();
                            $seen = array_fill_keys(array_column($messages, 'id'), true);
                            $since = time();
                        }
                        $currentChat = $bot->chatId();
                        $active = true;
                        $skipBacklog = false;
                        $sessionRevision = Cache::store('file')->get($sessionKey);
                    }
                    $heartbeat('active');
                    foreach ($bot->messages() as $message) {
                        if (! $bot->accepts($message, $since) || isset($seen[$message['id']])) {
                            continue;
                        }
                        $seen[$message['id']] = true;
                        $heartbeat('busy');
                        if ($bot->reply($message, $since)) {
                            $sent++;
                            $this->info(now()->toIso8601String().' Risposta inviata: '.$sent);
                            if ($sent >= 50) {
                                break;
                            }
                        }
                        $heartbeat('active');
                    }
                } catch (Throwable $e) {
                    $active = false;
                    $status = $e instanceof RequestException ? $e->response->status() : null;
                    $this->warn(now()->toIso8601String().' Errore: '.class_basename($e).($status ? ' HTTP '.$status : ''));
                    $heartbeat('connection_error');
                }
                usleep(3_000_000);
            }
        } finally {
            $heartbeat('stopped');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->info('Prova terminata.');

        return self::SUCCESS;
    }
}
