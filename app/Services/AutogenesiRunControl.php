<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiRunControl
{
    public function state(): array
    {
        $path = Storage::disk('local')->path('autogenesi/browser/control.json');

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : ['run_id' => null, 'stopped' => false];
    }

    public function activate(): void
    {
        $state = $this->state();
        if ($state['stopped'] || ! $state['run_id']) {
            $this->write(false);
        }
    }

    public function stop(): void
    {
        $this->write(true);
    }

    public function assertActive(?string $runId): void
    {
        $state = $this->state();
        if ($state['stopped'] || $state['run_id'] !== $runId) {
            throw new RuntimeException('Ricerca interrotta dall’utente.');
        }
    }

    private function write(bool $stopped): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('autogenesi/browser');
        $path = $disk->path('autogenesi/browser/control.json');
        $tmp = $path.'.'.Str::uuid();
        file_put_contents($tmp, json_encode(['run_id' => (string) Str::uuid(), 'stopped' => $stopped]), LOCK_EX);
        chmod($tmp, 0600);
        if (! rename($tmp, $path)) {
            throw new RuntimeException('Controllo ricerche non aggiornato.');
        }
    }
}
