<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
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

            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $missingItalianTitles = ConfiguratorProduct::query()
                ->where('category', 'screen')
                ->whereNull('title_it')
                ->count();

            if ($missingItalianTitles > 0) {
                $translationExitCode = Artisan::call('configurator:translate-titles', [
                    'locale' => 'it',
                    '--category' => 'screen',
                    '--no-interaction' => true,
                ]);

                if ($translationExitCode !== 0) {
                    Log::error('Traduzione titoli italiani fallita dalla dashboard.', [
                        'exit_code' => $translationExitCode,
                        'output' => Artisan::output(),
                    ]);

                    return back()->withErrors([
                        'database' => 'Database aggiornato, ma la traduzione non è terminata: '.trim(Artisan::output()),
                    ]);
                }
            }

            $this->clearApplicationCaches();

            $opcacheReset = function_exists('opcache_reset') && @opcache_reset();
            $translatedItalianTitles = $missingItalianTitles > 0
                ? ConfiguratorProduct::query()->where('category', 'screen')->whereNotNull('title_it')->count()
                : 0;
            $status = 'Database e cache Laravel aggiornati correttamente.';

            if ($missingItalianTitles > 0) {
                $status .= " Traduzioni italiane presenti: {$translatedItalianTitles}.";
            }

            if ($opcacheReset) {
                $status .= ' Anche la cache PHP OPcache è stata svuotata.';
            } else {
                $status .= ' OPcache non è gestibile dall’app: se continui a vedere la versione precedente, riavvia PHP dal pannello hosting.';
            }

            return back()->with('status', $status);
        } catch (Throwable $exception) {
            Log::error('Errore durante l’aggiornamento database dalla dashboard.', [
                'exception' => $exception,
            ]);

            return back()->withErrors([
                'database' => 'Impossibile aggiornare il database: '.$this->safeErrorMessage($exception),
            ]);
        } finally {
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    private function clearApplicationCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            $exitCode = Artisan::call($command, ['--no-interaction' => true]);

            if ($exitCode !== 0) {
                Log::warning('Pulizia cache Laravel non completata dalla dashboard.', [
                    'command' => $command,
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                ]);
            }
        }
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: 'errore sconosciuto';

        return mb_str($message)->limit(350)->toString();
    }
}
