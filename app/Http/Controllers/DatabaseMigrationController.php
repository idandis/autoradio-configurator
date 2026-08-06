<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DatabaseMigrationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $lock = Cache::lock('admin:database-migration', 300);
        $lockAcquired = false;

        try {
            $lockAcquired = $lock->get();

            if (! $lockAcquired) {
                return back()->withErrors([
                    'database' => 'Un aggiornamento del database è già in corso. Riprova tra qualche minuto.',
                ]);
            }

            $exitCode = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0) {
                Log::error('Aggiornamento database fallito dalla dashboard.', [
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                ]);

                return back()->withErrors([
                    'database' => 'Aggiornamento non completato. Controlla i log del server o contatta il provider.',
                ]);
            }

            return back()->with('status', 'Database aggiornato correttamente. Tutte le migrazioni disponibili sono state applicate.');
        } catch (Throwable $exception) {
            Log::error('Errore durante l’aggiornamento database dalla dashboard.', [
                'exception' => $exception,
            ]);

            return back()->withErrors([
                'database' => 'Impossibile aggiornare il database. Controlla i log del server o contatta il provider.',
            ]);
        } finally {
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }
}
