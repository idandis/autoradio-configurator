<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AutogenesiWorker
{
    public function ensure(): void
    {
        if (!app()->environment('local')) return;
        try {
            Process::timeout(30)->run(['node', base_path('scripts/autogenesi/start-worker.cjs'), PHP_BINARY,
                base_path(), Storage::disk('local')->path('autogenesi/browser')])->throw();
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['worker' => 'Lista salvata, ma il processo automatico non si è avviato. Consulta storage/app/private/autogenesi/browser/worker.log e riprova.']);
        }
    }
}
