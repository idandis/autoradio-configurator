<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LogicException;

class WhatsAppServiceControl
{
    public function start(): void
    {
        $connection = app(WhatsAppSessionConnection::class);
        if (! $connection->supported() || ! config('waha.test_enabled')) {
            throw new LogicException('Avvio disponibile solo sul Mac con la prova WhatsApp abilitata.');
        }
        $lock = Cache::store('file')->lock('waha-service-start', 30);
        if (! $lock->get()) throw new LogicException('Avvio già in corso. Attendi e riprova.');
        try {
            app(WhatsAppTestBot::class)->connect();
            $settings = app(WhatsAppAssistantSettings::class);
            $runtime = $settings->runtime();
            $dir = storage_path('app/private/waha');
            File::ensureDirectoryExists($dir);
            File::delete($dir.'/PAUSA');
            $settings->save(['enabled' => true]);
            if ($runtime['running']) return;
            if (! Cache::store('file')->add('waha-service-starting', true, now()->addSeconds(30))) {
                throw new LogicException('Avvio già richiesto. Attendi 30 secondi prima di riprovare.');
            }
            $cmd = ['/usr/bin/python3', base_path('scripts/start-whatsapp-bot.py'), base_path(), PHP_BINDIR.'/php'];
            Process::path(base_path())->timeout(5)->run($cmd)->throw();
        } finally {
            $lock->release();
        }
    }
}
