<?php

namespace App\Services;

use App\Models\ItalianOrder;
use App\Models\ItalianOrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use UnexpectedValueException;

class StripePayments
{
    public function __construct(private readonly StripeGateway $gateway) {}

    public static function live(): bool
    {
        return config('stripe.mode') === 'live';
    }

    public static function configured(): bool
    {
        $mode = config('stripe.mode');
        if (! in_array($mode, ['test', 'live'], true)) {
            return false;
        }
        // Live charging is deliberately unavailable on a developer machine.
        if (self::live() ? ! app()->environment('production', 'testing') : ! app()->environment('local', 'testing')) {
            return false;
        }

        return str_starts_with((string) config('stripe.key'), 'pk_'.$mode.'_')
            && str_starts_with((string) config('stripe.secret'), 'sk_'.$mode.'_');
    }

    public static function supportsOrder(ItalianOrder $order): bool
    {
        return self::configured() && $order->is_test === ! self::live();
    }

    /** Reuse an open session; reserve a stable idempotency key before contacting Stripe. */
    public function session(ItalianOrder $order, string $returnUrl): ?array
    {
        $this->assertEnabled($order);
        if (self::live() && (parse_url($returnUrl, PHP_URL_SCHEME) !== 'https'
            || ! in_array(parse_url($returnUrl, PHP_URL_HOST), config('italian_checkout.hosts'), true))) {
            throw new LogicException('Invalid live return URL.');
        }
        if ($order->fresh()->fulfillment_status === 'cancelled') {
            throw new LogicException('Order cancelled.');
        }
        if (in_array($order->fresh()->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return null;
        }
        $previous = $order->payments()->latest('id')->first();
        if ($previous?->stripe_session_id) {
            $session = $this->synchronize($previous);
            if ($session['status'] !== 'expired') {
                return $session;
            }
        }

        $payment = DB::transaction(function () use ($order, $returnUrl) {
            $locked = ItalianOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->fulfillment_status === 'cancelled') {
                throw new LogicException('Order cancelled.');
            }
            if (in_array($locked->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
                return null;
            }
            $latest = $locked->payments()->latest('id')->first();
            if ($latest && $latest->status !== 'expired'
                && ($latest->stripe_session_id || $latest->expires_at > now()->timestamp)) {
                return $latest;
            }
            $payment = $locked->payments()->create([
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addHour()->timestamp,
                'request_payload' => [],
            ]);
            $locale = $locked->checkout_locale === 'es' ? 'es' : 'it';
            $metadata = ['italian_order_id' => (string) $locked->id, 'italian_payment_id' => (string) $payment->id];
            $payment->update(['request_payload' => [
                'mode' => 'payment',
                'ui_mode' => 'embedded_page',
                'locale' => $locale,
                'payment_method_types' => ['card'],
                'customer_email' => $locked->email,
                'client_reference_id' => (string) $locked->id,
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata],
                'return_url' => $returnUrl,
                'expires_at' => $payment->expires_at,
                // The full product breakdown is shown by our checkout. Stripe charges
                // exactly the immutable order total, including discounts and free shipping.
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $locked->total_amount,
                        'product_data' => [
                            'name' => ($locale === 'es' ? 'Pedido ' : 'Ordine ').$locked->number,
                            'description' => $locale === 'es'
                                ? 'Productos y costes de importación del presupuesto'
                                : 'Prodotti e costi di importazione del preventivo',
                        ],
                    ],
                ]],
            ]]);

            return $payment;
        });
        if (! $payment) {
            return null;
        }
        if ($payment->stripe_session_id) {
            return $this->synchronize($payment);
        }
        $session = $this->gateway->createSession($payment->request_payload, $payment->idempotency_key);
        $this->applySession($payment, $session);

        return $session;
    }

    public function synchronize(ItalianOrderPayment $payment, ?string $sessionId = null): array
    {
        $this->assertEnabled($payment->order);
        $id = $payment->stripe_session_id ?: $sessionId;
        if (! $id) {
            throw new LogicException('Missing Stripe session.');
        }
        $session = $this->gateway->retrieveSession($id);
        $this->applySession($payment, $session);

        return $session;
    }

    private function assertEnabled(ItalianOrder $order): void
    {
        if (! self::supportsOrder($order) || $order->currency !== 'EUR' || $order->total_amount <= 0) {
            throw new LogicException('Payment configuration does not match this EUR order.');
        }
    }

    private function applySession(ItalianOrderPayment $payment, array $session): void
    {
        DB::transaction(function () use ($payment, $session) {
            $order = ItalianOrder::query()->lockForUpdate()->findOrFail($payment->italian_order_id);
            $payment = ItalianOrderPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->assertEnabled($order);
            if (($session['livemode'] ?? null) !== self::live()
                || ($session['mode'] ?? null) !== 'payment'
                || ($session['currency'] ?? null) !== 'eur'
                || ($session['amount_total'] ?? null) !== $order->total_amount
                || ($session['client_reference_id'] ?? null) !== (string) $order->id
                || ($session['metadata']['italian_order_id'] ?? null) !== (string) $order->id
                || ($session['metadata']['italian_payment_id'] ?? null) !== (string) $payment->id
                || ! str_starts_with($session['id'] ?? '', (self::live() ? 'cs_live_' : 'cs_test_'))
                || ! in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)
                || ($payment->stripe_session_id && $payment->stripe_session_id !== $session['id'])) {
                throw new UnexpectedValueException('Stripe session does not match the order.');
            }
            $intent = $session['payment_intent'] ?? null;
            $payment->update([
                'stripe_session_id' => $session['id'],
                'stripe_payment_intent_id' => is_array($intent) ? ($intent['id'] ?? null) : $intent,
                'status' => $session['status'],
            ]);
            if (in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
                return;
            }
            $paid = $session['status'] === 'complete' && ($session['payment_status'] ?? null) === 'paid';
            // An expired or failed older attempt must not overwrite a newer attempt.
            if (! $paid && (int) $order->payments()->max('id') !== $payment->id) {
                return;
            }
            $failed = $session['status'] === 'expired'
                || (is_array($intent) && ($intent['status'] ?? null) === 'requires_payment_method' && ! empty($intent['last_payment_error']));
            $status = $paid ? 'paid' : ($failed ? 'failed' : 'pending');
            if ($order->payment_status === $status) {
                return;
            }
            $from = $order->payment_status;
            $order->payment_status = $status;
            if ($paid) {
                $order->paid_at = now();
            }
            $order->version++;
            $order->save();
            if ($paid) {
                app(ItalianPurchaseEmails::class)->record($order);
            }
            $order->events()->create([
                'kind' => 'payment', 'user_id' => null,
                'from_status' => $from, 'to_status' => $status,
                'changes' => ['stripe_session_id' => $session['id']],
            ]);
        });
    }
}
