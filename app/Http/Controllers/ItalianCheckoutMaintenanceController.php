<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ItalianCheckoutMaintenanceController extends Controller
{
    public const MIGRATIONS = [
        'database/migrations/2026_09_14_150000_create_italian_orders_tables.php',
        'database/migrations/2026_09_14_160000_add_checkout_fields_to_italian_orders.php',
        'database/migrations/2026_09_15_010000_create_italian_order_payments.php',
        'database/migrations/2026_09_15_110000_add_deleted_at_to_italian_orders.php',
        'database/migrations/2026_09_15_120000_create_italian_order_refunds.php',
        'database/migrations/2026_09_15_130000_create_italian_order_emails.php',
    ];

    public function __invoke(): RedirectResponse
    {
        // Share the lock with the existing general database update action.
        $lock = Cache::lock('admin:database-migration', 300);
        $acquired = false;

        try {
            $acquired = $lock->get();
            if (! $acquired) {
                return back()->withErrors(['italian_checkout' => 'Un aggiornamento è già in corso. Riprova tra qualche minuto.']);
            }

            foreach (self::MIGRATIONS as $path) {
                if (! is_file(base_path($path))) {
                    throw new RuntimeException('Checkout migration file missing.');
                }
            }

            if (Artisan::call('migrate', [
                '--path' => self::MIGRATIONS,
                '--force' => true,
                '--no-interaction' => true,
            ]) !== 0) {
                throw new RuntimeException('Checkout migration failed.');
            }

            foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
                if (Artisan::call($command, ['--no-interaction' => true]) !== 0) {
                    throw new RuntimeException('Checkout cache refresh failed: '.$command);
                }
            }

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            // Read readiness on the next request, after the new .env is loaded.
            return to_route('dashboard')
                ->with('italian_checkout_check_requested', true)
                ->with('status', 'Database del checkout Italia aggiornato. Verifica la configurazione nel riquadro dedicato.');
        } catch (Throwable $exception) {
            Log::error('Aggiornamento checkout Italia non completato.', ['exception' => $exception]);
            $detail = $exception instanceof QueryException
                ? ($exception->errorInfo[2] ?? 'Errore del database; dettagli nei log del server.')
                : $exception->getMessage();

            return back()->withErrors([
                'italian_checkout' => 'Aggiornamento checkout Italia non completato: '.Str::limit(preg_replace('/\s+/', ' ', $detail), 350),
            ]);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }
}
