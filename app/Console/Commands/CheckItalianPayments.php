<?php

namespace App\Console\Commands;

use App\Services\ItalianCheckout;
use App\Services\ItalianPurchaseEmails;
use App\Services\StripePayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class CheckItalianPayments extends Command
{
    protected $signature = 'italian-payments:check {--stripe : Verify the account and webhook via read-only Stripe API calls}';

    protected $description = 'Check production payment readiness without charging or exposing secrets';

    public function handle(): int
    {
        $origin = rtrim((string) config('italian_checkout.origin'), '/');
        $checks = [
            'Ambiente production' => app()->environment('production'),
            'Debug disabilitato' => ! config('app.debug'),
            'Modalità live e chiavi coerenti' => StripePayments::live() && StripePayments::configured(),
            'Checkout abilitato' => ItalianCheckout::enabled(),
            'Origine HTTPS italiana' => parse_url($origin, PHP_URL_SCHEME) === 'https'
                && in_array(parse_url($origin, PHP_URL_HOST), config('italian_checkout.hosts'), true),
            'APP_URL corrisponde al dominio del checkout' => rtrim((string) config('app.url'), '/') === $origin,
            'Cookie di sessione sicuri' => (bool) config('session.secure'),
            'Segreto webhook presente' => str_starts_with((string) config('stripe.webhook_secret'), 'whsec_'),
            'Conferme email abilitate con trasporto reale' => ItalianPurchaseEmails::configured(),
            'Build frontend presente' => is_file(public_path('build/manifest.json')),
        ];
        foreach (['italian_orders', 'italian_order_items', 'italian_order_events', 'italian_order_payments', 'italian_order_refunds', 'italian_order_emails'] as $table) {
            $checks['Tabella '.$table] = Schema::hasTable($table);
        }
        $checks['Campi preventivo custom negli ordini'] = Schema::hasColumns('italian_orders', ['import_amount', 'checkout_locale', 'checkout_origin']);
        $checks['Campi importazione nelle righe ordine'] = Schema::hasColumns('italian_order_items', ['import_unit_amount', 'import_total_amount']);
        $checks['Link pagamento persistenti'] = Schema::hasTable('shared_configurations')
            && Schema::hasColumns('shared_configurations', ['fingerprint', 'checkout']);
        if ($this->option('stripe') && StripePayments::configured()) {
            $previousHttpClient = ApiRequestor::httpClient();
            try {
                // This check also runs from the dashboard on shared hosting.
                $httpClient = new CurlClient;
                $httpClient->setConnectTimeout(3)->setTimeout(8);
                ApiRequestor::setHttpClient($httpClient);
                $stripe = new StripeClient(['api_key' => config('stripe.secret'), 'max_network_retries' => 0]);
                $account = $stripe->accounts->retrieve();
                $checks['Account Stripe abilitato agli incassi'] = $account->charges_enabled;
                $checks['Account Stripe abilitato agli accrediti'] = $account->payouts_enabled;
                $endpointReady = false;
                foreach ($stripe->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
                    if ($endpoint->url === $origin.'/stripe/webhook' && $endpoint->livemode && $endpoint->status === 'enabled') {
                        $endpointReady = in_array('*', $endpoint->enabled_events, true)
                            || array_diff(['checkout.session.completed', 'checkout.session.expired',
                                'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
                                'payment_intent.payment_failed', 'payment_intent.succeeded',
                                'refund.created', 'refund.updated', 'refund.failed', 'charge.refunded'], $endpoint->enabled_events) === [];
                    }
                }
                $checks['Webhook live attivo con tutti gli eventi'] = $endpointReady;
            } catch (\Throwable $exception) {
                $checks['Connessione Stripe'] = false;
                $this->error('Verifica Stripe non riuscita: '.class_basename($exception));
            } finally {
                ApiRequestor::setHttpClient($previousHttpClient);
            }
        }
        foreach ($checks as $label => $passed) {
            $this->line(($passed ? 'OK' : 'NO').' · '.$label);
        }
        if ($checks['Tabella italian_order_emails']) {
            $this->line('Email in attesa: '.DB::table('italian_order_emails')->where('status', 'pending')->count());
        }
        $this->line('Verificare sul server che php artisan schedule:run venga eseguito ogni minuto.');
        $this->line('Questo controllo non verifica la consegna HTTP del webhook né la ricezione delle email.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
